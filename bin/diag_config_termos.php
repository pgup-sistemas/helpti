<?php
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
define('HELPTI_BOOT', 1);
require_once __DIR__ . '/../config.php';
$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
$tables = $pdo->query("SHOW TABLES LIKE 'config%'")->fetchAll(PDO::FETCH_COLUMN);
echo "Tabelas config*: " . implode(', ', $tables ?: ['nenhuma']) . "\n";
$exists = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='helpti' AND TABLE_NAME='config_termos'")->fetchColumn();
echo "config_termos existe: " . ($exists ? 'SIM' : 'NAO') . "\n";
