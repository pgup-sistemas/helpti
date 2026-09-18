<?php
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
define('HELPTI_BOOT', 1);
require_once __DIR__ . '/../config.php';
$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$rows = $pdo->query("
    SELECT c.id, c.numero, c.setor, c.solicitante, c.unidade_id, u.nome AS unidade_nome, u.sla_multiplicador
    FROM chamados c
    LEFT JOIN unidades u ON u.id = c.unidade_id
    ORDER BY c.id DESC LIMIT 5
")->fetchAll();
foreach ($rows as $r) {
    echo "#{$r['id']} {$r['numero']} | setor={$r['setor']} | unidade_id={$r['unidade_id']} | unidade={$r['unidade_nome']} | sla_mult={$r['sla_multiplicador']}\n";
}
