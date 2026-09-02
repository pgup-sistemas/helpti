<?php
require 'db.php';
requireLogin();
require 'layout.php';

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

// Buscar impressora
$stmt = $pdo->prepare("SELECT * FROM impressoras WHERE id = ?");
$stmt->execute([$id]);
$imp = $stmt->fetch();

if (!$imp) {
    flash("Equipamento não encontrado.", "danger");
    header("Location: impressoras.php");
    exit;
}

// Snapshots SNMP — últimos 12 meses
$tabela_snap_existe = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'impressoras_snapshot'"
)->fetchColumn();

$snapshots_mensais = [];
$ultimo_snap = null;
if ($tabela_snap_existe) {
    $st_snap = $pdo->prepare("
        SELECT
            DATE_FORMAT(coletado_em, '%Y-%m')       AS mes,
            DATE_FORMAT(MIN(coletado_em), '%m/%Y')  AS mes_label,
            MAX(paginas_total)                      AS pag_fim,
            MIN(paginas_total)                      AS pag_inicio,
            MAX(paginas_total) - MIN(paginas_total) AS paginas_mes,
            ROUND(AVG(toner_preto_pct))             AS toner_preto_avg,
            ROUND(AVG(toner_ciano_pct))             AS toner_ciano_avg,
            ROUND(AVG(toner_magenta_pct))           AS toner_magenta_avg,
            ROUND(AVG(toner_amarelo_pct))           AS toner_amarelo_avg
        FROM impressoras_snapshot
        WHERE impressora_id = ?
          AND coletado_em >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(coletado_em, '%Y-%m')
        ORDER BY mes ASC
    ");
    $st_snap->execute([$id]);
    $snapshots_mensais = $st_snap->fetchAll();

    $st_ultimo = $pdo->prepare("
        SELECT * FROM impressoras_snapshot
        WHERE impressora_id = ?
        ORDER BY coletado_em DESC LIMIT 1
    ");
    $st_ultimo->execute([$id]);
    $ultimo_snap = $st_ultimo->fetch();
}

// Buscar histórico de manutenções
$stmt_manut = $pdo->prepare("
    SELECT m.*, u.nome AS tecnico_nome 
    FROM manutencoes_impressoras m
    LEFT JOIN usuarios u ON u.id = m.tecnico_id
    WHERE m.impressora_id = ?
    ORDER BY m.data_manutencao DESC, m.criado_em DESC
");
$stmt_manut->execute([$id]);
$manutencoes = $stmt_manut->fetchAll();

// Buscar pedidos de suprimentos
$stmt_supr = $pdo->prepare("
    SELECT * FROM pedidos_suprimentos
    WHERE impressora_id = ?
    ORDER BY criado_em DESC
");
$stmt_supr->execute([$id]);
$suprimentos = $stmt_supr->fetchAll();

// Itens de cada pedido (tipo + quantidade)
$itens_por_pedido = [];
if ($suprimentos) {
    $ids_pedidos = array_column($suprimentos, 'id');
    $in = str_repeat('?,', count($ids_pedidos) - 1) . '?';
    $st_itens = $pdo->prepare("
        SELECT pi.pedido_id, pi.quantidade, pi.descricao_livre, ts.nome AS tipo_nome
        FROM pedidos_suprimentos_itens pi
        LEFT JOIN tipos_suprimentos ts ON ts.id = pi.tipo_suprimento_id
        WHERE pi.pedido_id IN ($in)
    ");
    $st_itens->execute($ids_pedidos);
    foreach ($st_itens->fetchAll() as $item) {
        $itens_por_pedido[$item['pedido_id']][] = $item;
    }
}

layoutHeader($imp['nome'], 'impressoras');

function badgeStatusImp(string $s): string {
    $map = [
        'Ativa' => 'badge-concluido',
        'Em Manutenção' => 'badge-andamento',
        'Inativa' => 'badge-pendente'
    ];
    $cls = $map[$s] ?? 'bg-secondary text-white';
    return "<span class=\"badge $cls\">$s</span>";
}

function badgeTipoManut(string $t): string {
    return $t === 'Preventiva' ? '<span class="badge bg-info text-white">Preventiva</span>' : '<span class="badge bg-warning text-dark">Corretiva</span>';
}

function badgeStatusManut(string $s): string {
    $map = [
        'Concluída' => 'badge-concluido',
        'Em Realização' => 'badge-andamento',
        'Pendente' => 'badge-pendente'
    ];
    $cls = $map[$s] ?? 'bg-secondary text-white';
    return "<span class=\"badge $cls\">$s</span>";
}

function badgeStatusSuprimento(string $s): string {
    $map = [
        'Pendente' => 'badge-pendente',
        'Aprovado' => 'badge-andamento',
        'Entregue' => 'badge-concluido',
        'Cancelado' => 'bg-secondary text-white'
    ];
    $cls = $map[$s] ?? 'bg-secondary text-white';
    return "<span class=\"badge $cls\">$s</span>";
}
?>

<?php breadcrumb([['label'=>'Impressoras','href'=>'impressoras.php'],['label'=>h($imp['nome'])]]); ?>

<div class="page-header mt-1">
  <h1 class="page-title">
    <i class="bi bi-printer-fill me-2 text-primary"></i><?= h($imp['nome']) ?>
  </h1>
  <div class="d-flex gap-2">
    <a href="editar_impressora.php?id=<?= $imp['id'] ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-pencil me-1"></i>Editar Equipamento
    </a>
    <a href="nova_manutencao.php?impressora_id=<?= $imp['id'] ?>" class="btn btn-warning btn-sm text-dark">
      <i class="bi bi-wrench-adjustable me-1"></i>Registrar Manutenção
    </a>
    <a href="pedir_suprimento.php?impressora_id=<?= $imp['id'] ?>" class="btn btn-primary btn-sm">
      <i class="bi bi-box-seam me-1"></i>Pedir Suprimento
    </a>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- Detalhes Técnicos -->
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-header fw-bold"><i class="bi bi-info-circle-fill me-2 text-primary"></i>Especificações Técnicas</div>
      <div class="card-body">
        <ul class="list-group list-group-flush" style="font-size:13.5px">
          <li class="list-group-item px-0 d-flex justify-content-between">
            <span class="text-muted">Status:</span>
            <span><?= badgeStatusImp($imp['status']) ?></span>
          </li>
          <li class="list-group-item px-0 d-flex justify-content-between">
            <span class="text-muted">Marca / Modelo:</span>
            <span class="fw-semibold text-dark"><?= h($imp['marca_modelo']) ?></span>
          </li>
          <li class="list-group-item px-0 d-flex justify-content-between">
            <span class="text-muted">Setor:</span>
            <span class="fw-semibold text-dark"><?= h($imp['setor']) ?></span>
          </li>
          <li class="list-group-item px-0 d-flex justify-content-between">
            <span class="text-muted">Modelo de Toner:</span>
            <span class="fw-semibold text-primary"><code><?= h($imp['modelo_toner'] ?: '—') ?></code></span>
          </li>
          <li class="list-group-item px-0 d-flex justify-content-between">
            <span class="text-muted">Endereço IP:</span>
            <span>
              <?php if ($imp['ip']): ?>
                <a href="http://<?= h($imp['ip']) ?>" target="_blank" class="text-decoration-none" title="Acessar painel web">
                  <i class="bi bi-link-45deg me-1"></i><?= h($imp['ip']) ?>
                </a>
              <?php else: ?>
                <span class="text-muted">Não configurado</span>
              <?php endif; ?>
            </span>
          </li>
          <li class="list-group-item px-0 d-flex justify-content-between">
            <span class="text-muted">Número de Série:</span>
            <span class="text-muted"><?= h($imp['numero_serie'] ?: '—') ?></span>
          </li>
          <li class="list-group-item px-0 d-flex justify-content-between">
            <span class="text-muted">Cadastrado em:</span>
            <span class="text-muted"><?= date('d/m/Y H:i', strtotime($imp['criado_em'])) ?></span>
          </li>
        </ul>
      </div>
    </div>
  </div>

  <!-- Abas de Histórico -->
  <div class="col-md-8">
    <div class="card h-100">
      <div class="card-header p-0">
        <ul class="nav nav-tabs border-0" id="printerTab" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active border-0 px-4 py-3 fw-semibold" style="font-size:13.5px" id="manutencao-tab" data-bs-toggle="tab" data-bs-target="#manutencao" type="button" role="tab" aria-controls="manutencao" aria-selected="true">
              <i class="bi bi-wrench-adjustable me-2 text-warning"></i>Histórico de Manutenções
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link border-0 px-4 py-3 fw-semibold" style="font-size:13.5px" id="suprimentos-tab" data-bs-toggle="tab" data-bs-target="#suprimentos" type="button" role="tab" aria-controls="suprimentos" aria-selected="false">
              <i class="bi bi-box-seam me-2 text-primary"></i>Pedidos de Suprimentos
            </button>
          </li>
        </ul>
      </div>
      
      <div class="card-body">
        <div class="tab-content" id="printerTabContent">
          <!-- Aba Manutenção -->
          <div class="tab-pane fade show active" id="manutencao" role="tabpanel" aria-labelledby="manutencao-tab">
            <div class="table-responsive">
              <table class="table table-hover table-sm align-middle" style="font-size:13px">
                <thead>
                  <tr class="table-light">
                    <th>Data</th>
                    <th>Tipo</th>
                    <th>Técnico</th>
                    <th>Problema</th>
                    <th>Status</th>
                    <th>Ações</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($manutencoes as $m): ?>
                    <tr>
                      <td style="white-space:nowrap"><?= date('d/m/Y', strtotime($m['data_manutencao'])) ?></td>
                      <td><?= badgeTipoManut($m['tipo']) ?></td>
                      <td><?= h($m['tecnico_nome'] ?? 'Sistema') ?></td>
                      <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= h($m['descricao_problema']) ?>">
                        <?= h($m['descricao_problema']) ?>
                      </td>
                      <td><?= badgeStatusManut($m['status']) ?></td>
                      <td>
                        <a href="editar_manutencao.php?id=<?= $m['id'] ?>" class="btn btn-xs btn-outline-secondary" title="Editar registro de manutenção">
                          <i class="bi bi-pencil"></i>
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$manutencoes): ?>
                    <tr>
                      <td colspan="6" class="text-center text-muted py-4">Nenhum registro de manutenção para este equipamento.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Aba Suprimentos -->
          <div class="tab-pane fade" id="suprimentos" role="tabpanel" aria-labelledby="suprimentos-tab">
            <div class="table-responsive">
              <table class="table table-hover table-sm align-middle" style="font-size:13px">
                <thead>
                  <tr class="table-light">
                    <th>Data</th>
                    <th>Pedido</th>
                    <th>Suprimento</th>
                    <th>Qtd</th>
                    <th>Solicitante</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($suprimentos as $s):
                    $itens_s = $itens_por_pedido[$s['id']] ?? [];
                  ?>
                    <tr>
                      <td style="white-space:nowrap"><?= date('d/m H:i', strtotime($s['criado_em'])) ?></td>
                      <td><code><?= h($s['numero']) ?></code></td>
                      <td>
                        <?php if ($itens_s): ?>
                          <?php foreach ($itens_s as $it): ?>
                            <div><strong><?= h($it['tipo_nome'] ?: $it['descricao_livre']) ?></strong></div>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <span class="text-muted">—</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php foreach ($itens_s as $it): ?>
                          <div><?= (int)$it['quantidade'] ?></div>
                        <?php endforeach; ?>
                        <?php if (!$itens_s): ?><span class="text-muted">—</span><?php endif; ?>
                      </td>
                      <td><?= h($s['solicitante']) ?></td>
                      <td><?= badgeStatusSuprimento($s['status']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$suprimentos): ?>
                    <tr>
                      <td colspan="6" class="text-center text-muted py-4">Nenhum pedido de suprimento feito para esta impressora.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
// ── Seção de Monitoramento SNMP ─────────────────────────────
if ($tabela_snap_existe):
?>
<div class="card mb-4">
  <div class="card-header fw-bold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-graph-up me-2 text-success"></i>Monitoramento SNMP — Páginas &amp; Toner</span>
    <?php if ($ultimo_snap): ?>
      <small class="text-muted fw-normal">Última leitura: <?= date('d/m/Y H:i', strtotime($ultimo_snap['coletado_em'])) ?></small>
    <?php endif; ?>
  </div>
  <div class="card-body">

    <?php if (!$imp['ip']): ?>
      <div class="alert alert-warning mb-0">
        <i class="bi bi-exclamation-triangle me-2"></i>
        IP não configurado nesta impressora. <a href="editar_impressora.php?id=<?= $imp['id'] ?>" class="alert-link">Cadastre o IP</a> para ativar o monitoramento automático via SNMP.
      </div>

    <?php elseif (!$snapshots_mensais): ?>
      <div class="text-center text-muted py-4">
        <i class="bi bi-wifi-off fs-3 d-block mb-2"></i>
        Nenhum dado coletado ainda para esta impressora.<br>
        <small>Execute o cron <code>snmp_coletar.php</code> para iniciar o monitoramento.</small>
      </div>

    <?php else: ?>

      <!-- Cards de resumo -->
      <?php if ($ultimo_snap): ?>
      <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
          <div class="card border-0 bg-light text-center py-2">
            <div class="fw-bold fs-4 text-primary"><?= $ultimo_snap['paginas_total'] !== null ? number_format($ultimo_snap['paginas_total'], 0, ',', '.') : '—' ?></div>
            <small class="text-muted">Páginas total</small>
          </div>
        </div>
        <?php
        $mes_atual = end($snapshots_mensais);
        reset($snapshots_mensais);
        $pag_mes = $mes_atual ? (int)$mes_atual['paginas_mes'] : 0;
        ?>
        <div class="col-6 col-md-3">
          <div class="card border-0 bg-light text-center py-2">
            <div class="fw-bold fs-4 text-success"><?= number_format($pag_mes, 0, ',', '.') ?></div>
            <small class="text-muted">Páginas este mês</small>
          </div>
        </div>
        <?php if ($ultimo_snap['toner_preto_pct'] !== null): ?>
        <div class="col-6 col-md-3">
          <div class="card border-0 bg-light text-center py-2">
            <?php $tp = (int)$ultimo_snap['toner_preto_pct']; $cor_t = $tp <= 15 ? 'danger' : ($tp <= 30 ? 'warning' : 'success'); ?>
            <div class="fw-bold fs-4 text-<?= $cor_t ?>"><?= $tp ?>%</div>
            <small class="text-muted">Toner Preto</small>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($ultimo_snap['toner_ciano_pct'] !== null): ?>
        <div class="col-6 col-md-3">
          <div class="card border-0 bg-light text-center py-2">
            <?php $tc = (int)$ultimo_snap['toner_ciano_pct']; $cor_c = $tc <= 15 ? 'danger' : ($tc <= 30 ? 'warning' : 'success'); ?>
            <div class="fw-bold fs-4 text-<?= $cor_c ?>"><?= $tc ?>%</div>
            <small class="text-muted">Toners Cor</small>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Gráfico de páginas mensais -->
      <div class="row g-4">
        <div class="col-md-7">
          <h6 class="text-muted mb-2" style="font-size:12px;text-transform:uppercase;letter-spacing:.05em">Páginas impressas por mês</h6>
          <canvas id="grafico-paginas" height="160"></canvas>
        </div>
        <div class="col-md-5">
          <h6 class="text-muted mb-2" style="font-size:12px;text-transform:uppercase;letter-spacing:.05em">Nível médio de toner (%)</h6>
          <canvas id="grafico-toner" height="160"></canvas>
        </div>
      </div>

      <!-- Tabela mensal detalhada -->
      <div class="table-responsive mt-4">
        <table class="table table-sm table-hover align-middle" style="font-size:13px">
          <thead class="table-light">
            <tr>
              <th>Mês</th>
              <th class="text-end">Páginas no mês</th>
              <th class="text-end">Total acumulado</th>
              <th class="text-center">Toner Preto</th>
              <th class="text-center">Ciano</th>
              <th class="text-center">Magenta</th>
              <th class="text-center">Amarelo</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_reverse($snapshots_mensais) as $s): ?>
            <tr>
              <td class="fw-semibold"><?= h($s['mes_label']) ?></td>
              <td class="text-end"><?= number_format((int)$s['paginas_mes'], 0, ',', '.') ?></td>
              <td class="text-end text-muted"><?= $s['pag_fim'] !== null ? number_format((int)$s['pag_fim'], 0, ',', '.') : '—' ?></td>
              <?php
              foreach (['toner_preto_avg','toner_ciano_avg','toner_magenta_avg','toner_amarelo_avg'] as $col):
                  $v = $s[$col] !== null ? (int)$s[$col] : null;
                  $badge = $v === null ? '—' : ($v <= 15 ? "<span class=\"badge bg-danger\">{$v}%</span>" : ($v <= 30 ? "<span class=\"badge bg-warning text-dark\">{$v}%</span>" : "<span class=\"badge bg-success\">{$v}%</span>"));
              ?>
              <td class="text-center"><?= $badge ?></td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function() {
    var meses  = <?= json_encode(array_column($snapshots_mensais, 'mes_label')) ?>;
    var paginas = <?= json_encode(array_map(fn($s) => (int)$s['paginas_mes'], $snapshots_mensais)) ?>;
    var preto   = <?= json_encode(array_map(fn($s) => $s['toner_preto_avg'] !== null ? (int)$s['toner_preto_avg'] : null, $snapshots_mensais)) ?>;
    var ciano   = <?= json_encode(array_map(fn($s) => $s['toner_ciano_avg'] !== null ? (int)$s['toner_ciano_avg'] : null, $snapshots_mensais)) ?>;
    var magenta = <?= json_encode(array_map(fn($s) => $s['toner_magenta_avg'] !== null ? (int)$s['toner_magenta_avg'] : null, $snapshots_mensais)) ?>;
    var amarelo = <?= json_encode(array_map(fn($s) => $s['toner_amarelo_avg'] !== null ? (int)$s['toner_amarelo_avg'] : null, $snapshots_mensais)) ?>;

    var temCor = ciano.some(v => v !== null);

    if (document.getElementById('grafico-paginas') && meses.length) {
        new Chart(document.getElementById('grafico-paginas'), {
            type: 'bar',
            data: {
                labels: meses,
                datasets: [{
                    label: 'Páginas/mês',
                    data: paginas,
                    backgroundColor: 'rgba(13,110,253,0.7)',
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }

    if (document.getElementById('grafico-toner') && meses.length) {
        var datasets = [{
            label: 'Preto',
            data: preto,
            borderColor: '#333',
            backgroundColor: 'rgba(51,51,51,0.1)',
            tension: 0.3,
            fill: false,
        }];
        if (temCor) {
            datasets.push(
                { label: 'Ciano',   data: ciano,   borderColor: '#0dcaf0', backgroundColor: 'rgba(13,202,240,0.1)', tension:0.3, fill:false },
                { label: 'Magenta', data: magenta, borderColor: '#d63384', backgroundColor: 'rgba(214,51,132,0.1)', tension:0.3, fill:false },
                { label: 'Amarelo', data: amarelo, borderColor: '#ffc107', backgroundColor: 'rgba(255,193,7,0.1)',   tension:0.3, fill:false }
            );
        }
        new Chart(document.getElementById('grafico-toner'), {
            type: 'line',
            data: { labels: meses, datasets: datasets },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
                scales: { y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } } }
            }
        });
    }
})();
</script>

<?php endif; // tabela_snap_existe ?>

<?php layoutFooter(); ?>
