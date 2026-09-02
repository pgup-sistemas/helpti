<?php
require 'db.php';
requireGestora();

$pdo = db();

function d(?string $v): string { return $v ? date('d/m/Y', strtotime($v)) : '—'; }
function bold(string $s): string { return "<b>$s</b>"; }
function moeda(?string $v): string { return $v !== null && $v !== '' ? 'R$ ' . number_format((float)$v, 2, ',', '.') : '—'; }

// Filtros vindos de contratos.php (GET passado via link)
$filtro_status = $_GET['status']  ?? '';
$filtro_tipo   = $_GET['tipo']    ?? '';
$busca         = trim($_GET['busca'] ?? '');

$where  = [];
$params = [];

if ($filtro_status) { $where[] = "status = ?";             $params[] = $filtro_status; }
if ($filtro_tipo)   { $where[] = "tipo = ?";               $params[] = $filtro_tipo; }
if ($busca)         { $where[] = "(nome LIKE ? OR fornecedor LIKE ? OR numero_contrato LIKE ?)";
                      $like = '%'.$busca.'%';
                      $params[] = $like; $params[] = $like; $params[] = $like; }

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Aba 1: lista completa ──────────────────────────────────
$stmt = $pdo->prepare("SELECT nome, tipo, fornecedor, numero_contrato,
    data_inicio, data_vencimento, valor_mensal, valor_total,
    status, renovacao_auto, alerta_dias, observacoes
    FROM contratos $where_sql ORDER BY data_vencimento ASC");
$stmt->execute($params);
$contratos = $stmt->fetchAll();

$sheet1 = [[
    bold('Nome / Objeto'), bold('Tipo'), bold('Fornecedor'), bold('Nº Contrato'),
    bold('Início'), bold('Vencimento'), bold('Valor Mensal'), bold('Valor Total'),
    bold('Status'), bold('Renovação Auto'), bold('Alerta (dias)'), bold('Observações'),
]];
foreach ($contratos as $r) {
    $sheet1[] = [
        $r['nome'], $r['tipo'], $r['fornecedor'] ?: '—', $r['numero_contrato'] ?: '—',
        d($r['data_inicio']), d($r['data_vencimento']),
        moeda($r['valor_mensal']), moeda($r['valor_total']),
        $r['status'], $r['renovacao_auto'] ? 'Sim' : 'Não',
        $r['alerta_dias'] ?: '—', $r['observacoes'] ?: '',
    ];
}
if (count($sheet1) === 1) $sheet1[] = ['Nenhum contrato encontrado com os filtros aplicados.','','','','','','','','','','',''];

// ── Aba 2: resumo por status ───────────────────────────────
$resumo = $pdo->query("SELECT status, COUNT(*) AS total,
    SUM(valor_mensal) AS soma_mensal, SUM(valor_total) AS soma_total
    FROM contratos GROUP BY status ORDER BY total DESC")->fetchAll();

$sheet2 = [[bold('Status'), bold('Quantidade'), bold('Soma Mensal'), bold('Soma Total')]];
foreach ($resumo as $r) {
    $sheet2[] = [$r['status'], $r['total'], moeda($r['soma_mensal']), moeda($r['soma_total'])];
}

// ── Aba 3: vencimentos próximos (90 dias) ──────────────────
$venc = $pdo->query("SELECT nome, fornecedor, data_vencimento,
    DATEDIFF(data_vencimento, CURDATE()) AS dias_restantes, status, valor_mensal
    FROM contratos
    WHERE status = 'Ativo' AND data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
    ORDER BY data_vencimento ASC")->fetchAll();

$sheet3 = [[bold('Nome'), bold('Fornecedor'), bold('Vencimento'), bold('Dias Restantes'), bold('Status'), bold('Valor Mensal')]];
foreach ($venc as $r) {
    $sheet3[] = [$r['nome'], $r['fornecedor'] ?: '—', d($r['data_vencimento']), (int)$r['dias_restantes'], $r['status'], moeda($r['valor_mensal'])];
}
if (count($sheet3) === 1) $sheet3[] = ['Nenhum contrato vencendo nos próximos 90 dias.','','','','',''];

// ── Aba 4: por tipo ────────────────────────────────────────
$por_tipo = $pdo->query("SELECT tipo, COUNT(*) AS total,
    SUM(valor_mensal) AS soma_mensal, SUM(valor_total) AS soma_total
    FROM contratos GROUP BY tipo ORDER BY total DESC")->fetchAll();

$sheet4 = [[bold('Tipo'), bold('Quantidade'), bold('Soma Mensal'), bold('Soma Total')]];
foreach ($por_tipo as $r) {
    $sheet4[] = [$r['tipo'], $r['total'], moeda($r['soma_mensal']), moeda($r['soma_total'])];
}

// ── Gerar XLSX ─────────────────────────────────────────────
require_once 'SimpleXLSXGen.php';
$filtro_label = $filtro_status ?: 'Todos';
$nomeArq = 'Contratos_HelpTI_' . date('Y-m-d') . '_' . preg_replace('/[^a-zA-Z0-9]/', '', $filtro_label);

$xlsx = Shuchkin\SimpleXLSXGen::fromArray($sheet1, 'Contratos');
$xlsx->addSheet($sheet2, 'Resumo por Status');
$xlsx->addSheet($sheet3, 'Vencimentos Próximos');
$xlsx->addSheet($sheet4, 'Por Tipo');
$xlsx->downloadAs("{$nomeArq}.xlsx");
