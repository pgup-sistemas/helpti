<?php
require 'db.php';
requireGestora();
require 'layout.php';

$pdo = db();
$mes = (int)($_GET['mes'] ?? date('m'));
$ano = (int)($_GET['ano'] ?? date('Y'));
$params = ['mes'=>$mes,'ano'=>$ano];
$meses_labels = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];

$totais = $pdo->prepare("SELECT
    COUNT(*) AS total,
    SUM(status='Entregue') AS entregues,
    SUM(status='Aprovado') AS aprovados,
    SUM(status='Pendente') AS pendentes,
    SUM(status='Cancelado') AS cancelados
    FROM pedidos_suprimentos WHERE MONTH(criado_em)=:mes AND YEAR(criado_em)=:ano");
$totais->execute($params);
$totais = $totais->fetch();

$insumos = $pdo->prepare("SELECT COALESCE(ts.nome, pi.descricao_livre, 'Outros') as insumo_nome, SUM(pi.quantidade) as total_qtd
    FROM pedidos_suprimentos s
    JOIN pedidos_suprimentos_itens pi ON s.id = pi.pedido_id
    LEFT JOIN tipos_suprimentos ts ON ts.id = pi.tipo_suprimento_id
    WHERE MONTH(s.criado_em)=:mes AND YEAR(s.criado_em)=:ano
    GROUP BY insumo_nome
    ORDER BY total_qtd DESC LIMIT 10");
$insumos->execute($params);
$insumos = $insumos->fetchAll();

$por_setor = $pdo->prepare("SELECT setor, COUNT(*) AS total
    FROM pedidos_suprimentos WHERE MONTH(criado_em)=:mes AND YEAR(criado_em)=:ano
    GROUP BY setor ORDER BY total DESC LIMIT 10");
$por_setor->execute($params);
$por_setor = $por_setor->fetchAll();

$pedidos = $pdo->prepare("SELECT p.numero, p.setor, p.solicitante, p.status, p.criado_em,
    i.nome AS impressora_nome
    FROM pedidos_suprimentos p LEFT JOIN impressoras i ON i.id = p.impressora_id
    WHERE MONTH(p.criado_em)=:mes AND YEAR(p.criado_em)=:ano
    ORDER BY p.criado_em DESC");
$pedidos->execute($params);
$pedidos = $pedidos->fetchAll();

// ── Exportação (CSV / XLSX) ────────────────────────────────
$fmt = $_GET['fmt'] ?? '';
if ($fmt === 'csv' || $fmt === 'xlsx') {
    $nome_mes = $meses_labels[$mes-1];
    $base = "Suprimentos_{$nome_mes}_{$ano}";

    $cab = ['Nº','Setor','Solicitante','Impressora','Status','Data'];
    $dados = [$cab];
    foreach ($pedidos as $r) {
        $dados[] = [$r['numero'], $r['setor'], $r['solicitante'], $r['impressora_nome'] ?: 'Geral', $r['status'], date('d/m/Y H:i', strtotime($r['criado_em']))];
    }
    if (count($dados) === 1) $dados[] = ['Nenhum pedido neste período.','','','','',''];

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
    $xlsx = Shuchkin\SimpleXLSXGen::fromArray($dados, 'Pedidos');

    $sheet_ins = [[$bold('Insumo'), $bold('Quantidade')]];
    foreach ($insumos as $i) $sheet_ins[] = [$i['insumo_nome'], $i['total_qtd']];
    $xlsx->addSheet($sheet_ins, 'Insumos Consumidos');

    $sheet_setor = [[$bold('Setor'), $bold('Pedidos')]];
    foreach ($por_setor as $s) $sheet_setor[] = [$s['setor'], $s['total']];
    $xlsx->addSheet($sheet_setor, 'Por Setor');

    $xlsx->downloadAs("{$base}.xlsx");
    exit;
}

$insumos_json  = json_encode(array_values($insumos));
$setor_json    = json_encode(array_values($por_setor));

layoutHeader('Relatório de Suprimentos', 'relatorios');
?>

<?php breadcrumb([['label'=>'Relatórios','href'=>'relatorios.php'],['label'=>'Suprimentos']]); ?>
<div class="page-header">
  <h1 class="page-title"><i class="bi bi-box-seam-fill me-2 text-primary"></i>Relatório de Suprimentos</h1>
  <div class="d-flex gap-3 align-items-center">
    <form method="get" class="d-flex gap-2 align-items-center">
      <select name="mes" class="form-select form-select-sm" style="width:130px">
        <?php for($m=1;$m<=12;$m++): ?>
          <option value="<?= $m ?>" <?= $m===$mes?'selected':'' ?>><?= $meses_labels[$m-1] ?></option>
        <?php endfor; ?>
      </select>
      <select name="ano" class="form-select form-select-sm" style="width:85px">
        <?php for($a=2024;$a<=2027;$a++): ?>
          <option value="<?= $a ?>" <?= $a===$ano?'selected':'' ?>><?= $a ?></option>
        <?php endfor; ?>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Atualizar</button>
    </form>
    <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>&fmt=xlsx" class="btn btn-success btn-sm fw-semibold"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    <a href="?mes=<?= $mes ?>&ano=<?= $ano ?>&fmt=csv" class="btn btn-outline-success btn-sm fw-semibold"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="d-flex justify-content-between">
      <div><div class="stat-num" style="color:var(--brand)"><?= (int)$totais['total'] ?></div><div class="stat-label">Total de Pedidos</div></div>
      <i class="bi bi-box-seam" style="font-size:24px;color:var(--brand);opacity:.3"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="d-flex justify-content-between">
      <div><div class="stat-num" style="color:#22c55e"><?= (int)$totais['entregues'] ?></div><div class="stat-label">Entregues</div></div>
      <i class="bi bi-check-circle-fill" style="font-size:24px;color:#22c55e;opacity:.3"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="d-flex justify-content-between">
      <div><div class="stat-num" style="color:#f59e0b"><?= (int)($totais['pendentes']+$totais['aprovados']) ?></div><div class="stat-label">Pendentes / Separação</div></div>
      <i class="bi bi-hourglass-split" style="font-size:24px;color:#f59e0b;opacity:.3"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="d-flex justify-content-between">
      <div><div class="stat-num" style="color:#94a3b8"><?= (int)$totais['cancelados'] ?></div><div class="stat-label">Cancelados</div></div>
      <i class="bi bi-x-circle-fill" style="font-size:24px;color:#94a3b8;opacity:.3"></i>
    </div></div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-pie-chart-fill me-2 text-warning"></i>Insumos Mais Consumidos (Qtd.)</div>
      <div class="card-body"><div style="position:relative;height:280px"><canvas id="chartInsumos"></canvas></div></div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-building me-2 text-primary"></i>Pedidos por Setor</div>
      <div class="card-body"><div style="position:relative;height:280px"><canvas id="chartSetor"></canvas></div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-table me-2"></i>Pedidos do período</span>
    <span class="text-muted" style="font-size:12px"><?= count($pedidos) ?> registro(s)</span>
  </div>
  <div class="table-responsive" style="max-height:420px;overflow-y:auto">
    <table class="table table-sm table-hover mb-0" style="font-size:13px">
      <thead class="table-light" style="position:sticky;top:0">
        <tr><th>Nº</th><th>Setor</th><th>Solicitante</th><th>Impressora</th><th>Status</th><th>Data</th></tr>
      </thead>
      <tbody>
        <?php foreach ($pedidos as $p): ?>
        <tr>
          <td><code><?= h($p['numero']) ?></code></td>
          <td><?= h($p['setor']) ?></td>
          <td><?= h($p['solicitante']) ?></td>
          <td><?= h($p['impressora_nome'] ?: 'Geral') ?></td>
          <td><span class="badge bg-<?= match($p['status']){'Entregue'=>'success','Aprovado'=>'warning text-dark','Cancelado'=>'secondary',default=>'danger'} ?>"><?= h($p['status']) ?></span></td>
          <td style="color:#6b7280"><?= date('d/m H:i', strtotime($p['criado_em'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$pedidos): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Nenhum pedido neste período.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const insumos = <?= $insumos_json ?>;
const setores = <?= $setor_json ?>;

const canvasInsumos = document.getElementById('chartInsumos');
if (insumos.length > 0) {
  const bgColors = ['#0ea5e9','#22c55e','#f59e0b','#8b5cf6','#ec4899','#64748b','#f43f5e','#14b8a6','#a855f7','#0d6efd'];
  new Chart(canvasInsumos, {
    type:'doughnut',
    data:{ labels: insumos.map(d => d.insumo_nome), datasets:[{data: insumos.map(d => d.total_qtd), backgroundColor: bgColors, borderWidth:2}] },
    options:{ maintainAspectRatio:false, plugins:{legend:{position:'right',labels:{boxWidth:12,font:{size:11}}}}, cutout:'60%' }
  });
} else {
  canvasInsumos.style.display = 'none';
  canvasInsumos.insertAdjacentHTML('afterend','<div class="text-center text-muted py-5" style="font-size:13px"><i class="bi bi-inbox" style="font-size:28px;display:block;margin-bottom:8px;opacity:.4"></i>Nenhum insumo consumido neste período.</div>');
}

const canvasSetor = document.getElementById('chartSetor');
if (setores.length > 0) {
  new Chart(canvasSetor, {
    type:'bar',
    data:{ labels: setores.map(d=>d.setor.replace(/^\d+ - /,'')), datasets:[{label:'Pedidos',data:setores.map(d=>d.total),backgroundColor:'#0ea5e9',borderRadius:4}] },
    options:{ maintainAspectRatio:false, indexAxis:'y', plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true,ticks:{precision:0}}} }
  });
} else {
  canvasSetor.style.display = 'none';
  canvasSetor.insertAdjacentHTML('afterend','<div class="text-center text-muted py-5" style="font-size:13px"><i class="bi bi-inbox" style="font-size:28px;display:block;margin-bottom:8px;opacity:.4"></i>Nenhum pedido neste período.</div>');
}
</script>

<?php layoutFooter(); ?>
