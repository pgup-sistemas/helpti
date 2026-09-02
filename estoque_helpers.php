<?php
/**
 * HelpTI — Movimentação de estoque de suprimentos
 * Sincroniza tipos_suprimentos.estoque_atual com o consumo real
 * (pedidos entregues) e com ajustes/entradas manuais, mantendo auditoria.
 */

function estoque_criar_tabela(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `estoque_movimentos` (
      `id` int NOT NULL AUTO_INCREMENT,
      `tipo_suprimento_id` int NOT NULL,
      `tipo` enum('entrada','saida','ajuste') NOT NULL,
      `quantidade` int NOT NULL COMMENT 'sempre positivo; o sinal é dado pela coluna tipo',
      `motivo` varchar(255) DEFAULT NULL,
      `pedido_id` int DEFAULT NULL COMMENT 'preenchido quando a saída vem de uma entrega automática',
      `usuario_id` int DEFAULT NULL,
      `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_tipo_sup` (`tipo_suprimento_id`),
      KEY `idx_pedido` (`pedido_id`),
      CONSTRAINT `fk_mov_tipo_suprimento` FOREIGN KEY (`tipo_suprimento_id`) REFERENCES `tipos_suprimentos` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Registra uma movimentação e atualiza o estoque_atual atomicamente.
 * $tipo: 'entrada' soma, 'saida' subtrai (nunca deixa negativo), 'ajuste' soma o delta informado (pode ser negativo).
 */
function estoque_movimentar(PDO $pdo, int $tipo_suprimento_id, string $tipo, int $quantidade, ?string $motivo = null, ?int $pedido_id = null, ?int $usuario_id = null): void {
    estoque_criar_tabela($pdo);

    $pdo->beginTransaction();
    try {
        $delta = match ($tipo) {
            'entrada' => abs($quantidade),
            'saida'   => -abs($quantidade),
            'ajuste'  => $quantidade, // pode ser positivo ou negativo
            default   => 0,
        };

        $pdo->prepare("
            UPDATE tipos_suprimentos
            SET estoque_atual = GREATEST(0, estoque_atual + ?)
            WHERE id = ?
        ")->execute([$delta, $tipo_suprimento_id]);

        $pdo->prepare("
            INSERT INTO estoque_movimentos (tipo_suprimento_id, tipo, quantidade, motivo, pedido_id, usuario_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$tipo_suprimento_id, $tipo, abs($quantidade), $motivo, $pedido_id, $usuario_id]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
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
