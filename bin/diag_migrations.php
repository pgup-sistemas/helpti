<?php
define('HELPTI_BOOT', 1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/bootstrap.php';
$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

echo "=== Migrations aplicadas ===\n";
$rows = $pdo->query("SELECT * FROM schema_migrations ORDER BY aplicado_em")->fetchAll();
foreach ($rows as $r) { echo "  {$r['versao']} — {$r['aplicado_em']}\n"; }

echo "\n=== FKs em inventario ===\n";
$fks = $pdo->query("
    SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME,
           DELETE_RULE, UPDATE_RULE
    FROM information_schema.KEY_COLUMN_USAGE kcu
    JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
      ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
    WHERE kcu.TABLE_SCHEMA = 'helpti' AND kcu.TABLE_NAME = 'inventario'
      AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
")->fetchAll();
foreach ($fks as $f) {
    echo "  {$f['CONSTRAINT_NAME']}: inventario.{$f['COLUMN_NAME']} -> {$f['REFERENCED_TABLE_NAME']}.{$f['REFERENCED_COLUMN_NAME']} (DEL={$f['DELETE_RULE']}, UPD={$f['UPDATE_RULE']})\n";
}

echo "\n=== FKs em chamados ===\n";
$fks2 = $pdo->query("
    SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, DELETE_RULE
    FROM information_schema.KEY_COLUMN_USAGE kcu
    JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
      ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
    WHERE kcu.TABLE_SCHEMA = 'helpti' AND kcu.TABLE_NAME = 'chamados'
      AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
")->fetchAll();
foreach ($fks2 as $f) {
    echo "  {$f['CONSTRAINT_NAME']}: chamados.{$f['COLUMN_NAME']} -> {$f['REFERENCED_TABLE_NAME']} (DEL={$f['DELETE_RULE']})\n";
}

echo "\n=== FKs em historico_movimentacao ===\n";
$fks3 = $pdo->query("
    SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, DELETE_RULE
    FROM information_schema.KEY_COLUMN_USAGE kcu
    JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
      ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
    WHERE kcu.TABLE_SCHEMA = 'helpti' AND kcu.TABLE_NAME = 'historico_movimentacao'
      AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
")->fetchAll();
foreach ($fks3 as $f) {
    echo "  {$f['CONSTRAINT_NAME']}: historico_movimentacao.{$f['COLUMN_NAME']} -> {$f['REFERENCED_TABLE_NAME']} (DEL={$f['DELETE_RULE']})\n";
}

echo "\n=== Colunas deleted_at ===\n";
$cols = $pdo->query("
    SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='helpti' AND COLUMN_NAME='deleted_at'
")->fetchAll();
foreach ($cols as $c) { echo "  {$c['TABLE_NAME']}.deleted_at\n"; }
