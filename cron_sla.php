<?php
// ============================================================
// cron_sla.php — Alerta de SLA vencido (horário comercial)
// cPanel (a cada 30 min):  php /caminho/cron_sla.php
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/bin/lib_cron.php';

cron_guard('sla');

$pdo = db();
$slaMap = [
    'Alta Complexidade'  => 2,
    'Média Complexidade' => 4,
    'Baixa Complexidade' => 8,
];
$enviados = 0;

try {
    // supervisor/gestora (um) para cópia
    $emailSup = $pdo->query("SELECT email FROM usuarios
                             WHERE perfil IN ('gestora','admin') AND ativo = 1 AND email <> ''
                             ORDER BY perfil='admin' DESC LIMIT 1")->fetchColumn() ?: null;

    $cand = $pdo->prepare("
        SELECT c.id, c.numero, c.setor, c.nivel, c.criado_em, c.responsavel_id,
               u.email AS email_resp, u.nome AS nome_resp
        FROM chamados c
        LEFT JOIN usuarios u ON u.id = c.responsavel_id
        WHERE c.nivel = ?
          AND c.status IN ('Aberto','Em Andamento','Pendente')
          AND c.deleted_at IS NULL
          AND c.sla_alerta_enviado = 0
    ");

    foreach ($slaMap as $nivel => $horas) {
        $cand->execute([$nivel]);
        foreach ($cand->fetchAll() as $c) {
            $deadline = slaDeadline($c['criado_em'], $horas);   // horário comercial (P2-5)
            if (time() < $deadline) continue;                    // ainda dentro do prazo

            // Marca ANTES de enfileirar — se duas execuções correrem, só uma passa daqui
            $mark = $pdo->prepare("UPDATE chamados SET sla_alerta_enviado = 1
                                   WHERE id = ? AND sla_alerta_enviado = 0");
            $mark->execute([$c['id']]);
            if ($mark->rowCount() !== 1) continue;                // outra execução já pegou

            $atrasoH = round((time() - $deadline) / 3600, 1);
            $assunto = "[" . APP_NOME . "] ⚠️ SLA VENCIDO — Chamado {$c['numero']} ({$nivel})";
            $corpo = "<html><body style='font-family:Segoe UI,sans-serif;font-size:14px;color:#222'>"
                . "<div style='max-width:520px;margin:0 auto;border:1px solid #e5e9f2;border-radius:10px;overflow:hidden'>"
                . "<div style='background:#DC2626;padding:18px 24px'><span style='color:#fff;font-weight:700;font-size:16px'>"
                . APP_NOME . " · SLA Vencido</span></div><div style='padding:24px'>"
                . "<h3 style='color:#DC2626;margin-top:0'>⚠️ SLA vencido — ação necessária</h3>"
                . "<table style='border-collapse:collapse;width:100%'>"
                . "<tr><td style='padding:5px 0;font-weight:600;width:120px'>Chamado</td><td><strong>" . h($c['numero']) . "</strong></td></tr>"
                . "<tr><td style='padding:5px 0;font-weight:600'>Setor</td><td>" . h($c['setor']) . "</td></tr>"
                . "<tr><td style='padding:5px 0;font-weight:600'>Nível</td><td>" . h($nivel) . "</td></tr>"
                . "<tr><td style='padding:5px 0;font-weight:600'>SLA</td><td>{$horas}h úteis — vencido há <strong>{$atrasoH}h</strong></td></tr>"
                . "<tr><td style='padding:5px 0;font-weight:600'>Responsável</td><td>" . h($c['nome_resp'] ?? 'Sem responsável') . "</td></tr>"
                . "</table>"
                . "<p style='margin-top:20px'><a href='" . APP_URL . "/chamado.php?id={$c['id']}' style='background:#DC2626;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600'>Atender agora</a></p>"
                . "</div></div></body></html>";

            if (!empty($c['email_resp'])) { queueEmail($c['email_resp'], $assunto, $corpo); $enviados++; }
            if ($emailSup && $emailSup !== ($c['email_resp'] ?? null)) { queueEmail($emailSup, $assunto, $corpo); $enviados++; }
        }
    }

    cron_finish('sla', true, "alertas_enfileirados={$enviados}");
    echo "[" . date('c') . "] sla: {$enviados} alerta(s) enfileirado(s)\n";
} catch (Throwable $e) {
    cron_finish('sla', false, $e->getMessage());
    throw $e;
}
