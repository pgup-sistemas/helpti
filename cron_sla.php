<?php
// ============================================================
// cron_sla.php — Alerta de SLA vencido
// Configurar no cPanel para rodar a cada 30 minutos:
//   */30 * * * * php /caminho/cron_sla.php
// ============================================================

if (PHP_SAPI !== 'cli' && isset($_SERVER['HTTP_HOST'])) {
    http_response_code(403); exit('Acesso negado.');
}

require_once __DIR__ . '/db.php';

$pdo = db();

// Horas SLA por nível
$slaMap = [
    'Alta Complexidade'  => 2,
    'Média Complexidade' => 4,
    'Baixa Complexidade' => 8,
];

foreach ($slaMap as $nivel => $horas) {
    // Busca chamados vencidos ainda não notificados
    $st = $pdo->prepare("
        SELECT c.*, u.email AS email_resp, u.nome AS nome_resp,
               s.email AS email_sup
        FROM chamados c
        LEFT JOIN usuarios u ON u.id = c.responsavel_id
        LEFT JOIN (
            SELECT email FROM usuarios
            WHERE perfil IN ('gestora','admin') AND ativo = 1
            LIMIT 1
        ) s ON 1=1
        WHERE c.nivel    = ?
          AND c.status  IN ('Aberto','Em Andamento','Pendente')
          AND c.deleted_at IS NULL
          AND c.sla_alerta_enviado = 0
          AND c.criado_em <= NOW() - INTERVAL ? HOUR
    ");
    $st->execute([$nivel, $horas]);

    foreach ($st->fetchAll() as $c) {
        $numero  = $c['numero'];
        $setor   = $c['setor'];
        $atraso  = round((time() - strtotime($c['criado_em'])) / 3600, 1);

        $corpo = "<html><body style='font-family:Segoe UI,sans-serif;font-size:14px;color:#222'>"
               . "<div style='max-width:520px;margin:0 auto;border:1px solid #e5e9f2;border-radius:10px;overflow:hidden'>"
               . "<div style='background:#DC2626;padding:18px 24px'>"
               . "<span style='color:#fff;font-weight:700;font-size:16px'>" . APP_NOME . " · SLA Vencido</span>"
               . "</div>"
               . "<div style='padding:24px'>"
               . "<h3 style='color:#DC2626;margin-top:0'>⚠️ SLA vencido — ação necessária</h3>"
               . "<table style='border-collapse:collapse;width:100%'>"
               . "<tr><td style='padding:5px 0;font-weight:600;width:110px'>Chamado</td><td><strong>{$numero}</strong></td></tr>"
               . "<tr><td style='padding:5px 0;font-weight:600'>Setor</td><td>{$setor}</td></tr>"
               . "<tr><td style='padding:5px 0;font-weight:600'>Nível</td><td>{$nivel}</td></tr>"
               . "<tr><td style='padding:5px 0;font-weight:600'>SLA</td><td>{$horas}h — aberto há <strong>{$atraso}h</strong></td></tr>"
               . "<tr><td style='padding:5px 0;font-weight:600'>Responsável</td><td>" . ($c['nome_resp'] ?? 'Sem responsável') . "</td></tr>"
               . "</table>"
               . "<p style='margin-top:20px'>"
               . "<a href='" . APP_URL . "/chamado.php?id={$c['id']}' style='background:#DC2626;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600'>Atender agora</a>"
               . "</p>"
               . "</div></div></body></html>";

        $assunto = "[" . APP_NOME . "] ⚠️ SLA VENCIDO — Chamado {$numero} ({$nivel})";

        // Notifica o técnico responsável
        if ($c['email_resp']) {
            queueEmail($c['email_resp'], $assunto, $corpo);
        }

        // Notifica supervisor/gestora
        if ($c['email_sup'] && $c['email_sup'] !== $c['email_resp']) {
            queueEmail($c['email_sup'], $assunto, $corpo);
        }

        // Marca como notificado para não reenviar
        $pdo->prepare("UPDATE chamados SET sla_alerta_enviado = 1 WHERE id = ?")
            ->execute([$c['id']]);
    }
}
