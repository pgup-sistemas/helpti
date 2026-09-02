<?php
// Endpoint público — retorna apenas status e atualizado_em de um chamado pelo número
require 'db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$numero = strtoupper(trim($_GET['numero'] ?? ''));
if (!preg_match('/^CHM-[A-Z0-9]{6}$/', $numero)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid']);
    exit;
}

$st = db()->prepare("SELECT status, atualizado_em FROM chamados WHERE numero=?");
$st->execute([$numero]);
$row = $st->fetch();

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}

echo json_encode([
    'status'       => $row['status'],
    'atualizado_em' => $row['atualizado_em'],
]);
