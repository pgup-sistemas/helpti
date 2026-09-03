<?php
/**
 * HelpTI — Estoque de suprimentos (fachada).
 * Implementação em src/Estoque.php. Mantido para compatibilidade com o
 * código que chama estas funções globais.
 */

if (!defined('HELPTI_BOOT')) { http_response_code(403); exit('Acesso negado.'); }

/** @deprecated schema vem das migrations; no-op. */
function estoque_criar_tabela(PDO $pdo): void {}

function estoque_movimentar(PDO $pdo, int $tipo_suprimento_id, string $tipo, int $quantidade, ?string $motivo = null, ?int $pedido_id = null, ?int $usuario_id = null): void {
    Estoque::movimentar($pdo, $tipo_suprimento_id, $tipo, $quantidade, $motivo, $pedido_id, $usuario_id);
}

function estoque_debitar_pedido(PDO $pdo, int $pedido_id, ?int $usuario_id = null): void {
    Estoque::debitarPedido($pdo, $pedido_id, $usuario_id);
}

function estoque_saldo_reconstruido(PDO $pdo, int $tipo_suprimento_id): int {
    return Estoque::saldoReconstruido($pdo, $tipo_suprimento_id);
}
