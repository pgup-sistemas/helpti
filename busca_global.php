<?php
require 'db.php';
requireLogin();
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) { echo json_encode([]); exit; }

$pdo   = db();
$itens = [];

// ── Estratégia: FULLTEXT (BOOLEAN, prefixo) quando todos os termos têm >= 3
// caracteres; senão cai para LIKE. numero/patrimônio sempre por prefixo. (P3-3)
$termos = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
$usaFulltext = $termos && !array_filter($termos, static fn($t) => mb_strlen($t) < 3);

$boolean = implode(' ', array_map(
    static fn($t) => '+' . preg_replace('/[+\-*"()~<>@]/', '', $t) . '*',
    $termos,
));
$like    = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
$prefix  = str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

// ── Chamados ──────────────────────────────────────────────
if ($usaFulltext) {
    $rows = $pdo->prepare("
        SELECT id, numero, setor, descricao, status
        FROM chamados
        WHERE deleted_at IS NULL
          AND (MATCH(descricao, solicitante) AGAINST(:b IN BOOLEAN MODE) OR numero LIKE :p)
        ORDER BY criado_em DESC LIMIT 6
    ");
    $rows->execute(['b' => $boolean, 'p' => $prefix]);
} else {
    $rows = $pdo->prepare("
        SELECT id, numero, setor, descricao, status
        FROM chamados
        WHERE deleted_at IS NULL
          AND (numero LIKE :l OR setor LIKE :l2 OR descricao LIKE :l3 OR solicitante LIKE :l4)
        ORDER BY criado_em DESC LIMIT 6
    ");
    $rows->execute(['l' => $like, 'l2' => $like, 'l3' => $like, 'l4' => $like]);
}
foreach ($rows->fetchAll() as $r) {
    $cor = match ($r['status']) {
        'Concluído' => '#22c55e', 'Em Andamento' => '#f59e0b', default => '#3b82f6',
    };
    $itens[] = [
        'icone'  => 'bi-headset', 'cor' => $cor,
        'titulo' => "#{$r['numero']} — {$r['setor']}",
        'sub'    => mb_strimwidth((string) $r['descricao'], 0, 60, '…') . " · {$r['status']}",
        'link'   => "chamado.php?id={$r['id']}", 'grupo' => 'Chamados',
    ];
}

// ── Inventário ────────────────────────────────────────────
if ($usaFulltext) {
    $rows = $pdo->prepare("
        SELECT id, tipo, marca, modelo, numero_serie, patrimonio, setor, status
        FROM inventario
        WHERE MATCH(tipo, marca, modelo, numero_serie, patrimonio, setor) AGAINST(:b IN BOOLEAN MODE)
           OR patrimonio LIKE :p OR numero_serie LIKE :p2
        LIMIT 5
    ");
    $rows->execute(['b' => $boolean, 'p' => $prefix, 'p2' => $prefix]);
} else {
    $rows = $pdo->prepare("
        SELECT id, tipo, marca, modelo, numero_serie, patrimonio, setor, status
        FROM inventario
        WHERE tipo LIKE :l OR marca LIKE :l2 OR modelo LIKE :l3
           OR numero_serie LIKE :l4 OR patrimonio LIKE :l5 OR setor LIKE :l6
        LIMIT 5
    ");
    $rows->execute(['l' => $like, 'l2' => $like, 'l3' => $like, 'l4' => $like, 'l5' => $like, 'l6' => $like]);
}
foreach ($rows->fetchAll() as $r) {
    $nome = trim($r['marca'] . ' ' . $r['modelo']) ?: $r['tipo'];
    $itens[] = [
        'icone'  => 'bi-pc-display', 'cor' => '#8b5cf6',
        'titulo' => "{$r['tipo']} — {$nome}",
        'sub'    => ($r['setor'] ?? '—') . ' · ' . ($r['status'] ?? ''),
        'link'   => "inventario.php?action=editar&id={$r['id']}", 'grupo' => 'Inventário',
    ];
}

// ── Contratos ────────────────────────────────────────────
if ($usaFulltext) {
    $rows = $pdo->prepare("
        SELECT id, nome, fornecedor, status FROM contratos
        WHERE MATCH(nome, fornecedor) AGAINST(:b IN BOOLEAN MODE) LIMIT 4
    ");
    $rows->execute(['b' => $boolean]);
} else {
    $rows = $pdo->prepare("
        SELECT id, nome, fornecedor, status FROM contratos
        WHERE nome LIKE :l OR fornecedor LIKE :l2 LIMIT 4
    ");
    $rows->execute(['l' => $like, 'l2' => $like]);
}
foreach ($rows->fetchAll() as $r) {
    $itens[] = [
        'icone'  => 'bi-file-earmark-text', 'cor' => '#0ea5e9',
        'titulo' => $r['nome'],
        'sub'    => ($r['fornecedor'] ?? '—') . ' · ' . $r['status'],
        'link'   => "contratos.php?action=editar&id={$r['id']}", 'grupo' => 'Contratos',
    ];
}

// ── Usuários (sempre LIKE — volume baixo) ─────────────────
$rows = $pdo->prepare("SELECT id, nome, email, perfil FROM usuarios WHERE nome LIKE ? OR email LIKE ? LIMIT 4");
$rows->execute([$like, $like]);
foreach ($rows->fetchAll() as $r) {
    $itens[] = [
        'icone'  => 'bi-person-circle', 'cor' => '#64748b',
        'titulo' => $r['nome'],
        'sub'    => $r['email'] . ' · ' . $r['perfil'],
        'link'   => 'usuarios.php', 'grupo' => 'Usuários',
    ];
}

echo json_encode($itens);
