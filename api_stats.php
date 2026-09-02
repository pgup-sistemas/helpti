<?php
// Endpoint JSON para polling do dashboard — sem layout, sem HTML
require 'db.php';
requireLogin();

header('Content-Type: application/json');
header('Cache-Control: no-store');

$stats = db()->query("
    SELECT
        COUNT(*) AS total,
        SUM(status='Aberto') AS abertos,
        SUM(status='Em Andamento') AS andamento,
        SUM(status='Pendente') AS pendentes,
        SUM(status='Concluído') AS concluidos
    FROM chamados
    WHERE deleted_at IS NULL
")->fetch();

echo json_encode([
    'total'     => (int)$stats['total'],
    'abertos'   => (int)$stats['abertos'],
    'andamento' => (int)$stats['andamento'],
    'pendentes' => (int)$stats['pendentes'],
    'concluidos'=> (int)$stats['concluidos'],
    'ts'        => time(),
]);
