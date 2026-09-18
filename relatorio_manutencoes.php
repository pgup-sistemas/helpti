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
    SUM(tipo='Preventiva') AS preventiva,
    SUM(tipo='Corretiva') AS corretiva,
    SUM(status='Concluída') AS concluidas,
    SUM(status IN ('Pendente','Em Realização')) AS pendentes
    FROM manutencoes_impressoras
    WHERE MONTH(data_manutencao)=:mes AND YEAR(data_manutencao)=:ano");
$totais->execute($params);
$totais = $totais->fetch();

$por_tecnico = $pdo->prepare("SELECT u.nome, COUNT(*) AS total
    FROM manutencoes_impressoras m LEFT JOIN usuarios u ON u.id=m.tecnico_id
    WHERE MONTH(m.data_manutencao)=:mes AND YEAR(m.data_manutencao)=:ano
    GROUP BY m.tecnico_id ORDER BY total DESC");
$por_tecnico->execute($params);
$por_tecnico = $por_tecnico->fetchAll();

$por_impressora = $pdo->prepare("SELECT i.nome, i.setor, COUNT(*) AS total
    FROM manutencoes_impressoras m JOIN impressoras i ON i.id=m.impressora_id
    WHERE MONTH(m.data_manutencao)=:mes AND YEAR(m.data_manutencao)=:ano
    GROUP BY m.impressora_id ORDER BY total DESC LIMIT 10");
$por_impressora->execute($params);
$por_impressora = $por_impressora->fetchAll();

$lista = $pdo->prepare("SELECT m.id, i.nome AS impressora, i.setor, m.tipo, m.status, m.descricao_problema, m.data_manutencao,
    u.nome AS tecnico
    FROM manutencoes_impressoras m
    JOIN impressoras i ON i.id = m.impressora_id
    LEFT JOIN usuarios u ON u.id = m.tecnico_id
    WHERE MONTH(m.data_manutencao)=:mes AND YEAR(m.data_manutencao)=:ano
    ORDER BY m.data_manutencao DESC");
$lista->execute($params);
$lista = $lista->fetchAll();

// ── Exportação (CSV / XLSX) ────────────────────────────────
$fmt = $_GET['fmt'] ?? '';
if ($fmt === 'csv' || $fmt === 'xlsx') {
    $nome_mes = $meses_labels[$mes-1];
    $base = "Manutencoes_{$nome_mes}_{$ano}";

    $cab = ['Impressora','Setor','Tipo','Status','Problema','Técnico','Data'];
    $dados = [$cab];
    foreach ($lista as $r) {
        $dados[] = [$r['impressora'], $r['setor'], $r['tipo'], $r['status'], $r['descricao_problema'], $r['tecnico'] ?: '—', date('d/m/Y', strtotime($r['data_manutencao']))];
    }
    if (count($dados) === 1) $dados[] = ['Nenhuma manutenção neste período.','','','','','',''];

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
    $xlsx = Shuchkin\SimpleXLSXGen::fromArray($dados, 'Manutenções');

    $sheet_tec = [[$bold('Técnico'), $bold('Total')]];
    foreach ($por_tecnico as $t) $sheet_tec[] = [$t['nome'] ?: 'Sem atribuição', $t['total']];
    $xlsx->addSheet($sheet_tec, 'Por Técnico');

    $sheet_imp = [[$bold('Impressora'), $bold('Setor'), $bold('Total')]];
    foreach ($por_impressora as $i) $sheet_imp[] = [$i['nome'], $i['setor'], $i['total']];
    $xlsx->addSheet($sheet_imp, 'Por Impressora');

    $xlsx->downloadAs("{$base}.xlsx");
    exit;
}

$tecnico_json    = json_encode(array_values($por_tecnico));
$impressora_json = json_encode(array_values($por_impressora));

layoutHeader('Relatório de Manutenções', 'relatorios');
?>

<?php breadcrumb([['label'=>'Relatórios','href'=>'relatorios.php'],['label'=>'Manutenções']]); ?>
<div class="page-header">
  <h1 class="page-title"><i class="bi bi-wrench-adjustable-fill me-2 text-primary"></i>Relatório de Manutenções</h1>
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
      <div><div class="stat-num" style="color:var(--brand)"><?= (int)$totais['total'] ?></div><div class="stat-label">Total</div></div>
      <i class="bi bi-wrench-adjustable" style="font-size:24px;color:var(--brand);opacity:.3"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="d-flex justify-content-between">
      <div><div class="stat-num" style="color:#22c55e"><?= (int)$totais['concluidas'] ?></div><div class="stat-label">Concluídas</div></div>
      <i class="bi bi-check-circle-fill" style="font-size:24px;color:#22c55e;opacity:.3"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="d-flex justify-content-between">
      <div><div class="stat-num" style="color:#f59e0b"><?= (int)$totais['pendentes'] ?></div><div class="stat-label">Pendentes</div></div>
      <i class="bi bi-hourglass-split" style="font-size:24px;color:#f59e0b;opacity:.3"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="d-flex justify-content-between">
      <div><div class="stat-num" style="color:#ef4444"><?= (int)$totais['corretiva'] ?></div><div class="stat-label">Corretivas <span class="text-muted" style="font-size:10px">(vs <?= (int)$totais['preventiva'] ?> prev.)</span></div></div>
      <i class="bi bi-exclamation-triangle-fill" style="font-size:24px;color:#ef4444;opacity:.3"></i>
    </div></div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header">Manutenções por técnico</div>
      <div class="card-body"><div style="position:relative;height:280px"><canvas id="chartTecnico"></canvas></div></div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header">Top 10 impressoras com mais manutenções</div>
      <div class="card-body"><div style="position:relative;height:280px"><canvas id="chartImpressora"></canvas></div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-table me-2"></i>Manutenções do período</span>
    <span class="text-muted" style="font-size:12px"><?= count($lista) ?> registro(s)</span>
  </div>
  <div class="table-responsive" style="max-height:420px;overflow-y:auto">
    <table class="table table-sm table-hover mb-0" style="font-size:13px">
      <thead class="table-light" style="position:sticky;top:0">
        <tr><th>Impressora</th><th>Setor</th><th>Tipo</th><th>Status</th><th>Problema</th><th>Técnico</th><th>Data</th></tr>
      </thead>
      <tbody>
        <?php foreach ($lista as $m): ?>
        <tr>
          <td class="fw-semibold"><?= h($m['impressora']) ?></td>
          <td style="font-size:12px"><?= h($m['setor']) ?></td>
          <td><span class="badge bg-<?= $m['tipo']==='Preventiva'?'info text-dark':'warning text-dark' ?>"><?= h($m['tipo']) ?></span></td>
          <td><span class="badge bg-<?= match($m['status']){'Concluída'=>'success','Em Realização'=>'primary',default=>'secondary'} ?>"><?= h($m['status']) ?></span></td>
          <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($m['descricao_problema']) ?>"><?= h($m['descricao_problema']) ?></td>
          <td><?= h($m['tecnico'] ?: '—') ?></td>
          <td style="color:#6b7280"><?= date('d/m/Y', strtotime($m['data_manutencao'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$lista): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma manutenção neste período.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const tecnicos    = <?= $tecnico_json ?>;
const impressoras = <?= $impressora_json ?>;

const canvasTec = document.getElementById('chartTecnico');
if (tecnicos.length > 0) {
  new Chart(canvasTec, {
    type:'bar',
    data:{ labels: tecnicos.map(d=>d.nome||'Sem atribuição'), datasets:[{label:'Manutenções',data:tecnicos.map(d=>d.total),backgroundColor:'#818cf8',borderRadius:4}] },
    options:{ maintainAspectRatio:false, indexAxis:'y', plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true,ticks:{precision:0}}} }
  });
} else {
  canvasTec.style.display = 'none';
  canvasTec.insertAdjacentHTML('afterend','<div class="text-center text-muted py-5" style="font-size:13px"><i class="bi bi-inbox" style="font-size:28px;display:block;margin-bottom:8px;opacity:.4"></i>Nenhum dado neste período.</div>');
}

const canvasImp = document.getElementById('chartImpressora');
if (impressoras.length > 0) {
  new Chart(canvasImp, {
    type:'bar',
    data:{ labels: impressoras.map(d=>d.nome), datasets:[{label:'Manutenções',data:impressoras.map(d=>d.total),backgroundColor:'#f59e0b',borderRadius:4}] },
    options:{ maintainAspectRatio:false, indexAxis:'y', plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true,ticks:{precision:0}}} }
  });
} else {
  canvasImp.style.display = 'none';
  canvasImp.insertAdjacentHTML('afterend','<div class="text-center text-muted py-5" style="font-size:13px"><i class="bi bi-inbox" style="font-size:28px;display:block;margin-bottom:8px;opacity:.4"></i>Nenhum dado neste período.</div>');
}
</script>

<?php layoutFooter(); ?>
