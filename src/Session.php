<?php
declare(strict_types=1);

/** Sessão endurecida: cookie seguro + expiração idle (30 min) e absoluta (12 h). */
final class Session
{
    private const IDLE_SECONDS = 1800;
    private const ABS_SECONDS  = 43200;

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) return;

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('HELPTI_SESS');
        session_start();

        if (isset($_SESSION['usuario'])) {
            $agora = time();
            $ini = $_SESSION['_iniciada']   ?? $agora;
            $act = $_SESSION['_ultimo_ato'] ?? $agora;
            if (($agora - $act) > self::IDLE_SECONDS || ($agora - $ini) > self::ABS_SECONDS) {
                self::destroy();
            } else {
                $_SESSION['_ultimo_ato'] = $agora;
                $_SESSION['_iniciada']   = $ini;
            }
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
