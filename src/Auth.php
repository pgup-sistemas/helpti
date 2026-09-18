<?php
declare(strict_types=1);

/**
 * Autenticação e autorização.
 * Perfis: 'tecnico' < 'gestora' < 'admin'.
 */
final class Auth
{
    /** Usuário da sessão (snapshot). Pode estar desatualizado — ver validated(). */
    public static function user(): ?array
    {
        Session::start();
        return $_SESSION['usuario'] ?? null;
    }

    /**
     * Revalida no banco que o usuário ainda existe, está ativo e mantém o perfil.
     * Cacheado por 60 s no ciclo de sessão. Retorna null (e derruba a sessão)
     * se o usuário foi desativado ou removido.
     */
    public static function validated(): ?array
    {
        $u = self::user();
        if (!$u) return null;

        $agora = time();
        if (($_SESSION['_perfil_checado'] ?? 0) > $agora - 60) return $u;

        try {
            $st = db()->prepare("SELECT perfil, ativo, nome FROM usuarios WHERE id = ?");
            $st->execute([$u['id']]);
            $row = $st->fetch();
            if (!$row || (int) $row['ativo'] !== 1) {
                Session::destroy();
                return null;
            }
            $_SESSION['usuario']['perfil'] = $row['perfil'];
            $_SESSION['usuario']['nome']   = $row['nome'];
            $_SESSION['_perfil_checado']   = $agora;
            return $_SESSION['usuario'];
        } catch (Throwable) {
            return $u; // banco oscilando: mantém sessão atual
        }
    }

    /**
     * Exige autenticação e (opcionalmente) um papel mínimo.
     * $papel: 'login' | 'gestora' | 'admin'. Redireciona se não autorizado.
     */
    public static function require(string $papel = 'login'): array
    {
        $u = self::validated();
        if (!$u) { self::redirect('login.php'); }

        $perfil = $u['perfil'] ?? '';
        $ok = match ($papel) {
            'login'   => true,
            'gestora' => $perfil !== 'tecnico',
            'admin'   => $perfil === 'admin',
            default   => false,
        };
        if (!$ok) { self::redirect('dashboard.php'); }

        return $u;
    }

    public static function is(string $papel): bool
    {
        $perfil = (self::user()['perfil'] ?? '');
        return match ($papel) {
            'login'   => $perfil !== '',
            'gestora' => in_array($perfil, ['gestora', 'admin'], true),
            'admin'   => $perfil === 'admin',
            default   => false,
        };
    }

    /** Cria a sessão autenticada após validação de credenciais (login.php). */
    public static function login(array $usuarioRow): void
    {
        Session::start();
        session_regenerate_id(true);
        $_SESSION['usuario'] = [
            'id'     => (int) $usuarioRow['id'],
            'nome'   => $usuarioRow['nome'],
            'email'  => $usuarioRow['email'],
            'perfil' => $usuarioRow['perfil'],
        ];
        $_SESSION['_iniciada']       = time();
        $_SESSION['_ultimo_ato']     = time();
        $_SESSION['_perfil_checado'] = time();
    }

    public static function logout(): void
    {
        Session::start();
        Session::destroy();
    }

    private static function redirect(string $to): never
    {
        header('Location: ' . $to);
        exit;
    }
}
