<?php
/**
 * cep_proxy.php — Proxy server-side para ViaCEP
 * Chamado por cep.js quando o fetch direto falhar (CORS, firewall, etc.)
 */
require __DIR__ . '/db.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=86400'); // cache 24h no browser

$cep = preg_replace('/\D/', '', $_GET['cep'] ?? '');
if (strlen($cep) !== 8) {
    http_response_code(400);
    echo json_encode(['erro' => true]);
    exit;
}

$ctx = stream_context_create([
    'http' => [
        'timeout'     => 6,
        'user_agent'  => 'HelpTI/1.0',
        'ignore_errors' => true,
    ],
    'ssl' => [
        'verify_peer'      => false,
        'verify_peer_name' => false,
    ],
]);

$url  = "https://viacep.com.br/ws/{$cep}/json/";
$body = @file_get_contents($url, false, $ctx);

if ($body === false) {
    http_response_code(502);
    echo json_encode(['erro' => true]);
    exit;
}

echo $body;
