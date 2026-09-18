<?php
define('HELPTI_BOOT', 1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/bootstrap.php';

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// Setores em inventario que NAO estao em setores
$rows = $pdo->query("
    SELECT DISTINCT i.setor, LENGTH(i.setor) AS len, COUNT(*) AS ativos
    FROM inventario i
    LEFT JOIN setores s ON s.nome COLLATE utf8mb4_unicode_ci = i.setor COLLATE utf8mb4_unicode_ci
    WHERE i.setor IS NOT NULL AND i.setor != '' AND s.nome IS NULL
    GROUP BY i.setor
")->fetchAll();

echo "Setores orfaos (" . count($rows) . "):\n";
foreach ($rows as $r) {
    echo "  [{$r['setor']}] (len={$r['len']}, ativos={$r['ativos']})\n";
}

$setores = $pdo->query("SELECT nome FROM setores ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN);
echo "\nSetores no catalogo (" . count($setores) . "):\n";
foreach ($setores as $s) { echo "  [{$s}]\n"; }

// Total de ativos com setor vs sem
$t = $pdo->query("SELECT COUNT(*) AS total, SUM(setor IS NULL OR setor='') AS sem_setor FROM inventario")->fetch();
echo "\nTotal ativos: {$t['total']}, sem setor: {$t['sem_setor']}\n";
