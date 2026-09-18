<?php
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
define('HELPTI_BOOT', 1);
require_once __DIR__ . '/../config.php';
$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

foreach (['inventario','usuarios','impressoras'] as $tab) {
    $ativos  = $pdo->query("SELECT COUNT(*) FROM $tab WHERE deleted_at IS NULL")->fetchColumn();
    $deleted = $pdo->query("SELECT COUNT(*) FROM $tab WHERE deleted_at IS NOT NULL")->fetchColumn();
    echo "$tab: $ativos ativos | $deleted soft-deleted\n";
}
