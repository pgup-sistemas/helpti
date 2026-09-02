<?php
// ============================================================
// cron_email.php — Processa a fila de e-mails pendentes
// Configurar no cPanel da Locaweb para rodar a cada 1 minuto:
//   php /caminho/para/cron_email.php
// ============================================================

// Bloqueia execução via browser
if (PHP_SAPI !== 'cli' && (!isset($_SERVER['HTTP_HOST']) === false)) {
    http_response_code(403);
    exit('Acesso negado.');
}

require_once __DIR__ . '/db.php';

$pdo   = db();
$limit = 20; // máximo de e-mails por execução

$pendentes = $pdo->prepare("
    SELECT id, destinatario, assunto, corpo
    FROM email_queue
    WHERE enviado_em IS NULL
      AND tentativas < 3
    ORDER BY criado_em ASC
    LIMIT ?
");
$pendentes->bindValue(1, $limit, PDO::PARAM_INT);
$pendentes->execute();

$headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
         . "From: " . APP_EMAIL_FROM . "\r\nReply-To: " . APP_EMAIL_REPLY . "\r\n";

foreach ($pendentes->fetchAll() as $email) {
    $ok = @mail($email['destinatario'], $email['assunto'], $email['corpo'], $headers);

    if ($ok) {
        $pdo->prepare("UPDATE email_queue SET enviado_em = NOW() WHERE id = ?")
            ->execute([$email['id']]);
    } else {
        $pdo->prepare("
            UPDATE email_queue
            SET tentativas = tentativas + 1,
                erro = ?
            WHERE id = ?
        ")->execute([error_get_last()['message'] ?? 'falha desconhecida', $email['id']]);
    }
}

// Limpa enviados com mais de 30 dias
$pdo->exec("DELETE FROM email_queue WHERE enviado_em < NOW() - INTERVAL 30 DAY");
