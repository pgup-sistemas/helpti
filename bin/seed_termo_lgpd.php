<?php
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
define('HELPTI_BOOT', 1);
require_once __DIR__ . '/../config.php';
$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Pega o primeiro inventario disponível
$inv = $pdo->query("SELECT id FROM inventario WHERE deleted_at IS NULL LIMIT 1")->fetchColumn();
if (!$inv) { echo "Nenhum inventario.\n"; exit(1); }

$pdo->prepare("INSERT INTO termos_uso
    (inventario_id, responsavel_nome, responsavel_cpf, responsavel_matricula, setor, data_entrega, condicao_entrega, status)
    VALUES (?, 'Maria da Silva', '123.456.789-00', 'MAT0042', 'Ti', CURDATE(), 'Bom estado', 'Ativo')")
    ->execute([$inv]);
echo "Termo inserido com CPF=123.456.789-00, Matrícula=MAT0042\n";
