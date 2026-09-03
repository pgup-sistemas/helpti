<?php
// ============================================================
// CONFIGURAÇÕES GERAIS — valores padrão / estrutura
// Segredos ficam em config.local.php (gitignored)
// ============================================================

$_cfg = file_exists(__DIR__ . '/config.local.php')
    ? (require __DIR__ . '/config.local.php')
    : [];

define('APP_NOME',        'HelpTI');
define('APP_VENDOR',      'PageUp Sistemas');
define('CLINICA_NOME',    APP_NOME);

define('DB_HOST',         $_cfg['DB_HOST']         ?? 'localhost');
define('DB_NAME',         $_cfg['DB_NAME']         ?? '');
define('DB_USER',         $_cfg['DB_USER']         ?? '');
define('DB_PASS',         $_cfg['DB_PASS']         ?? '');

define('APP_URL',         $_cfg['APP_URL']         ?? 'https://helpti.pageup.net.br');
define('APP_EMAIL_FROM',  $_cfg['APP_EMAIL_FROM']  ?? 'HelpTI <noreply@pageup.net.br>');
define('APP_EMAIL_REPLY', $_cfg['APP_EMAIL_REPLY'] ?? 'noreply@pageup.net.br');

define('DEBUG_MODE',      $_cfg['DEBUG_MODE']      ?? false);
define('GEMINI_API_KEY',  $_cfg['GEMINI_API_KEY']  ?? '');

// Token do endpoint health.php (defina em config.local.php em produção)
define('HEALTH_TOKEN',    $_cfg['HEALTH_TOKEN']    ?? '');

unset($_cfg);

date_default_timezone_set('America/Sao_Paulo');

if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}
