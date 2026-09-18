<?php
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
define('HELPTI_BOOT', 1);
require_once __DIR__ . '/../config.php';
$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->exec("INSERT IGNORE INTO unidades (nome, codigo, cidade, sla_multiplicador, ativo)
    VALUES ('Filial Porto Velho', 'PVH', 'Porto Velho', 1.50, 1)");
echo "Filial Porto Velho inserida (id=" . $pdo->lastInsertId() . ", SLA x1.50)\n";
