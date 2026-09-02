<?php
require 'db.php';
requireGestora();
require 'layout.php';

$pdo = db();

$resumo = $pdo->query("SELECT
    COUNT(*) AS total,
    SUM(status='Ativo') AS ativos,
    SUM(status='Vencido') AS vencidos,
    SUM(status='Em Renovação') AS em_renovacao,
    SUM(data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)) AS vencendo_60,
    SUM(valor) AS valor_total
    FROM contratos")->fetch();

$por_tipo = $pdo->query("SELECT tipo, COUNT(*) AS total, SUM(valor) AS valor
    FROM contratos GROUP BY tipo ORDER BY total DESC")->fetchAll();

$por_fornecedor = $pdo->query("SELECT fornecedor, COUNT(*) AS total, SUM(valor) AS valor
    FROM contratos WHERE fornecedor IS NOT NULL AND fornecedor != ''
    GROUP BY fornecedor ORDER BY valor DESC LIMIT 10")->fetchAll();

$vencendo = $pdo->query("SELECT nome, fornecedor, tipo, data_vencimento, valor
    FROM contratos WHERE status != 'Cancelado' AND data_vencimento IS NOT NULL
    ORDER BY data_vencimento ASC")->fetchAll();

// ── Exportação (CSV / XLSX) ────────────────────────────────
$fmt = $_GET['fmt'] ?? '';
if ($fmt === 'csv' || $fmt === 'xlsx') {
    $base = "Contratos_" . date('Y-m-d');

    $cab = ['Nome','Tipo','Fornecedor','Nº Contrato','Valor (R$)','Início','Vencimento','Status'];
    $dados = [$cab];
    $todos = $pdo->query("SELECT nome, tipo, fornecedor, numero_contrato, valor, data_inicio, data_vencimento, status FROM contratos ORDER BY data_vencimento ASC")->fetchAll();
    foreach ($todos as $r) {
        $dados[] = [$r['nome'], $r['tipo'], $r['fornecedor'] ?: '—', $r['numero_contrato'] ?: '—',
            $r['valor'] ? number_format($r['valor'],2,',','.') : '', $r['data_inicio'] ? date('d/m/Y', strtotime($r['data_inicio'])) : '—',
            $r['data_vencimento'] ? date('d/m/Y', strtotime($r['data_vencimento'])) : '—', $r['status']];
    }
    if (count($dados) === 1) $dados[] = ['Nenhum contrato cadastrado.','','','','','','',''];

    if ($fmt === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$base}.csv\"");
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        foreach ($dados as $row) fputcsv($out, $row, ';');
        fclose($out);
        exit;
    }

    require_once 'SimpleXLSXGen.php';
    $bold = fn($s) => "<b>{$s}</b>";
    $dados[0] = array_map($bold, $dados[0]);
    $xlsx = Shuchkin\SimpleXLSXGen::fromArray($dados, 'Contratos');

    $sheet_tipo = [[$bold('Tipo'), $bold('Total'), $bold('Valor (R$)')]];
    foreach ($por_tipo as $t) $sheet_tipo[] = [$t['tipo'], $t['total'], number_format($t['valor'] ?: 0, 2, ',', '.')];
    $xlsx->addSheet($sheet_tipo, 'Por Tipo');

    $xlsx->downloadAs("{$base}.xlsx");
    exit;
}

$tipo_json = json_encode(array_values($por_tipo));
$forn_json = json_encode(array_values($por_fornecedor));

layoutHeader('Relatório de Contratos', 'relatorios');
?>

<?php breadcrumb([['label'=>'Relatórios','href'=>'relatorios.php'],['label'=>'Contratos & Licenças']]); ?>
<div class="page-header">
  <h1 class="page-title"><i class="bi bi-file-earmark-check-fill me-2 text-primary"></i>Relatório de Contratos & Licenças</h1>
  <div class="d-flex gap-2">
    <a href="?fmt=xlsx" class="btn btn-success btn-sm fw-semibold"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    <a href="?fmt=csv" class="btn btn-outline-success btn-sm fw-semibold"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="d-flex justify-content-between">
      <div><div class="stat-num" style="color:var(--brand)"><?= (int)$resumo['total'] ?></div><div class="stat-label">Total</div></div>
      <i class="bi bi-file-earmark-text" style="font-size:24px;color:var(--brand);opacity:.3"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="d-flex justify-content-between">
      <div><div class="stat-num" style="color:#22c55e"><?= (int)$resumo['ativos'] ?></div><div class="stat-label">Ativos</div></div>
      <i class="bi bi-check-circle-fill" style="font-size:24px;color:#22c55e;opacity:.3"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="d-flex justify-content-between">
      <div><div class="stat-num" style="color:#f59e0b"><?= (int)$resumo['vencendo_60'] ?></div><div class="stat-label">Vencendo em 60 dias</div></div>
      <i class="bi bi-clock-fill" style="font-size:24px;color:#f59e0b;opacity:.3"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="d-flex justify-content-between">
      <div><div class="stat-num" style="color:#ef4444;font-size:20px"><?= number_format($resumo['valor_total'] ?: 0, 0, ',', '.') ?></div><div class="stat-label">Valor total (R$)</div></div>
      <i class="bi bi-cash-stack" style="font-size:24px;color:#ef4444;opacity:.3"></i>
    </div></div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header">Contratos por tipo</div>
      <div class="card-body"><div style="position:relative;height:280px"><canvas id="chartTipo"></canvas></div></div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header">Top 10 fornecedores por valor</div>
      <div class="card-body"><div style="position:relative;height:280px"><canvas id="chartFornecedor"></canvas></div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-calendar-event me-2"></i>Ordenado por vencimento</span>
    <span class="text-muted" style="font-size:12px"><?= count($vencendo) ?> registro(s)</span>
  </div>
  <div class="table-responsive" style="max-height:420px;overflow-y:auto">
    <table class="table table-sm table-hover mb-0" style="font-size:13px">
      <thead class="table-light" style="position:sticky;top:0">
        <tr><th>Nome</th><th>Tipo</th><th>Fornecedor</th><th>Vencimento</th><th>Valor</th></tr>
      </thead>
      <tbody>
        <?php foreach ($vencendo as $c):
          $dias = (strtotime($c['data_vencimento']) - time()) / 86400;
          $cls = $dias < 0 ? 'venc-vencido' : ($dias <= 60 ? 'venc-alerta' : 'venc-ok');
        ?>
        <tr>
          <td class="fw-semibold"><?= h($c['nome']) ?></td>
          <td style="font-size:12px"><?= h($c['tipo']) ?></td>
          <td><?= h($c['fornecedor'] ?: '—') ?></td>
          <td class="<?= $cls ?>"><?= date('d/m/Y', strtotime($c['data_vencimento'])) ?></td>
          <td><?= $c['valor'] ? 'R$ ' . number_format($c['valor'], 2, ',', '.') : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$vencendo): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">Nenhum contrato cadastrado.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const tipos = <?= $tipo_json ?>;
const fornecedores = <?= $forn_json ?>;

const canvasTipo = document.getElementById('chartTipo');
if (tipos.length > 0) {
  const bgColors = ['#0ea5e9','#22c55e','#f59e0b','#8b5cf6','#ec4899','#64748b'];
  new Chart(canvasTipo, {
    type:'doughnut',
    data:{ labels: tipos.map(d=>d.tipo), datasets:[{data: tipos.map(d=>d.total), backgroundColor: bgColors, borderWidth:2}] },
    options:{ maintainAspectRatio:false, plugins:{legend:{position:'right',labels:{boxWidth:12,font:{size:11}}}}, cutout:'60%' }
  });
} else {
  canvasTipo.style.display = 'none';
}

const canvasForn = document.getElementById('chartFornecedor');
if (fornecedores.length > 0) {
  new Chart(canvasForn, {
    type:'bar',
    data:{ labels: fornecedores.map(d=>d.fornecedor), datasets:[{label:'Valor (R$)',data:fornecedores.map(d=>d.valor),backgroundColor:'#818cf8',borderRadius:4}] },
    options:{ maintainAspectRatio:false, indexAxis:'y', plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true}} }
  });
} else {
  canvasForn.style.display = 'none';
}
</script>

<?php layoutFooter(); ?>
