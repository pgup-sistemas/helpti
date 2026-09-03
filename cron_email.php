<?php
// ============================================================
// cron_email.php — Processa a fila de e-mails pendentes
// cPanel (a cada 1 minuto):  php /caminho/para/cron_email.php
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/bin/lib_cron.php';

cron_guard('email');                     // CLI + lock exclusivo (P1-4 / P2-2)

$pdo   = db();
$limit = 20;
$enviados = 0; $falhas = 0;

$headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
         . "From: " . APP_EMAIL_FROM . "\r\nReply-To: " . APP_EMAIL_REPLY . "\r\n";

try {
    // 1) Recupera itens presos em 'enviando' há mais de 10 min (worker morto)
    $pdo->exec("UPDATE email_queue SET status='pendente', locked_at=NULL, lote=NULL
                WHERE status='enviando' AND locked_at < NOW() - INTERVAL 10 MINUTE");

    // 2) Reivindica um lote atomicamente marcando como 'enviando'
    $loteId = bin2hex(random_bytes(6)); // 12 chars
    $pdo->prepare("
        UPDATE email_queue
        SET status='enviando', locked_at=NOW(), lote=?
        WHERE status='pendente' AND tentativas < 3
        ORDER BY criado_em ASC
        LIMIT {$limit}
    ")->execute([$loteId]);

    $meus = $pdo->prepare("SELECT id, destinatario, assunto, corpo FROM email_queue
                           WHERE status='enviando' AND lote = ?");
    $meus->execute([$loteId]);

    foreach ($meus->fetchAll() as $email) {
        $ok = @mail($email['destinatario'], $email['assunto'], $email['corpo'], $headers);
        if ($ok) {
            $pdo->prepare("UPDATE email_queue SET status='enviado', enviado_em=NOW(), erro=NULL, locked_at=NULL, lote=NULL WHERE id=?")
                ->execute([$email['id']]);
            $enviados++;
        } else {
            $msg = error_get_last()['message'] ?? 'falha desconhecida';
            $pdo->prepare("
                UPDATE email_queue
                SET tentativas = tentativas + 1,
                    status = IF(tentativas + 1 >= 3, 'falhou', 'pendente'),
                    erro = ?, locked_at = NULL, lote = NULL
                WHERE id = ?
            ")->execute([mb_substr($msg, 0, 255), $email['id']]);
            $falhas++;
        }
    }

    // 3) Limpa enviados com mais de 30 dias
    $pdo->exec("DELETE FROM email_queue WHERE status='enviado' AND enviado_em < NOW() - INTERVAL 30 DAY");

    cron_finish('email', true, "enviados={$enviados} falhas={$falhas}");
    echo "[" . date('c') . "] email: enviados={$enviados} falhas={$falhas}\n";
} catch (Throwable $e) {
    cron_finish('email', false, $e->getMessage());
    throw $e;
}
