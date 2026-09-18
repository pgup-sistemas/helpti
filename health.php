<?php
// ============================================================
// health.php — Endpoint de monitoramento (JSON). Protegido por token.
//   GET /health.php?token=XXXX
// Configure HEALTH_TOKEN em config.local.php.
// ============================================================
require 'db.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!HEALTH_TOKEN || !hash_equals(HEALTH_TOKEN, (string)($_GET['token'] ?? ''))) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}

$out = ['ts' => date('c'), 'ok' => true, 'checks' => []];
function chk(array &$out, string $nome, bool $ok, $detalhe = null): void {
    $out['checks'][$nome] = ['ok' => $ok, 'detalhe' => $detalhe];
    if (!$ok) $out['ok'] = false;
}

// Banco
try {
    db()->query("SELECT 1");
    chk($out, 'db', true);
} catch (Throwable $e) {
    chk($out, 'db', false, $e->getMessage());
}

try {
    $pdo = db();

    // Fila de e-mail presa
    $presos = (int)$pdo->query("SELECT COUNT(*) FROM email_queue
        WHERE status='pendente' AND criado_em < NOW() - INTERVAL 15 MINUTE")->fetchColumn();
    chk($out, 'email_queue', $presos === 0, "pendentes_ha_15min={$presos}");

    // Jobs — algum não roda há muito tempo?
    $atrasos = [
        'email'     => 10 * 60,
        'sla'       => 60 * 60,
        'snmp'      => 6 * 3600,
        'scanner'   => 30 * 3600,
        'contratos' => 30 * 3600,
    ];
    $runs = $pdo->query("SELECT nome, terminado_em, ok FROM cron_runs")->fetchAll(PDO::FETCH_ASSOC);
    $byName = [];
    foreach ($runs as $r) $byName[$r['nome']] = $r;
    foreach ($atrasos as $nome => $limite) {
        $r = $byName[$nome] ?? null;
        if (!$r || !$r['terminado_em']) { chk($out, "cron_$nome", false, 'nunca executou'); continue; }
        $idade = time() - strtotime($r['terminado_em']);
        $ok = $idade <= $limite && (int)$r['ok'] === 1;
        chk($out, "cron_$nome", $ok, "ultima_ha={$idade}s ok={$r['ok']}");
    }

    // Espelho estoque_atual x movimentos (amostra)
    $div = $pdo->query("
        SELECT COUNT(*) FROM tipos_suprimentos t
        WHERE t.ativo=1 AND t.estoque_atual <> (
            SELECT COALESCE(SUM(CASE tipo WHEN 'entrada' THEN quantidade
                WHEN 'saida' THEN -quantidade WHEN 'ajuste' THEN quantidade END),0)
            FROM estoque_movimentos m WHERE m.tipo_suprimento_id = t.id)
    ")->fetchColumn();
    chk($out, 'estoque_consistente', (int)$div === 0, "divergentes={$div}");

    // Espaço em uploads/
    $free = @disk_free_space(__DIR__ . '/uploads');
    chk($out, 'disco_uploads', $free === false || $free > 200 * 1024 * 1024,
        $free !== false ? round($free / 1048576) . 'MB livres' : 'n/d');

} catch (Throwable $e) {
    chk($out, 'checks', false, $e->getMessage());
}

http_response_code($out['ok'] ? 200 : 503);
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
