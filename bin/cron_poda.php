<?php
// ============================================================
// bin/cron_poda.php — Retenção de dados (P3-1)
// cPanel (1×/semana):  php /caminho/bin/cron_poda.php
// ============================================================
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/lib_cron.php';

cron_guard('poda');
$pdo = db();

try {
    // Snapshots de impressora: mantém 90 dias completos; acima disso, 1 por dia por impressora.
    $antigos = $pdo->exec("
        DELETE s FROM impressoras_snapshot s
        JOIN (
            SELECT id,
                   ROW_NUMBER() OVER (PARTITION BY impressora_id, DATE(coletado_em)
                                      ORDER BY coletado_em DESC) AS rn
            FROM impressoras_snapshot
            WHERE coletado_em < NOW() - INTERVAL 90 DAY
        ) d ON d.id = s.id AND d.rn > 1
    ");

    // audit_log: retenção de 2 anos
    $audit = $pdo->exec("DELETE FROM audit_log WHERE criado_em < NOW() - INTERVAL 2 YEAR");

    // email_queue: enviados/falhos com mais de 60 dias
    $mail = $pdo->exec("DELETE FROM email_queue
                        WHERE status IN ('enviado','falhou') AND criado_em < NOW() - INTERVAL 60 DAY");

    // rate_limits: janelas velhas
    $rl = $pdo->exec("DELETE FROM rate_limits WHERE janela_inicio < NOW() - INTERVAL 1 DAY");

    // hosts_rede offline há mais de 180 dias
    $hosts = $pdo->exec("DELETE FROM hosts_rede WHERE online = 0 AND ultimo_visto < NOW() - INTERVAL 180 DAY");

    $msg = "snapshots={$antigos} audit={$audit} email={$mail} rate={$rl} hosts={$hosts}";
    cron_finish('poda', true, $msg);
    echo "[" . date('c') . "] poda: {$msg}\n";
} catch (Throwable $e) {
    cron_finish('poda', false, $e->getMessage());
    throw $e;
}
