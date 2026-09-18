<?php
declare(strict_types=1);

/** Enfileiramento e templates de e-mail. Envio real: cron_email.php. */
final class Mailer
{
    public static function queue(string $destinatario, string $assunto, string $corpo): void
    {
        try {
            db()->prepare("INSERT INTO email_queue (destinatario, assunto, corpo) VALUES (?, ?, ?)")
                ->execute([$destinatario, $assunto, $corpo]);
        } catch (Throwable $e) {
            Log::warn('email_queue_fallback_sincrono', ['msg' => $e->getMessage()]);
            $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
                     . "From: " . APP_EMAIL_FROM . "\r\nReply-To: " . APP_EMAIL_REPLY . "\r\n";
            @mail($destinatario, $assunto, $corpo, $headers);
        }
    }

    /** $evento: 'aberto' | 'atribuido' | 'concluido'. */
    public static function notificarChamado(string $evento, array $chamado, ?string $emailDest): void
    {
        if (!$emailDest) return;

        $numero = $chamado['numero'];
        $setor  = $chamado['setor'];
        $descRaw = (string) ($chamado['descricao'] ?? '');
        $desc   = mb_substr($descRaw, 0, 120) . (mb_strlen($descRaw) > 120 ? '...' : '');

        $base = "<html><body style='font-family:Segoe UI,sans-serif;font-size:14px;color:#222'>"
              . "<div style='max-width:520px;margin:0 auto;border:1px solid #e5e9f2;border-radius:10px;overflow:hidden'>"
              . "<div style='background:#1D3557;padding:18px 24px'>"
              . "<span style='color:#fff;font-weight:700;font-size:16px'>" . APP_NOME . "</span>"
              . "<span style='color:#A8DADC;font-size:12px;margin-left:8px'>by " . APP_VENDOR . "</span>"
              . "</div><div style='padding:24px'>";
        $footer = "</div>"
                . "<div style='background:#f8f9fa;padding:12px 24px;font-size:11px;color:#999;border-top:1px solid #e5e9f2'>"
                . APP_NOME . " · " . APP_VENDOR . " · <a href='" . APP_URL . "' style='color:#1D3557'>" . APP_URL . "</a>"
                . "</div></div></body></html>";

        $btn = static fn(string $href, string $txt): string =>
            "<p style='margin-top:20px'><a href='{$href}' style='background:#1D3557;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600'>{$txt}</a></p>";

        if ($evento === 'aberto') {
            self::queue($emailDest,
                "[" . APP_NOME . "] Novo chamado {$numero} — aguardando atendimento",
                $base
                    . "<h3 style='color:#1D3557;margin-top:0'>Novo chamado aberto</h3>"
                    . "<table style='border-collapse:collapse;width:100%'>"
                    . "<tr><td style='padding:6px 0;font-weight:600;width:100px'>Nº</td><td style='padding:6px 0'>{$numero}</td></tr>"
                    . "<tr><td style='padding:6px 0;font-weight:600'>Setor</td><td style='padding:6px 0'>{$setor}</td></tr>"
                    . "<tr><td style='padding:6px 0;font-weight:600'>Descrição</td><td style='padding:6px 0'>{$desc}</td></tr>"
                    . "</table>"
                    . $btn(APP_URL . "/chamados.php", "Abrir painel TI")
                    . $footer);
            return;
        }

        if ($evento === 'atribuido') {
            $link = APP_URL . "/chamado.php?id=" . ($chamado['id'] ?? '');
            self::queue($emailDest,
                "[" . APP_NOME . "] Chamado {$numero} atribuído a você",
                $base
                    . "<h3 style='color:#1D3557;margin-top:0'>Chamado atribuído a você</h3>"
                    . "<p>Você foi designado como responsável pelo chamado <strong>{$numero}</strong> — setor <strong>{$setor}</strong>.</p>"
                    . "<p style='background:#f1faee;padding:12px;border-radius:6px;border-left:4px solid #1D3557'>{$desc}</p>"
                    . $btn($link, "Ver chamado")
                    . $footer);
            return;
        }

        if ($evento === 'concluido') {
            $link = !empty($chamado['avaliacao_token'])
                ? APP_URL . "/avaliar.php?t=" . rawurlencode((string) $chamado['avaliacao_token'])
                : APP_URL . "/avaliar.php";
            self::queue($emailDest,
                "[" . APP_NOME . "] Chamado {$numero} concluído — avalie o atendimento",
                $base
                    . "<h3 style='color:#1D3557;margin-top:0'>Chamado concluído ✓</h3>"
                    . "<p>O chamado <strong>{$numero}</strong> do setor <strong>{$setor}</strong> foi marcado como concluído.</p>"
                    . $btn($link, "Avaliar atendimento")
                    . $footer);
        }
    }
}
