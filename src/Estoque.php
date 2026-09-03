<?php
declare(strict_types=1);

/**
 * Movimentação de estoque de suprimentos.
 * Invariante: tipos_suprimentos.estoque_atual == soma assinada de estoque_movimentos.
 */
final class Estoque
{
    /**
     * Registra uma movimentação e ajusta estoque_atual atomicamente, com lock de linha.
     * $tipo: 'entrada' (soma) | 'saida' (subtrai, nunca negativo) | 'ajuste' (delta com sinal).
     * Se já houver transação ativa, participa dela; senão abre a sua.
     * Idempotente por pedido: a UNIQUE(pedido_id,tipo_suprimento_id,tipo) impede débito dobrado.
     */
    public static function movimentar(
        PDO $pdo,
        int $tipoSuprimentoId,
        string $tipo,
        int $quantidade,
        ?string $motivo = null,
        ?int $pedidoId = null,
        ?int $usuarioId = null,
    ): void {
        $proprio = !$pdo->inTransaction();
        if ($proprio) $pdo->beginTransaction();

        try {
            $delta = match ($tipo) {
                'entrada' => abs($quantidade),
                'saida'   => -abs($quantidade),
                'ajuste'  => $quantidade,
                default   => 0,
            };

            $lock = $pdo->prepare("SELECT estoque_atual FROM tipos_suprimentos WHERE id = ? FOR UPDATE");
            $lock->execute([$tipoSuprimentoId]);
            if ($lock->fetchColumn() === false) {
                throw new RuntimeException("Suprimento #{$tipoSuprimentoId} inexistente.");
            }

            try {
                $pdo->prepare("
                    INSERT INTO estoque_movimentos
                        (tipo_suprimento_id, tipo, quantidade, motivo, pedido_id, usuario_id)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([$tipoSuprimentoId, $tipo, abs($quantidade), $motivo, $pedidoId, $usuarioId]);
            } catch (PDOException $e) {
                if ($pedidoId !== null && str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    if ($proprio) $pdo->commit();
                    Log::info('estoque_debito_duplicado_ignorado', ['pedido' => $pedidoId, 'sup' => $tipoSuprimentoId]);
                    return;
                }
                throw $e;
            }

            $pdo->prepare("
                UPDATE tipos_suprimentos SET estoque_atual = GREATEST(0, estoque_atual + ?) WHERE id = ?
            ")->execute([$delta, $tipoSuprimentoId]);

            if ($proprio) $pdo->commit();
        } catch (Throwable $e) {
            if ($proprio && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Debita todos os itens de catálogo de um pedido entregue. Deve rodar dentro de transação. */
    public static function debitarPedido(PDO $pdo, int $pedidoId, ?int $usuarioId = null): void
    {
        $itens = $pdo->prepare("
            SELECT tipo_suprimento_id, quantidade
            FROM pedidos_suprimentos_itens
            WHERE pedido_id = ? AND tipo_suprimento_id IS NOT NULL
        ");
        $itens->execute([$pedidoId]);

        $numero = $pdo->prepare("SELECT numero FROM pedidos_suprimentos WHERE id = ?");
        $numero->execute([$pedidoId]);
        $num = $numero->fetchColumn() ?: "#{$pedidoId}";

        foreach ($itens->fetchAll(PDO::FETCH_ASSOC) as $item) {
            self::movimentar(
                $pdo,
                (int) $item['tipo_suprimento_id'],
                'saida',
                (int) $item['quantidade'],
                "Entrega do pedido {$num}",
                $pedidoId,
                $usuarioId,
            );
        }
    }

    /** Saldo reconstruído a partir dos movimentos (para reconciliação / health check). */
    public static function saldoReconstruido(PDO $pdo, int $tipoSuprimentoId): int
    {
        $r = $pdo->prepare("
            SELECT COALESCE(SUM(CASE tipo
                WHEN 'entrada' THEN quantidade
                WHEN 'saida'   THEN -quantidade
                WHEN 'ajuste'  THEN quantidade END), 0)
            FROM estoque_movimentos WHERE tipo_suprimento_id = ?
        ");
        $r->execute([$tipoSuprimentoId]);
        return (int) $r->fetchColumn();
    }
}
