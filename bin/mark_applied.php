<?php
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
require __DIR__ . '/../config.php';
$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$versao = $argv[1] ?? '';
if (!$versao) { echo "Uso: php bin/mark_applied.php 0011_enterprise_fixes.sql\n"; exit(1); }
$pdo->prepare("INSERT IGNORE INTO schema_migrations (versao) VALUES (?)")->execute([$versao]);
echo "Marcado como aplicado: $versao\n";
