<?php
// Endpoint público — retorna apenas status/atualizado_em de um chamado pelo número.
// Sem PII. Usado pelo auto-polling do portal.
require 'db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!rateLimit('status_chamado_' . clientIp(), 60, 600)) {
    http_response_code(429);
    echo json_encode(['error' => 'rate_limited']);
    exit;
}

$numero = strtoupper(trim($_GET['numero'] ?? ''));
// Formato real: CHM-2026-00001
if (!preg_match('/^CHM-\d{4}-\d{5}$/', $numero)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid']);
    exit;
}

$st = db()->prepare("SELECT status, atualizado_em FROM chamados WHERE numero = ? AND deleted_at IS NULL");
$st->execute([$numero]);
$row = $st->fetch();

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}

echo json_encode([
    'status'        => $row['status'],
    'atualizado_em' => $row['atualizado_em'],
]);
