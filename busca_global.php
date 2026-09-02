<?php
require 'db.php';
requireLogin();
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode([]); exit; }

$pdo    = db();
$like   = '%' . $q . '%';
$itens  = [];

// Chamados
$rows = $pdo->prepare("
    SELECT id, numero, setor, descricao, status, nivel
    FROM chamados
    WHERE numero LIKE ? OR setor LIKE ? OR descricao LIKE ? OR solicitante LIKE ?
    ORDER BY criado_em DESC LIMIT 6
");
$rows->execute([$like, $like, $like, $like]);
foreach ($rows->fetchAll() as $r) {
    $cor = match($r['status']) {
        'Concluído' => '#22c55e', 'Em Andamento' => '#f59e0b', default => '#3b82f6'
    };
    $itens[] = [
        'icone' => 'bi-headset',
        'cor'   => $cor,
        'titulo'=> "#{$r['numero']} — {$r['setor']}",
        'sub'   => mb_strimwidth($r['descricao'], 0, 60, '…') . " · {$r['status']}",
        'link'  => "chamado.php?id={$r['id']}",
        'grupo' => 'Chamados',
    ];
}

// Inventário
$rows = $pdo->prepare("
    SELECT id, tipo, marca, modelo, numero_serie, patrimonio, setor, status
    FROM inventario
    WHERE tipo LIKE ? OR marca LIKE ? OR modelo LIKE ? OR numero_serie LIKE ? OR patrimonio LIKE ? OR setor LIKE ?
    LIMIT 5
");
$rows->execute([$like,$like,$like,$like,$like,$like]);
foreach ($rows->fetchAll() as $r) {
    $nome = trim($r['marca'].' '.$r['modelo']) ?: $r['tipo'];
    $itens[] = [
        'icone' => 'bi-pc-display',
        'cor'   => '#8b5cf6',
        'titulo'=> "{$r['tipo']} — {$nome}",
        'sub'   => ($r['setor'] ?? '—') . ' · ' . ($r['status'] ?? ''),
        'link'  => "inventario.php?action=editar&id={$r['id']}",
        'grupo' => 'Inventário',
    ];
}

// Contratos
$rows = $pdo->prepare("
    SELECT id, nome, fornecedor, status
    FROM contratos
    WHERE nome LIKE ? OR fornecedor LIKE ?
    LIMIT 4
");
$rows->execute([$like,$like]);
foreach ($rows->fetchAll() as $r) {
    $itens[] = [
        'icone' => 'bi-file-earmark-text',
        'cor'   => '#0ea5e9',
        'titulo'=> $r['nome'],
        'sub'   => ($r['fornecedor'] ?? '—') . ' · ' . $r['status'],
        'link'  => "contratos.php?action=editar&id={$r['id']}",
        'grupo' => 'Contratos',
    ];
}

// Usuários
$rows = $pdo->prepare("
    SELECT id, nome, email, perfil FROM usuarios WHERE nome LIKE ? OR email LIKE ? LIMIT 4
");
$rows->execute([$like,$like]);
foreach ($rows->fetchAll() as $r) {
    $itens[] = [
        'icone' => 'bi-person-circle',
        'cor'   => '#64748b',
        'titulo'=> $r['nome'],
        'sub'   => $r['email'] . ' · ' . $r['perfil'],
        'link'  => "usuarios.php",
        'grupo' => 'Usuários',
    ];
}

echo json_encode($itens);
