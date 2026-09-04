<?php
require 'db.php';
requireLogin();
require 'topologia_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();

$temTabela = (bool)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'hosts_rede'"
)->fetchColumn();

if (!$temTabela) {
    echo json_encode(['nodes' => [], 'edges' => [], 'erro' => 'hosts_rede_ausente']);
    exit;
}

echo json_encode(topologiaMontarDados($pdo), JSON_UNESCAPED_UNICODE);
