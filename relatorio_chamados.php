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
    SUM(status='Concluído') AS concluidos,
    SUM(status IN ('Aberto','Pendente','Em Andamento')) AS abertos,
    SUM(status='Pendente') AS pendentes,
    SUM(nivel='Alta Complexidade') AS alta,
    SUM(nivel='Média Complexidade') AS media,
    SUM(nivel='Baixa Complexidade') AS baixa
    FROM chamados WHERE MONTH(criado_em)=:mes AND YEAR(criado_em)=:ano AND deleted_at IS NULL");
$totais->execute($params);
$totais = $totais->fetch();

$por_resp = $pdo->prepare("SELECT u.nome, COUNT(*) AS total,
    SUM(c.status='Concluído') AS concluidos
    FROM chamados c LEFT JOIN usuarios u ON u.id=c.responsavel_id
    WHERE MONTH(c.criado_em)=:mes AND YEAR(c.criado_em)=:ano AND c.deleted_at IS NULL
    GROUP BY c.responsavel_id ORDER BY total DESC");
$por_resp->execute($params);
$por_resp = $por_resp->fetchAll();

$por_setor = $pdo->prepare("SELECT setor, COUNT(*) AS total
    FROM chamados WHERE MONTH(criado_em)=:mes AND YEAR(criado_em)=:ano AND deleted_at IS NULL
    GROUP BY setor ORDER BY total DESC");
$por_setor->execute($params);
$por_setor = $por_setor->fetchAll();

$top_solic = $pdo->prepare("SELECT solicitante, setor, COUNT(*) AS total
    FROM chamados WHERE MONTH(criado_em)=:mes AND YEAR(criado_em)=:ano AND deleted_at IS NULL
    GROUP BY solicitante, setor ORDER BY total DESC LIMIT 15");
$top_solic->execute($params);
$top_solic = $top_solic->fetchAll();

$por_semana = $pdo->prepare("SELECT semana, COUNT(*) AS total
    FROM chamados WHERE MONTH(criado_em)=:mes AND YEAR(criado_em)=:ano AND deleted_at IS NULL
    GROUP BY semana ORDER BY semana");
$por_semana->execute($params);
$por_semana = $por_semana->fetchAll();

$evolucao = $pdo->query("SELECT MONTH(criado_em) AS m, YEAR(criado_em) AS y, COUNT(*) AS total
    FROM chamados WHERE deleted_at IS NULL
    GROUP BY YEAR(criado_em), MONTH(criado_em)
    ORDER BY y, m")->fetchAll();

// Satisfação (avaliações do solicitante via portal público)
$satisfacao = $pdo->prepare("SELECT COUNT(*) AS total, AVG(a.nota) AS media
    FROM avaliacoes a JOIN chamados c ON c.id = a.chamado_id
    WHERE MONTH(a.criado_em)=:mes AND YEAR(a.criado_em)=:ano");
$satisfacao->execute($params);
$satisfacao = $satisfacao->fetch();

$ultimas_avaliacoes = $pdo->prepare("SELECT c.id, c.numero, c.solicitante, a.nota, a.comentario, a.criado_em
    FROM avaliacoes a JOIN chamados c ON c.id = a.chamado_id
    WHERE MONTH(a.criado_em)=:mes AND YEAR(a.criado_em)=:ano
    ORDER BY a.criado_em DESC LIMIT 10");
$ultimas_avaliacoes->execute($params);
$ultimas_avaliacoes = $ultimas_avaliacoes->fetchAll();

// ── Exportação (CSV / XLSX) ────────────────────────────────
$fmt = $_GET['fmt'] ?? '';
if ($fmt === 'csv' || $fmt === 'xlsx') {
    $stmt = $pdo->prepare("SELECT c.numero, c.descricao, c.setor, c.solicitante,
        COALESCE(u.nome,'A Definir') AS responsavel, c.criado_em, c.status, c.nivel, c.semana,
        c.fechado_em
        FROM chamados c LEFT JOIN usuarios u ON u.id=c.responsavel_id
        WHERE MONTH(c.criado_em)=:mes AND YEAR(c.criado_em)=:ano AND c.deleted_at IS NULL
        ORDER BY c.criado_em DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $nome_mes = $meses_labels[$mes-1];
    $base = "Chamados_{$nome_mes}_{$ano}";

    $cab = ['Nº','Descrição','Setor','Solicitante','Responsável','Abertura','Status','Nível','Semana','Fechado em'];
    $dados = [$cab];
    foreach ($rows as $r) {
        $dados[] = [$r['numero'], $r['descricao'], $r['setor'], $r['solicitante'], $r['responsavel'],
            date('d/m/Y H:i', strtotime($r['criado_em'])), $r['status'], $r['nivel'], $r['semana'],
            $r['fechado_em'] ? date('d/m/Y H:i', strtotime($r['fechado_em'])) : '—'];
    }
    if (count($dados) === 1) $dados[] = ['Nenhum chamado neste período.','','','','','','','','',''];

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
    $xlsx = Shuchkin\SimpleXLSXGen::fromArray($dados, 'Chamados');

    $sheet_setor = [[$bold('Setor'), $bold('Total')]];
    foreach ($por_setor as $s) $sheet_setor[] = [$s['setor'], $s['total']];
    $xlsx->addSheet($sheet_setor, 'Por Setor');

    $sheet_tec = [[$bold('Técnico'), $bold('Total'), $bold('Concluídos')]];
    foreach ($por_resp as $r) $sheet_tec[] = [$r['nome'] ?: 'Sem atribuição', $r['total'], $r['concluidos'] ?? 0];
    $xlsx->addSheet($sheet_tec, 'Por Técnico');

    $sheet_solic = [[$bold('Solicitante'), $bold('Setor'), $bold('Chamados')]];
    foreach ($top_solic as $s) $sheet_solic[] = [$s['solicitante'], $s['setor'], $s['total']];
    $xlsx->addSheet($sheet_solic, 'Top Solicitantes');

    $xlsx->downloadAs("{$base}.xlsx");
    exit;
}

$evolucao_json = json_encode(array_values($evolucao));
$resp_json     = json_encode(array_values($por_resp));
$setor_json    = json_encode(array_values($por_setor));
$semana_json   = json_encode(array_values($por_semana));
$meses_json    = json_encode($meses_labels);

layoutHeader('Relatório de Chamados', 'relatorios');
?>

<?php breadcrumb([['label'=>'Relatórios','href'=>'relatorios.php'],['label'=>'Chamados']]); ?>
<div class="page-header">
  <h1 class="page-title"><i class="bi bi-ticket-detailed-fill me-2 text-primary"></i>Relatório de Chamados</h1>
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

<!-- KPIs -->
<div class="row g-3 mb-4">
  <?php foreach([
    ['Total chamados','total','#0d6efd','bi-ticket-detailed'],
    ['Concluídos','concluidos','#22c55e','bi-check-circle-fill'],
    ['Abertos/Pendentes','abertos','#ef4444','bi-clock-fill'],
    ['Alta complexidade','alta','#f59e0b','bi-exclamation-triangle-fill'],
  ] as [$lbl,$k,$cor,$ico]): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between">
        <div>
          <div class="stat-num" style="color:<?= $cor ?>"><?= (int)$totais[$k] ?></div>
          <div class="stat-label"><?= $lbl ?></div>
        </div>
        <i class="bi <?= $ico ?>" style="font-size:24px;color:<?= $cor ?>;opacity:.3"></i>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between">
        <div>
          <div class="stat-num" style="color:#f59e0b">
            <?= $satisfacao['total'] ? number_format($satisfacao['media'], 1) : '—' ?>
            <?php if ($satisfacao['total']): ?><span style="font-size:14px">★</span><?php endif; ?>
          </div>
          <div class="stat-label">Satisfação (<?= (int)$satisfacao['total'] ?> avalia<?= $satisfacao['total']==1?'ção':'ções' ?>)</div>
        </div>
        <i class="bi bi-star-fill" style="font-size:24px;color:#f59e0b;opacity:.3"></i>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-8">
    <div class="card h-100">
      <div class="card-header">Evolução anual de chamados</div>
      <div class="card-body"><div style="position:relative;height:280px"><canvas id="chartEvolucao"></canvas></div></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-header">Chamados por semana — <?= $meses_labels[$mes-1] ?></div>
      <div class="card-body"><div style="position:relative;height:280px"><canvas id="chartSemana"></canvas></div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card">
      <div class="card-header">Produção por técnico</div>
      <div class="card-body"><div style="position:relative;height:280px"><canvas id="chartResp"></canvas></div></div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card">
      <div class="card-header">Top 10 setores que mais solicitam</div>
      <div class="card-body"><div style="position:relative;height:280px"><canvas id="chartSetor"></canvas></div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-7">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-people-fill text-warning"></i>
        Quem mais abre chamados
        <span class="ms-2 text-muted" style="font-size:11px">(use para planejar treinamentos)</span>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>#</th><th>Solicitante</th><th>Setor</th><th>Chamados</th><th>Análise</th></tr></thead>
          <tbody>
            <?php $max = $top_solic[0]['total'] ?? 1; foreach ($top_solic as $i=>$s): ?>
            <tr>
              <td style="color:#6b7280"><?= $i+1 ?></td>
              <td class="fw-semibold"><?= h($s['solicitante']) ?></td>
              <td style="font-size:12px"><?= h($s['setor']) ?></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div style="height:7px;background:#e5e9f2;border-radius:4px;width:80px">
                    <div style="height:7px;background:#0d6efd;border-radius:4px;width:<?= round($s['total']/$max*100) ?>%"></div>
                  </div>
                  <strong><?= $s['total'] ?></strong>
                </div>
              </td>
              <td style="font-size:11px;color:#6b7280">
                <?php if ($s['total'] >= 8) echo '<span class="text-danger fw-semibold">Alto — treinamento</span>';
                elseif ($s['total'] >= 5) echo '<span class="tx-warning">Moderado</span>';
                else echo '<span class="text-success">Normal</span>'; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$top_solic): ?>
              <tr><td colspan="5" class="text-center text-muted py-3">Nenhum chamado neste período.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-building me-2 text-primary"></i>Chamados por setor</div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Setor</th><th>Chamados</th><th>%</th></tr></thead>
          <tbody>
            <?php $tot = array_sum(array_column($por_setor,'total')); foreach ($por_setor as $s): ?>
            <tr>
              <td style="font-size:13px"><?= h($s['setor']) ?></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div style="height:7px;background:#e5e9f2;border-radius:4px;width:80px">
                    <div style="height:7px;background:#0ea5e9;border-radius:4px;width:<?= $tot?round($s['total']/$tot*100):0 ?>%"></div>
                  </div>
                  <?= $s['total'] ?>
                </div>
              </td>
              <td style="font-size:12px;color:#6b7280"><?= $tot ? round($s['total']/$tot*100,1).'%' : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$por_setor): ?>
              <tr><td colspan="3" class="text-center text-muted py-3">Nenhum dado.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Avaliações do solicitante -->
<div class="card mb-3">
  <div class="card-header"><i class="bi bi-star-fill me-2 text-warning"></i>Últimas avaliações dos solicitantes</div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>Chamado</th><th>Solicitante</th><th>Nota</th><th>Comentário</th><th>Data</th></tr></thead>
      <tbody>
        <?php foreach ($ultimas_avaliacoes as $av): ?>
        <tr>
          <td><a href="chamado.php?id=<?= $av['id'] ?>" class="text-decoration-none"><code><?= h($av['numero']) ?></code></a></td>
          <td><?= h($av['solicitante']) ?></td>
          <td style="white-space:nowrap"><?php for($i=1;$i<=5;$i++): ?><span style="color:<?= $i<=$av['nota']?'#f59e0b':'#e5e9f2' ?>">★</span><?php endfor; ?></td>
          <td style="font-size:12.5px;font-style:italic;color:#6b7280"><?= h($av['comentario'] ?: '—') ?></td>
          <td style="font-size:11px;color:#6b7280;white-space:nowrap"><?= date('d/m H:i', strtotime($av['criado_em'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$ultimas_avaliacoes): ?>
          <tr><td colspan="5" class="text-center text-muted py-3">Nenhuma avaliação neste período.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const evolucao = <?= $evolucao_json ?>;
const resps    = <?= $resp_json ?>;
const setores  = <?= $setor_json ?>;
const semanas  = <?= $semana_json ?>;
const meses    = <?= $meses_json ?>;

const evoLabels = evolucao.map(d => meses[d.m-1]+'/'+String(d.y).slice(-2));
new Chart(document.getElementById('chartEvolucao'), {
  type:'line',
  data:{labels:evoLabels,datasets:[{label:'Chamados',data:evolucao.map(d=>d.total),borderColor:'#0d6efd',backgroundColor:'rgba(13,110,253,.1)',fill:true,tension:.35,pointRadius:5}]},
  options:{maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}
});

new Chart(document.getElementById('chartSemana'), {
  type:'bar',
  data:{labels:semanas.map(d=>d.semana),datasets:[{label:'Chamados',data:semanas.map(d=>d.total),backgroundColor:'#0ea5e9',borderRadius:6}]},
  options:{maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}
});

new Chart(document.getElementById('chartResp'), {
  type:'bar',
  data:{
    labels:resps.map(d=>d.nome||'Sem atribuição'),
    datasets:[
      {label:'Total',data:resps.map(d=>d.total),backgroundColor:'#818cf8',borderRadius:4},
      {label:'Concluídos',data:resps.map(d=>d.concluidos),backgroundColor:'#22c55e',borderRadius:4}
    ]
  },
  options:{maintainAspectRatio:false,indexAxis:'y',plugins:{legend:{position:'bottom'}},scales:{x:{beginAtZero:true,ticks:{precision:0}}}}
});

const topSet = setores.slice(0,10);
new Chart(document.getElementById('chartSetor'), {
  type:'bar',
  data:{
    labels:topSet.map(d=>d.setor.replace(/^\d+ - /,'')),
    datasets:[{label:'Chamados',data:topSet.map(d=>d.total),backgroundColor:'#f59e0b',borderRadius:4}]
  },
  options:{maintainAspectRatio:false,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{precision:0}}}}
});
</script>

<?php layoutFooter(); ?>
