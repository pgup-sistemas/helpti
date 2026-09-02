<?php
// Buffer de saída — previne erro "headers already sent" caso config.php tenha BOM
ob_start();

// Carregar configurações de produção
require_once __DIR__ . '/config.php';

// Iniciar sessão global
if (session_status() === PHP_SESSION_NONE) session_start();

// Zonas de tempo
date_default_timezone_set('America/Sao_Paulo');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            // Se DEBUG_MODE for false, erros silenciosos (logados pelo servidor, mas não exibidos na tela)
            PDO::ATTR_ERRMODE            => DEBUG_MODE ? PDO::ERRMODE_EXCEPTION : PDO::ERRMODE_SILENT,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }
    return $pdo;
}

function session(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
}

function usuario(): ?array {
    session();
    return $_SESSION['usuario'] ?? null;
}

function requireLogin(): void {
    if (!usuario()) { header('Location: login.php'); exit; }
}

function requireGestora(): void {
    requireLogin();
    $u = usuario();
    if ($u['perfil'] === 'tecnico') { header('Location: dashboard.php'); exit; }
}

function requireAdmin(): void {
    requireLogin();
    $u = usuario();
    if ($u['perfil'] !== 'admin') { header('Location: dashboard.php'); exit; }
}

function gerarNumero(): string {
    // LAST_INSERT_ID(expr) é atômico por conexão no MySQL — elimina race condition
    db()->exec("UPDATE sequences SET value = LAST_INSERT_ID(value + 1) WHERE name = 'chamados'");
    $seq = (int) db()->query("SELECT LAST_INSERT_ID()")->fetchColumn();
    return 'CHM-' . date('Y') . '-' . str_pad($seq, 5, '0', STR_PAD_LEFT);
}

function getSemana(string $data): string {
    $day = (int)date('j', strtotime($data));
    if ($day <= 7)  return 'Semana 01';
    if ($day <= 14) return 'Semana 02';
    if ($day <= 21) return 'Semana 03';
    if ($day <= 28) return 'Semana 04';
    return 'Semana 05';
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
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

// ── SLA por nível ──────────────────────────────────────────────────────────
function slaHoras(string $nivel): ?int {
    return match(true) {
        str_contains($nivel, 'Alta')  => 2,
        str_contains($nivel, 'Média') => 4,
        str_contains($nivel, 'Baixa') => 8,
        default => null,
    };
}

function slaBadge(string $nivel, string $criado_em, string $status): string {
    $horas = slaHoras($nivel);
    if (!$horas || $status === 'Concluído') return '';
    $limite = strtotime($criado_em) + $horas * 3600;
    $agora  = time();
    $diff   = $limite - $agora;
    if ($diff < 0) {
        $atraso = abs($diff);
        $label  = $atraso < 3600 ? floor($atraso/60).'min' : floor($atraso/3600).'h';
        return '<span class="badge badge-pendente ms-1" title="SLA vencido há '.$label.'"><i class="bi bi-exclamation-circle me-1"></i>+'.$label.'</span>';
    }
    if ($diff < 3600) {
        $label = floor($diff/60).'min';
        return '<span class="badge badge-andamento ms-1" title="SLA vence em '.$label.'"><i class="bi bi-clock me-1"></i>'.$label.'</span>';
    }
    $label = floor($diff/3600).'h '.floor(($diff%3600)/60).'min';
    return '<span class="badge bg-light text-muted border ms-1" title="Prazo SLA"><i class="bi bi-clock me-1"></i>'.$label.'</span>';
}

// ── Fila de e-mail — enfileira em vez de enviar sincronamente ──────────────
function queueEmail(string $destinatario, string $assunto, string $corpo): void {
    try {
        db()->prepare("
            INSERT INTO email_queue (destinatario, assunto, corpo) VALUES (?, ?, ?)
        ")->execute([$destinatario, $assunto, $corpo]);
    } catch (Throwable $e) {
        // fallback síncrono se a tabela ainda não existir (ex: ambiente de dev antigo)
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
                 . "From: " . APP_EMAIL_FROM . "\r\nReply-To: " . APP_EMAIL_REPLY . "\r\n";
        @mail($destinatario, $assunto, $corpo, $headers);
    }
}

// ── Notificação por e-mail ──────────────────────────────────────────────────
function notificarChamado(string $evento, array $chamado, ?string $emailDest = null): void {
    if (!$emailDest) return;

    $numero = $chamado['numero'];
    $setor  = $chamado['setor'];
    $desc   = mb_substr($chamado['descricao'], 0, 120) . (mb_strlen($chamado['descricao']) > 120 ? '...' : '');

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

    if ($evento === 'aberto') {
        queueEmail(
            $emailDest,
            "[" . APP_NOME . "] Novo chamado {$numero} — aguardando atendimento",
            $base
                . "<h3 style='color:#1D3557;margin-top:0'>Novo chamado aberto</h3>"
                . "<table style='border-collapse:collapse;width:100%'>"
                . "<tr><td style='padding:6px 0;font-weight:600;width:100px'>Nº</td><td style='padding:6px 0'>{$numero}</td></tr>"
                . "<tr><td style='padding:6px 0;font-weight:600'>Setor</td><td style='padding:6px 0'>{$setor}</td></tr>"
                . "<tr><td style='padding:6px 0;font-weight:600'>Descrição</td><td style='padding:6px 0'>{$desc}</td></tr>"
                . "</table>"
                . "<p style='margin-top:20px'><a href='" . APP_URL . "/chamados.php' style='background:#1D3557;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600'>Abrir painel TI</a></p>"
                . $footer
        );
    }

    if ($evento === 'atribuido') {
        $link = APP_URL . "/chamado.php?id=" . ($chamado['id'] ?? '');
        queueEmail(
            $emailDest,
            "[" . APP_NOME . "] Chamado {$numero} atribuído a você",
            $base
                . "<h3 style='color:#1D3557;margin-top:0'>Chamado atribuído a você</h3>"
                . "<p>Você foi designado como responsável pelo chamado <strong>{$numero}</strong> — setor <strong>{$setor}</strong>.</p>"
                . "<p style='background:#f1faee;padding:12px;border-radius:6px;border-left:4px solid #1D3557'>{$desc}</p>"
                . "<p><a href='{$link}' style='background:#1D3557;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600'>Ver chamado</a></p>"
                . $footer
        );
    }

    if ($evento === 'concluido') {
        $link = APP_URL . "/avaliar.php?id=" . ($chamado['id'] ?? '');
        queueEmail(
            $emailDest,
            "[" . APP_NOME . "] Chamado {$numero} concluído — avalie o atendimento",
            $base
                . "<h3 style='color:#1D3557;margin-top:0'>Chamado concluído ✓</h3>"
                . "<p>O chamado <strong>{$numero}</strong> do setor <strong>{$setor}</strong> foi marcado como concluído.</p>"
                . "<p><a href='{$link}' style='background:#1D3557;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600'>Avaliar atendimento</a></p>"
                . $footer
        );
    }
}
