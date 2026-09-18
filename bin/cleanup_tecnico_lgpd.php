<?php
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
define('HELPTI_BOOT', 1);
require_once __DIR__ . '/../config.php';
$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
$pdo->exec("UPDATE usuarios SET deleted_at=NOW(), ativo=0 WHERE email='tecnico_lgpd@helpti.local'");
echo "Usuário tecnico_lgpd desativado.\n";
