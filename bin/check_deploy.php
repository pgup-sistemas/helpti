<?php
/**
 * bin/check_deploy.php — Validação pré-deploy do HelpTI
 *
 * Uso:
 *   php bin/check_deploy.php
 *
 * Verifica configuração, banco, migrations, diretórios e permissões.
 * Retorna exit code 0 se tudo ok, 1 se houver bloqueadores.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

define('HELPTI_BOOT', 1);
require __DIR__ . '/../config.php';

$erros   = [];
$avisos  = [];
$passes  = [];
$infos   = [];

// ── PHP Version ────────────────────────────────────────────────────────────
if (PHP_MAJOR_VERSION < 8 || (PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION < 1)) {
    $erros[] = "PHP " . PHP_VERSION . " detectado — necessário PHP 8.1+";
} else {
    $passes[] = "PHP " . PHP_VERSION;
}

// ── config.local.php ───────────────────────────────────────────────────────
if (!file_exists(__DIR__ . '/../config.local.php')) {
    $erros[] = "config.local.php não encontrado — copiar de config.local.php.example e preencher";
} else {
    $passes[] = "config.local.php encontrado";
}

// ── DEBUG_MODE ─────────────────────────────────────────────────────────────
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    $erros[] = "DEBUG_MODE está true — definir false em config.local.php antes do go-live";
} else {
    $passes[] = "DEBUG_MODE desligado";
}

// ── APP_URL ────────────────────────────────────────────────────────────────
if (str_contains(APP_URL, 'localhost') || str_contains(APP_URL, '127.0.0.1')) {
    $erros[] = "APP_URL aponta para localhost: '" . APP_URL . "' — trocar para o domínio de produção";
} elseif (!str_starts_with(APP_URL, 'https://')) {
    $avisos[] = "APP_URL não usa HTTPS: '" . APP_URL . "' — certificado SSL necessário para HSTS";
} else {
    $passes[] = "APP_URL: " . APP_URL;
}

// ── Credenciais de banco ────────────────────────────────────────────────────
if (DB_USER === 'root') {
    $erros[] = "DB_USER é 'root' — criar usuário MySQL dedicado com permissões mínimas";
} else {
    $passes[] = "DB_USER: " . DB_USER . " (não é root)";
}
if (DB_PASS === '') {
    $erros[] = "DB_PASS está vazia — definir senha forte";
}

// ── Conexão com banco ───────────────────────────────────────────────────────
$pdo = null;
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $ver = $pdo->query("SELECT VERSION()")->fetchColumn();
    $passes[] = "Banco: conectado — MySQL/MariaDB $ver";
} catch (Throwable $e) {
    $erros[] = "Banco: falha na conexão — " . $e->getMessage();
}

// ── Migrations ─────────────────────────────────────────────────────────────
if ($pdo) {
    try {
        $aplicadas = (int)$pdo->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();
        $arquivos  = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
        $total     = count($arquivos);
        if ($aplicadas < $total) {
            $pendentes = $total - $aplicadas;
            $erros[] = "Migrations: $pendentes pendentes ($aplicadas/$total aplicadas) — rodar: php bin/migrate.php";
        } else {
            $passes[] = "Migrations: todas $total aplicadas";
        }
    } catch (Throwable $e) {
        $erros[] = "schema_migrations não existe — banco não foi inicializado. Rodar: php bin/migrate.php";
    }
}

// ── HEALTH_TOKEN ───────────────────────────────────────────────────────────
if (!defined('HEALTH_TOKEN') || !HEALTH_TOKEN) {
    $avisos[] = "HEALTH_TOKEN não definido — health.php retornará 404 (sem monitoramento)";
} else {
    $passes[] = "HEALTH_TOKEN configurado";
}

// ── E-mail ─────────────────────────────────────────────────────────────────
if (str_contains(APP_EMAIL_FROM, 'localhost') || str_contains(APP_EMAIL_FROM, '.local')) {
    $erros[] = "APP_EMAIL_FROM usa domínio local: '" . APP_EMAIL_FROM . "' — trocar para e-mail real da hospedagem";
}

// ── Diretório uploads/ ─────────────────────────────────────────────────────
$uploadsDir = __DIR__ . '/../uploads';
if (!is_dir($uploadsDir)) {
    $erros[] = "Diretório uploads/ não existe — criar: mkdir -p uploads/contratos && chmod 755 uploads";
} elseif (!is_writable($uploadsDir)) {
    $erros[] = "uploads/ não tem permissão de escrita — ajustar: chmod 755 uploads";
} else {
    $passes[] = "uploads/: existe e gravável";
}

$contratosDir = $uploadsDir . '/contratos';
if (!is_dir($contratosDir)) {
    $erros[] = "uploads/contratos/ não existe — criar: mkdir -p uploads/contratos";
}

// ── Diretório logs/ ────────────────────────────────────────────────────────
$logsDir = __DIR__ . '/../logs';
if (!is_dir($logsDir)) {
    if (@mkdir($logsDir, 0770, true)) {
        $passes[] = "logs/: criado automaticamente";
    } else {
        $avisos[] = "logs/ não existe e não pôde ser criado — verificar permissões (escrita necessária para Log::write)";
    }
} elseif (!is_writable($logsDir)) {
    $avisos[] = "logs/ não tem permissão de escrita — ajustar: chmod 770 logs";
} else {
    $passes[] = "logs/: existe e gravável";
}

// ── Extensões PHP requeridas ────────────────────────────────────────────────
foreach (['pdo', 'pdo_mysql', 'mbstring', 'json', 'fileinfo', 'openssl'] as $ext) {
    if (!extension_loaded($ext)) {
        $erros[] = "Extensão PHP faltando: $ext";
    }
}
$passes[] = "Extensões PHP: pdo_mysql, mbstring, json, fileinfo, openssl — todas OK";

// ── GEMINI_API_KEY (opcional) ───────────────────────────────────────────────
if (!defined('GEMINI_API_KEY') || !GEMINI_API_KEY) {
    $infos[] = "GEMINI_API_KEY não definida — botão 'Classificar com IA' ficará desabilitado (opcional)";
}

// ── Output ─────────────────────────────────────────────────────────────────
$linha = str_repeat('─', 56);
echo "\n$linha\n";
echo "  HelpTI — Verificação Pré-Deploy\n";
echo "  PHP " . PHP_VERSION . " | " . date('c') . "\n";
echo "$linha\n\n";

if ($passes) {
    foreach ($passes as $p) echo "  ✅  $p\n";
    echo "\n";
}
if ($infos) {
    foreach ($infos as $i) echo "  ℹ️   $i\n";
    echo "\n";
}
if ($avisos) {
    echo "  ─── Atenção ───────────────────────────────────────\n";
    foreach ($avisos as $a) echo "  ⚠️   $a\n";
    echo "\n";
}
if ($erros) {
    echo "  ─── Bloqueadores ──────────────────────────────────\n";
    foreach ($erros as $e) echo "  ❌  $e\n";
    echo "\n";
    echo "$linha\n";
    echo "  Deploy NÃO recomendado — corrija os bloqueadores.\n";
    echo "$linha\n\n";
    exit(1);
}

echo "$linha\n";
echo "  ✅  Tudo OK — sistema pronto para deploy!\n";
echo "$linha\n\n";
exit(0);
