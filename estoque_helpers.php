<?php
/**
 * HelpTI — Movimentação de estoque de suprimentos
 * Sincroniza tipos_suprimentos.estoque_atual com o consumo real
 * (pedidos entregues) e com ajustes/entradas manuais, mantendo auditoria.
 *
 * Schema em database/migrations/0002_consolidacao_runtime.sql (não cria mais em runtime).
 */

if (!defined('HELPTI_BOOT')) { http_response_code(403); exit('Acesso negado.'); }

function estoque_criar_tabela(PDO $pdo): void {
    // Mantido por compatibilidade — schema agora vem das migrations. No-op.
}

/**
 * Registra uma movimentação e atualiza o estoque_atual atomicamente,
 * com lock de linha (SELECT ... FOR UPDATE) para evitar corrida. (P1-2)
 * $tipo: 'entrada' soma, 'saida' subtrai (nunca deixa negativo), 'ajuste' soma o delta (pode ser negativo).
 * Deve ser chamada JÁ dentro de uma transação quando o chamador precisa de atomicidade
 * com outras operações — a função só abre transação própria se não houver uma ativa.
 */
function estoque_movimentar(PDO $pdo, int $tipo_suprimento_id, string $tipo, int $quantidade, ?string $motivo = null, ?int $pedido_id = null, ?int $usuario_id = null): void {
    $proprio = !$pdo->inTransaction();
    if ($proprio) $pdo->beginTransaction();
    try {
        $delta = match ($tipo) {
            'entrada' => abs($quantidade),
            'saida'   => -abs($quantidade),
            'ajuste'  => $quantidade,
            default   => 0,
        };

        // Lock da linha do suprimento
        $lock = $pdo->prepare("SELECT estoque_atual FROM tipos_suprimentos WHERE id = ? FOR UPDATE");
        $lock->execute([$tipo_suprimento_id]);
        if ($lock->fetchColumn() === false) {
            throw new RuntimeException("Suprimento #{$tipo_suprimento_id} inexistente.");
        }

        // Idempotência: a UNIQUE (pedido_id,tipo_suprimento_id,tipo) faz o INSERT falhar
        // se o mesmo pedido já debitou este item. Fazemos o INSERT primeiro.
        $ins = $pdo->prepare("
            INSERT INTO estoque_movimentos (tipo_suprimento_id, tipo, quantidade, motivo, pedido_id, usuario_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        try {
            $ins->execute([$tipo_suprimento_id, $tipo, abs($quantidade), $motivo, $pedido_id, $usuario_id]);
        } catch (PDOException $e) {
            if ($pedido_id !== null && str_contains(strtolower($e->getMessage()), 'duplicate')) {
                // Já foi debitado antes para este pedido — não repete.
                if ($proprio) $pdo->commit();
                if (function_exists('logApp')) logApp('info', 'estoque_debito_duplicado_ignorado', ['pedido' => $pedido_id, 'sup' => $tipo_suprimento_id]);
                return;
            }
            throw $e;
        }

        $pdo->prepare("
            UPDATE tipos_suprimentos
            SET estoque_atual = GREATEST(0, estoque_atual + ?)
            WHERE id = ?
        ")->execute([$delta, $tipo_suprimento_id]);

        if ($proprio) $pdo->commit();
    } catch (Throwable $e) {
        if ($proprio && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Reconstrói o saldo esperado a partir dos movimentos (para auditoria/reconciliação).
 * saldo = entradas - saidas + ajustes
 */
function estoque_saldo_reconstruido(PDO $pdo, int $tipo_suprimento_id): int {
    $r = $pdo->prepare("
        SELECT COALESCE(SUM(CASE tipo
            WHEN 'entrada' THEN quantidade
            WHEN 'saida'   THEN -quantidade
            WHEN 'ajuste'  THEN quantidade END), 0)
        FROM estoque_movimentos WHERE tipo_suprimento_id = ?
    ");
    $r->execute([$tipo_suprimento_id]);
    return (int)$r->fetchColumn();
}

/**
 * Debita o estoque de todos os itens de um pedido (chamado quando o pedido é marcado como Entregue).
 * Itens com tipo_suprimento_id NULL (opção "Outros / descrição livre") são ignorados —
 * não têm um insumo de catálogo vinculado para debitar.
 */
function estoque_debitar_pedido(PDO $pdo, int $pedido_id, ?int $usuario_id = null): void {
    estoque_criar_tabela($pdo);

    $itens = $pdo->prepare("SELECT tipo_suprimento_id, quantidade FROM pedidos_suprimentos_itens WHERE pedido_id = ? AND tipo_suprimento_id IS NOT NULL");
    $itens->execute([$pedido_id]);

    $numero = $pdo->prepare("SELECT numero FROM pedidos_suprimentos WHERE id = ?");
    $numero->execute([$pedido_id]);
    $num = $numero->fetchColumn() ?: "#{$pedido_id}";

    foreach ($itens->fetchAll(PDO::FETCH_ASSOC) as $item) {
        estoque_movimentar(
            $pdo,
            (int)$item['tipo_suprimento_id'],
            'saida',
            (int)$item['quantidade'],
            "Entrega do pedido {$num}",
            $pedido_id,
            $usuario_id
        );
    }
}
