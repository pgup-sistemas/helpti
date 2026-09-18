<?php
define('HELPTI_BOOT', 1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/bootstrap.php';
$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$tabelas = ['inventario','usuarios','impressoras','chamados','historico_movimentacao','auditoria_itens'];
foreach ($tabelas as $tab) {
    $fks = $pdo->query("
        SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
               kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
               rc.DELETE_RULE, rc.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE kcu
        JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
          ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
         AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
        WHERE kcu.TABLE_SCHEMA = 'helpti'
          AND kcu.TABLE_NAME = '$tab'
          AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
    ")->fetchAll();
    echo "\n=== FKs em $tab ===\n";
    foreach ($fks as $f) {
        echo "  {$f['CONSTRAINT_NAME']}: {$tab}.{$f['COLUMN_NAME']} -> {$f['REFERENCED_TABLE_NAME']}.{$f['REFERENCED_COLUMN_NAME']} (DEL={$f['DELETE_RULE']}, UPD={$f['UPDATE_RULE']})\n";
    }
    if (!$fks) echo "  (nenhuma FK)\n";
}

echo "\n=== Colunas deleted_at ===\n";
$cols = $pdo->query("
    SELECT TABLE_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='helpti' AND COLUMN_NAME='deleted_at'
    ORDER BY TABLE_NAME
")->fetchAll(PDO::FETCH_COLUMN);
foreach ($cols as $c) { echo "  $c.deleted_at ✅\n"; }

echo "\n=== Tabelas novas ===\n";
foreach (['inventario_snapshot_mensal','v_termos_uso_publico'] as $t) {
    $exists = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='helpti' AND TABLE_NAME='$t'")->fetchColumn();
    echo "  $t: " . ($exists ? '✅ existe' : '❌ ausente') . "\n";
}
