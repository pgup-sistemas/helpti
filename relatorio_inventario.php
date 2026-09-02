<?php
require 'db.php';
requireGestora();
require 'layout.php';

$pdo = db();

$resumo = $pdo->query("SELECT
    COUNT(*) AS total,
    SUM(status='Em Uso') AS em_uso,
    SUM(status='Disponível') AS disponivel,
    SUM(status='Em Manutenção') AS manutencao,
    SUM(status='Descartado') AS descartado,
    SUM(valor) AS valor_total
    FROM inventario")->fetch();

$por_tipo = $pdo->query("SELECT tipo, COUNT(*) AS total
    FROM inventario WHERE status != 'Descartado'
    GROUP BY tipo ORDER BY total DESC")->fetchAll();

$por_setor = $pdo->query("SELECT setor, COUNT(*) AS total
    FROM inventario WHERE status != 'Descartado' AND setor IS NOT NULL AND setor != ''
    GROUP BY setor ORDER BY total DESC LIMIT 10")->fetchAll();

$garantias = $pdo->query("SELECT tipo, marca, modelo, patrimonio, setor, garantia_ate
    FROM inventario WHERE garantia_ate IS NOT NULL AND garantia_ate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
    ORDER BY garantia_ate ASC")->fetchAll();

// ── Exportação (CSV / XLSX) ────────────────────────────────
$fmt = $_GET['fmt'] ?? '';
if ($fmt === 'csv' || $fmt === 'xlsx') {
    $base = "Inventario_" . date('Y-m-d');

    $cab = ['Tipo','Marca','Modelo','S/N','Patrimônio','Setor','Responsável','Status','Aquisição','Valor (R$)','Garantia até'];
    $dados = [$cab];
    $todos = $pdo->query("SELECT tipo,marca,modelo,numero_serie,patrimonio,setor,responsavel_nome,status,data_aquisicao,valor,garantia_ate FROM inventario ORDER BY setor, tipo")->fetchAll();
    foreach ($todos as $r) {
        $dados[] = [$r['tipo'], $r['marca'], $r['modelo'], $r['numero_serie'] ?: '—', $r['patrimonio'] ?: '—', $r['setor'],
            $r['responsavel_nome'] ?: '—', $r['status'],
            $r['data_aquisicao'] ? date('d/m/Y', strtotime($r['data_aquisicao'])) : '—',
            $r['valor'] ? number_format($r['valor'],2,',','.') : '',
            $r['garantia_ate'] ? date('d/m/Y', strtotime($r['garantia_ate'])) : '—'];
    }
    if (count($dados) === 1) $dados[] = ['Nenhum equipamento cadastrado.','','','','','','','','','',''];

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
    $xlsx = Shuchkin\SimpleXLSXGen::fromArray($dados, 'Inventário');

    $sheet_tipo = [[$bold('Tipo'), $bold('Total')]];
    foreach ($por_tipo as $t) $sheet_tipo[] = [$t['tipo'], $t['total']];
    $xlsx->addSheet($sheet_tipo, 'Por Categoria');

    $xlsx->downloadAs("{$base}.xlsx");
    exit;
}

$tipo_json  = json_encode(array_values($por_tipo));
$setor_json = json_encode(array_values($por_setor));

layoutHeader('Relatório de Inventário', 'relatorios');
?>

<?php breadcrumb([['label'=>'Relatórios','href'=>'relatorios.php'],['label'=>'Inventário']]); ?>
<div class="page-header">
  <h1 class="page-title"><i class="bi bi-pc-display me-2 text-primary"></i>Relatório de Inventário</h1>
  <div class="d-flex gap-2">
    <a href="?fmt=xlsx" class="btn btn-success btn-sm fw-semibold"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    <a href="?fmt=csv" class="btn btn-outline-success btn-sm fw-semibold"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="d-flex justify-content-between">
      <div><div class="stat-num" style="color:#22c55e"><?= (int)$resumo['em_uso'] ?></div><div class="stat-label">Em Uso</div></div>
      <i class="bi bi-check-circle-fill" style="font-size:24px;color:#22c55e;opacity:.3"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="d-flex justify-content-between">
      <div><div class="stat-num" style="color:#0ea5e9"><?= (int)$resumo['disponivel'] ?></div><div class="stat-label">Disponível</div></div>
      <i class="bi bi-archive" style="font-size:24px;color:#0ea5e9;opacity:.3"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="d-flex justify-content-between">
      <div><div class="stat-num" style="color:#f59e0b"><?= (int)$resumo['manutencao'] ?></div><div class="stat-label">Em Manutenção</div></div>
      <i class="bi bi-wrench" style="font-size:24px;color:#f59e0b;opacity:.3"></i>
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
      <div class="card-header">Por categoria (ativos)</div>
      <div class="card-body"><div style="position:relative;height:280px"><canvas id="chartTipo"></canvas></div></div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header">Top 10 setores com mais equipamentos</div>
      <div class="card-body"><div style="position:relative;height:280px"><canvas id="chartSetor"></canvas></div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-shield-exclamation me-2 text-warning"></i>Garantias vencendo em até 90 dias</span>
    <span class="text-muted" style="font-size:12px"><?= count($garantias) ?> registro(s)</span>
  </div>
  <div class="table-responsive" style="max-height:420px;overflow-y:auto">
    <table class="table table-sm table-hover mb-0" style="font-size:13px">
      <thead class="table-light" style="position:sticky;top:0">
        <tr><th>Tipo</th><th>Marca/Modelo</th><th>Patrimônio</th><th>Setor</th><th>Garantia até</th></tr>
      </thead>
      <tbody>
        <?php foreach ($garantias as $g):
          $dias = (strtotime($g['garantia_ate']) - time()) / 86400;
          $cls = $dias <= 30 ? 'venc-vencido' : 'venc-alerta';
        ?>
        <tr>
          <td><?= h($g['tipo']) ?></td>
          <td class="fw-semibold"><?= h($g['marca'].' '.$g['modelo']) ?></td>
          <td><?= h($g['patrimonio'] ?: '—') ?></td>
          <td style="font-size:12px"><?= h($g['setor']) ?></td>
          <td class="<?= $cls ?>"><?= date('d/m/Y', strtotime($g['garantia_ate'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$garantias): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">Nenhuma garantia vencendo nos próximos 90 dias.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const tipos  = <?= $tipo_json ?>;
const setores = <?= $setor_json ?>;

const canvasTipo = document.getElementById('chartTipo');
if (tipos.length > 0) {
  const bgColors = ['#6366f1','#0ea5e9','#22c55e','#f59e0b','#8b5cf6','#ec4899','#64748b','#f43f5e','#14b8a6','#a855f7'];
  new Chart(canvasTipo, {
    type:'doughnut',
    data:{ labels: tipos.map(d=>d.tipo), datasets:[{data: tipos.map(d=>d.total), backgroundColor: bgColors, borderWidth:2}] },
    options:{ maintainAspectRatio:false, plugins:{legend:{position:'right',labels:{boxWidth:12,font:{size:11}}}}, cutout:'60%' }
  });
} else {
  canvasTipo.style.display = 'none';
}

const canvasSetor = document.getElementById('chartSetor');
if (setores.length > 0) {
  new Chart(canvasSetor, {
    type:'bar',
    data:{ labels: setores.map(d=>d.setor.replace(/^\d+ - /,'')), datasets:[{label:'Equipamentos',data:setores.map(d=>d.total),backgroundColor:'#0ea5e9',borderRadius:4}] },
    options:{ maintainAspectRatio:false, indexAxis:'y', plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true,ticks:{precision:0}}} }
  });
} else {
  canvasSetor.style.display = 'none';
}
</script>

<?php layoutFooter(); ?>
