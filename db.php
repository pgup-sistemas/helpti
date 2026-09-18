<?php
// Buffer de saída — previne erro "headers already sent" caso config.php tenha BOM
ob_start();

// Marca que o bootstrap rodou — libs internas checam isto para recusar acesso direto
define('HELPTI_BOOT', 1);

// Carregar configurações de produção
require_once __DIR__ . '/config.php';

// Camada de aplicação (autoloader das classes em src/)
require_once __DIR__ . '/src/bootstrap.php';

// Zonas de tempo
date_default_timezone_set('America/Sao_Paulo');

// ── Fachada: as funções globais abaixo delegam para src/. ──────────────────
// Código existente continua chamando logApp()/requireLogin()/etc.; código novo
// deve usar Log::, Auth::, Sla::, Estoque::, Mailer::, Seq:: diretamente.

function logApp(string $nivel, string $evento, array $ctx = []): void {
    Log::write($nivel, $evento, $ctx);
}

// ── Tratamento global de erros — nunca falha silenciosa (P2-1) ────────────
set_exception_handler(function (Throwable $e) {
    logApp('error', 'uncaught_exception', [
        'msg' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "ERRO: " . $e->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) http_response_code(500);
    $detalhe = defined('DEBUG_MODE') && DEBUG_MODE
        ? '<pre style="text-align:left;max-width:800px;margin:1rem auto;white-space:pre-wrap">'
          . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>'
        : '';
    echo '<!doctype html><meta charset="utf-8"><title>Erro</title>'
       . '<div style="font-family:system-ui,sans-serif;text-align:center;padding:3rem">'
       . '<h1 style="color:#1D3557">Ops — algo deu errado</h1>'
       . '<p>Nossa equipe foi notificada. Tente novamente em instantes.</p>'
       . $detalhe . '</div>';
    exit;
});

function session(): void { Session::start(); }

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            // Sempre EXCEPTION — falha de escrita nunca deve passar despercebida.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }
    return $pdo;
}

// IP real do cliente (respeita proxy do cPanel se configurado)
function clientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Token opaco de 16 hex — para acompanhamento público e avaliação
function tokenOpaco(): string { return bin2hex(random_bytes(8)); }

function rateLimit(string $bucket, int $max, int $janelaSeg): bool {
    return RateLimiter::allow($bucket, $max, $janelaSeg);
}

function usuario(): ?array            { return Auth::user(); }
function usuarioValidado(): ?array    { return Auth::validated(); }
function requireLogin(): void         { Auth::require('login'); }
function requireGestora(): void       { Auth::require('gestora'); }
function requireAdmin(): void         { Auth::require('admin'); }

function gerarNumero(): string            { return Seq::next('chamados', 'CHM'); }
function gerarNumeroSuprimento(): string  { return Seq::next('suprimentos', 'SUP'); }
function gerarNumeroSeq(string $seq, string $prefixo): string { return Seq::next($seq, $prefixo); }

function getSemana(string $data): string {
    $day = (int)date('j', strtotime($data));
    if ($day <= 7)  return 'Semana 01';
    if ($day <= 14) return 'Semana 02';
    if ($day <= 21) return 'Semana 03';
    if ($day <= 28) return 'Semana 04';
    return 'Semana 05';
}

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// ── CSRF ──────────────────────────────────────────────────────────
function csrfToken(): string {
    session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function csrfVerify(): void {
    session();
    $token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('Requisição inválida. Recarregue a página e tente novamente.');
    }
}

// ── RATE LIMITING (login brute-force) — persiste no banco, não em temp files ──
function isLoginBlocked(string $ip): bool {
    $row = db()->prepare("SELECT tentativas, ultima_tentativa FROM login_attempts WHERE ip = ?");
    $row->execute([$ip]);
    $r = $row->fetch();
    if (!$r) return false;
    if ((time() - strtotime($r['ultima_tentativa'])) > 300) return false;
    return (int)$r['tentativas'] >= 5;
}

function recordFailedLogin(string $ip): void {
    // ON DUPLICATE KEY: reseta o contador se a janela de 5 min expirou, senão incrementa
    db()->prepare("
        INSERT INTO login_attempts (ip, tentativas, ultima_tentativa)
        VALUES (?, 1, NOW())
        ON DUPLICATE KEY UPDATE
            tentativas       = IF(TIMESTAMPDIFF(SECOND, ultima_tentativa, NOW()) > 300, 1, tentativas + 1),
            ultima_tentativa = NOW()
    ")->execute([$ip]);
}

function clearFailedLogins(string $ip): void {
    db()->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
}

// ── BADGE HELPERS (centralizados) ─────────────────────────────────
function badgeStatus(string $s): string {
    $map = ['Aberto' => 'badge-aberto', 'Em Andamento' => 'badge-andamento', 'Pendente' => 'badge-pendente', 'Concluído' => 'badge-concluido'];
    return '<span class="badge ' . ($map[$s] ?? 'bg-secondary text-white') . '">' . h($s) . '</span>';
}

function badgeNivel(string $n): string {
    if (str_contains($n, 'Baixa')) return '<span class="badge badge-nivel-baixa">Baixa</span>';
    if (str_contains($n, 'Média')) return '<span class="badge badge-nivel-media">Média</span>';
    if (str_contains($n, 'Alta'))  return '<span class="badge badge-nivel-alta">Alta</span>';
    return '<span class="badge bg-light text-secondary">—</span>';
}

// ── Log de auditoria ──────────────────────────────────────────────
function auditLog(string $acao, string $tabela = '', int $registro_id = 0, string $detalhe = ''): void {
    $u = usuario();
    try {
        db()->prepare("INSERT INTO audit_log (usuario_id, acao, tabela, registro_id, detalhe, ip, criado_em)
            VALUES (?, ?, ?, ?, ?, ?, NOW())")
            ->execute([
                $u['id'] ?? null,
                $acao,
                $tabela ?: null,
                $registro_id ?: null,
                $detalhe ?: null,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
    } catch (Throwable) {
        // silencioso — falha no log nunca deve derrubar a aplicação
    }
}

function flash(string $msg, string $tipo = 'success'): void {
    session();
    $_SESSION['flash'] = ['msg' => $msg, 'tipo' => $tipo];
}

function getFlash(): ?array {
    session();
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

$SETORES = [];
try {
    $st_temp = db()->query("SELECT nome FROM setores WHERE ativo = 1 ORDER BY nome");
    if ($st_temp !== false) {
        $SETORES = $st_temp->fetchAll(PDO::FETCH_COLUMN);
    } else {
        throw new Exception("Tabela setores nao encontrada");
    }
} catch (Throwable $e) {
    $SETORES = [
        '01 - Médicos','02 - Rcp RM','03 - Rcp Térreo','04 - Rcp 1º Piso',
        '05 - Result Térreo','06 - Result 1º Piso','07 - Área Técnica','08 - Coleta',
        '09 - Vacinas','10 - Telefonia','11 - Geral','12 - Rcp 2º Piso',
        '13 - Densitometria','14 - Auditório','15 - Recep Mulher','16 - Comercial',
        '17 - Jatuarana','18 - Qualidade','19 - Tomografia','20 - SUS',
        '21 - Ilumina','22 - Faturamento','23 - RH','24 - Financeiro',
        '25 - Suprimento','26 - Administrativo','27 - USG','28 - RX','29 - MMG','30 - RM'
    ];
}

// ── SLA (delega para Sla:: — horário comercial) ───────────────────────────
function slaHoras(string $nivel): ?int { return Sla::horas($nivel); }
function slaDeadline(string $criado_em, int $horas): int { return Sla::deadline($criado_em, $horas); }
function slaBadge(string $nivel, string $criado_em, string $status): string {
    return Sla::badge($nivel, $criado_em, $status);
}

// ── E-mail (delega para Mailer::) ────────────────────────────────────────
function queueEmail(string $destinatario, string $assunto, string $corpo): void {
    Mailer::queue($destinatario, $assunto, $corpo);
}

function notificarChamado(string $evento, array $chamado, ?string $emailDest = null): void {
    Mailer::notificarChamado($evento, $chamado, $emailDest);
}
