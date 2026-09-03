<?php
require 'db.php';
requireLogin();
require 'layout.php';

$pdo = db();
$u   = usuario();

// Stats chamados
$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(status='Aberto') AS abertos,
        SUM(status='Em Andamento') AS andamento,
        SUM(status='Pendente') AS pendentes,
        SUM(status='Concluído') AS concluidos
    FROM chamados
    WHERE deleted_at IS NULL
")->fetch();

// Stats suprimentos
$stats_sup = $pdo->query("
    SELECT
        SUM(status='Pendente') AS pendentes,
        SUM(status='Aprovado') AS aprovados,
        SUM(status='Entregue') AS entregues
    FROM pedidos_suprimentos
")->fetch();

// Suprimentos pendentes de ação (Pendente ou Aprovado)
$sup_pendentes = $pdo->query("
    SELECT ps.*, COUNT(psi.id) AS total_itens
    FROM pedidos_suprimentos ps
    LEFT JOIN pedidos_suprimentos_itens psi ON psi.pedido_id = ps.id
    WHERE ps.status IN ('Pendente','Aprovado')
    GROUP BY ps.id
    ORDER BY ps.criado_em ASC
    LIMIT 10
")->fetchAll();

// Chamados urgentes: Alta complexidade em aberto há mais de 2h
$urgentes = $pdo->query("SELECT c.*, u.nome AS resp_nome FROM chamados c
    LEFT JOIN usuarios u ON u.id=c.responsavel_id
    WHERE c.status IN ('Aberto','Em Andamento','Pendente')
      AND c.nivel = 'Alta Complexidade'
      AND c.criado_em <= NOW() - INTERVAL 2 HOUR
      AND c.deleted_at IS NULL
    ORDER BY c.criado_em ASC LIMIT 10")->fetchAll();

// Meus chamados em aberto (qualquer perfil pode ser atribuído como responsável)
$st = $pdo->prepare("SELECT c.*, u.nome AS resp_nome FROM chamados c
    LEFT JOIN usuarios u ON u.id=c.responsavel_id
    WHERE c.responsavel_id=? AND c.status != 'Concluído' AND c.deleted_at IS NULL
    ORDER BY c.criado_em DESC LIMIT 15");
$st->execute([$u['id']]);
$meusChamados = $st->fetchAll();

// Chamados sem responsável
$sem_resp = $pdo->query("SELECT COUNT(*) FROM chamados WHERE responsavel_id IS NULL AND status='Aberto' AND deleted_at IS NULL")->fetchColumn();

// Contratos vencendo em 30 dias
$contratos_alerta = $pdo->query("
    SELECT * FROM contratos
    WHERE status='Ativo' AND data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY data_vencimento ASC LIMIT 5
")->fetchAll();

// Termos de empréstimo vencidos ou vencendo em 7 dias
$termos_alerta = $pdo->query("
    SELECT t.*, i.tipo, i.marca, i.modelo
    FROM termos_uso t JOIN inventario i ON i.id=t.inventario_id
    WHERE t.status='Ativo' AND t.data_prevista_devolucao IS NOT NULL
      AND t.data_prevista_devolucao <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY t.data_prevista_devolucao ASC LIMIT 5
")->fetchAll();

// Garantias vencendo em 60 dias
$garantias_alerta = $pdo->query("
    SELECT * FROM inventario
    WHERE garantia_ate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
    ORDER BY garantia_ate ASC LIMIT 5
")->fetchAll();

// Recentes abertos (todos)
$recentes = $pdo->query("SELECT c.*, u.nome AS resp_nome FROM chamados c
    LEFT JOIN usuarios u ON u.id=c.responsavel_id
    WHERE c.status != 'Concluído' AND c.deleted_at IS NULL
    ORDER BY c.criado_em DESC LIMIT 20")->fetchAll();

// Gráfico — últimos 30 dias
$evolucao30 = $pdo->query("
    SELECT DATE(criado_em) AS dia, COUNT(*) AS total
    FROM chamados
    WHERE criado_em >= NOW() - INTERVAL 30 DAY
    GROUP BY DATE(criado_em)
    ORDER BY dia ASC
")->fetchAll();
$chart_labels = json_encode(array_column($evolucao30, 'dia'));
$chart_data   = json_encode(array_column($evolucao30, 'total'));

layoutHeader('Dashboard', 'dashboard');

$hora = (int)date('H');
$saudacao = $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');
$primeiro_nome = explode(' ', trim($u['nome']))[0];
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><i class="bi bi-grid-1x2-fill me-2 text-primary"></i>Dashboard</h1>
    <p class="text-muted mb-0 mt-1" style="font-size:13px"><?= $saudacao ?>, <?= h($primeiro_nome) ?>! Bem-vindo(a) de volta ao <?= APP_NOME ?>.</p>
  </div>
  <a href="novo_chamado.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Novo chamado</a>
</div>

<!-- ── KPIs: Chamados ── -->
<div class="section-label">
  <i class="bi bi-ticket-detailed me-1"></i>Chamados
</div>
<div class="row g-3 mb-3">
  <?php foreach([
    ['Total','total','var(--brand)','bi-ticket-detailed','chamados.php?mes=&ano='],
    ['Abertos','abertos','#0ea5e9','bi-inbox-fill','chamados.php?mes=&ano=&status=Aberto'],
    ['Em andamento','andamento','#f59e0b','bi-arrow-repeat','chamados.php?mes=&ano=&status=Em+Andamento'],
    ['Pendentes','pendentes','#ef4444','bi-exclamation-circle-fill','chamados.php?mes=&ano=&status=Pendente'],
    ['Concluídos','concluidos','#22c55e','bi-check-circle-fill','chamados.php?mes=&ano=&status=Concluído'],
  ] as [$lbl,$key,$cor,$ico,$link]): ?>
  <div class="col-6 col-md-4 col-lg">
    <a href="<?= $link ?>" class="stat-card d-block text-decoration-none" style="color:inherit">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:<?= $cor ?>" data-stat="<?= $key ?>"><?= (int)$stats[$key] ?></div>
          <div class="stat-label"><?= $lbl ?></div>
        </div>
        <i class="bi <?= $ico ?>" style="font-size:22px;color:<?= $cor ?>;opacity:.35"></i>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── KPIs: Suprimentos ── -->
<div class="section-label">
  <i class="bi bi-box-seam me-1"></i>Suprimentos
</div>
<div class="row g-3 mb-4">
  <?php foreach([
    ['Aguardando aprovação','pendentes','#f59e0b','bi-hourglass-split','pedidos_suprimentos.php?status=Pendente'],
    ['Aprovados — a entregar','aprovados','#0ea5e9','bi-check2-square','pedidos_suprimentos.php?status=Aprovado'],
    ['Entregues','entregues','#22c55e','bi-box-seam-fill','pedidos_suprimentos.php?status=Entregue'],
  ] as [$lbl,$key,$cor,$ico,$link]): ?>
  <div class="col-6 col-md-4">
    <a href="<?= $link ?>" class="stat-card d-block text-decoration-none" style="border-top:3px solid <?= $cor ?>;color:inherit">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:<?= $cor ?>"><?= (int)$stats_sup[$key] ?></div>
          <div class="stat-label"><?= $lbl ?></div>
        </div>
        <i class="bi <?= $ico ?>" style="font-size:22px;color:<?= $cor ?>;opacity:.35"></i>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Alertas prioritários ── -->
<?php if ($urgentes): ?>
<div class="card mb-3" style="border-left:4px solid #ef4444">
  <div class="card-header card-header-danger d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-octagon-fill"></i>
    <strong>Atenção — <?= count($urgentes) ?> chamado(s) Alta Complexidade há mais de 2h</strong>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>Nº</th><th>Descrição</th><th>Setor</th><th>Responsável</th><th>Aberto há</th><th class="text-end">Ações</th></tr></thead>
      <tbody>
        <?php foreach ($urgentes as $urg): ?>
        <tr data-href="chamado.php?id=<?= $urg['id'] ?>">
          <td><code style="font-size:12px"><?= h($urg['numero']) ?></code></td>
          <td style="max-width:200px"><div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($urg['descricao']) ?></div></td>
          <td style="font-size:12px"><?= h($urg['setor']) ?></td>
          <td><?= $urg['resp_nome'] ? h($urg['resp_nome']) : '<span class="text-danger fw-semibold">Sem responsável</span>' ?></td>
          <td class="tx-danger" style="font-size:12px;white-space:nowrap">
            <?php $diff = time()-strtotime($urg['criado_em']); $h=floor($diff/3600); $m=floor(($diff%3600)/60); echo "{$h}h {$m}min"; ?>
          </td>
          <td class="text-end"><a href="chamado.php?id=<?= $urg['id'] ?>" class="btn btn-outline-primary btn-xs">Atender</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($sem_resp > 0): ?>
<div class="alert alert-warning d-flex align-items-center mb-3" style="font-size:13.5px">
  <i class="bi bi-exclamation-triangle-fill me-2"></i>
  <div><strong><?= $sem_resp ?> chamado(s)</strong> abertos sem responsável.
    <a href="chamados.php?status=Aberto&resp=0" class="ms-2 fw-semibold">Atribuir agora →</a>
  </div>
</div>
<?php endif; ?>

<?php if ($termos_alerta): ?>
<div class="card mb-3" style="border-left:4px solid #E63946">
  <div class="card-header card-header-danger d-flex align-items-center gap-2">
    <i class="bi bi-person-exclamation-fill"></i>
    <strong>Empréstimos vencidos ou a vencer (7 dias)</strong>
  </div>
  <div class="card-body p-0">
    <?php foreach ($termos_alerta as $tr):
      $prev = new DateTime($tr['data_prevista_devolucao']);
      $diff = (int)(new DateTime())->diff($prev)->format('%r%a');
    ?>
    <div class="d-flex align-items-center justify-content-between px-4 py-2 border-bottom" style="gap:12px">
      <div>
        <i class="bi bi-laptop me-2" style="color:#E63946"></i>
        <strong><?= h("{$tr['marca']} {$tr['modelo']}") ?></strong>
        <span class="text-muted" style="font-size:12px"> · <?= h($tr['tipo']) ?></span>
        <span class="text-muted" style="font-size:12px"> · <i class="bi bi-person me-1"></i><?= h($tr['responsavel_nome']) ?><?= $tr['setor'] ? ' · '.h($tr['setor']) : '' ?></span>
      </div>
      <div class="<?= $diff<0 ? 'tx-danger' : 'tx-warning' ?>" style="white-space:nowrap;font-size:12px">
        <?php if ($diff < 0): ?>
          <i class="bi bi-exclamation-triangle-fill me-1"></i>Vencido há <strong><?= abs($diff) ?></strong> dia(s)
        <?php else: ?>
          <i class="bi bi-clock me-1"></i>Vence em <strong><?= $diff ?></strong> dia(s) — <?= $prev->format('d/m/Y') ?>
        <?php endif; ?>
      </div>
      <a href="termos.php" class="btn btn-outline-primary btn-xs flex-shrink-0">Devolver</a>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($contratos_alerta || $garantias_alerta): ?>
<div class="card mb-3" style="border-left:4px solid #f59e0b">
  <div class="card-header card-header-warning d-flex align-items-center gap-2">
    <i class="bi bi-bell-fill"></i>
    <strong>Lembretes — contratos e garantias</strong>
  </div>
  <div class="card-body p-0">
    <?php foreach ($contratos_alerta as $ct):
      $dias = (int)ceil((strtotime($ct['data_vencimento']) - time()) / 86400);
    ?>
    <div class="d-flex align-items-center justify-content-between px-4 py-2 border-bottom" style="gap:12px">
      <div><i class="bi bi-file-earmark-check me-2 text-warning"></i>
        <strong><?= h($ct['nome']) ?></strong>
        <span class="text-muted" style="font-size:12px"> · <?= h($ct['tipo']) ?> · <?= h($ct['fornecedor']) ?></span>
      </div>
      <div class="tx-warning" style="white-space:nowrap;font-size:12px"><i class="bi bi-clock me-1"></i>Vence em <strong><?= $dias ?></strong> dia(s) — <?= date('d/m/Y', strtotime($ct['data_vencimento'])) ?></div>
      <a href="contratos.php?action=editar&id=<?= $ct['id'] ?>" class="btn btn-outline-primary btn-xs flex-shrink-0">Renovar</a>
    </div>
    <?php endforeach; ?>
    <?php foreach ($garantias_alerta as $ga):
      $dias = (int)ceil((strtotime($ga['garantia_ate']) - time()) / 86400);
    ?>
    <div class="d-flex align-items-center justify-content-between px-4 py-2 border-bottom" style="gap:12px">
      <div><i class="bi bi-shield-exclamation me-2 text-warning"></i>
        <strong><?= h("{$ga['marca']} {$ga['modelo']}") ?></strong>
        <span class="text-muted" style="font-size:12px"> · <?= h($ga['tipo']) ?> · S/N: <?= h($ga['numero_serie']) ?></span>
      </div>
      <div class="tx-warning" style="white-space:nowrap;font-size:12px"><i class="bi bi-shield me-1"></i>Garantia em <strong><?= $dias ?></strong> dia(s) — <?= date('d/m/Y', strtotime($ga['garantia_ate'])) ?></div>
      <a href="inventario.php?action=editar&id=<?= $ga['id'] ?>" class="btn btn-outline-primary btn-xs flex-shrink-0">Ver</a>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Suprimentos pendentes de ação ── -->
<?php if ($sup_pendentes): ?>
<div class="card mb-4" style="border-left:4px solid #f59e0b">
  <div class="card-header card-header-warning d-flex justify-content-between align-items-center">
    <span><i class="bi bi-box-seam-fill me-2"></i>Pedidos de suprimentos aguardando ação</span>
    <a href="pedidos_suprimentos.php" class="btn btn-outline-secondary btn-xs">Ver todos</a>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Código</th><th>Setor</th><th>Solicitante</th><th>Itens</th><th>Status</th><th>Data</th><th class="text-end">Ações</th></tr></thead>
      <tbody>
        <?php foreach ($sup_pendentes as $sp): ?>
        <tr data-href="pedidos_suprimentos.php?status=<?= $sp['status']==='Pendente'?'Pendente':'Aprovado' ?>">
          <td><code style="font-size:12px"><?= h($sp['numero']) ?></code></td>
          <td style="font-size:12px"><?= h($sp['setor']) ?></td>
          <td><?= h($sp['solicitante']) ?></td>
          <td style="text-align:center"><span class="badge bg-light text-dark border"><?= $sp['total_itens'] ?></span></td>
          <td>
            <?php if ($sp['status']==='Pendente'): ?>
              <span class="badge-pending">Pendente</span>
            <?php else: ?>
              <span class="badge-approved">Aprovado</span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px;white-space:nowrap"><?= date('d/m H:i', strtotime($sp['criado_em'])) ?></td>
          <td class="text-end"><a href="pedidos_suprimentos.php" class="btn btn-outline-primary btn-xs">Ação</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Meus chamados (qualquer perfil atribuído como responsável) ── -->
<?php if (!empty($meusChamados)): ?>
<div class="card mb-4">
  <div class="card-header card-header-success d-flex justify-content-between align-items-center">
    <span><i class="bi bi-person-check-fill me-2"></i>Meus chamados em aberto</span>
    <span class="badge bg-success"><?= count($meusChamados) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Nº</th><th>Descrição</th><th>Setor</th><th>Status</th><th>Data</th><th class="text-end">Ações</th></tr></thead>
      <tbody>
        <?php foreach ($meusChamados as $c): ?>
        <tr data-href="chamado.php?id=<?= $c['id'] ?>">
          <td><code style="font-size:12px"><?= h($c['numero']) ?></code></td>
          <td style="max-width:220px"><div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($c['descricao']) ?>"><?= h($c['descricao']) ?></div></td>
          <td style="font-size:12px"><?= h($c['setor']) ?></td>
          <td><?= badgeStatus($c['status']) ?></td>
          <td style="font-size:12px;white-space:nowrap"><?= date('d/m H:i', strtotime($c['criado_em'])) ?></td>
          <td class="text-end"><a href="chamado.php?id=<?= $c['id'] ?>" class="btn btn-outline-primary btn-xs">Abrir</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Gráfico ── -->
<div class="card mb-4">
  <div class="card-header"><span><i class="bi bi-graph-up me-2 text-primary"></i>Chamados — últimos 30 dias</span></div>
  <div class="card-body" style="height:180px"><canvas id="chartDash"></canvas></div>
</div>

<!-- ── Chamados em aberto / andamento ── -->
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-task me-2 text-primary"></i>Chamados abertos / em andamento</span>
    <a href="chamados.php" class="btn btn-outline-secondary btn-xs">Ver todos</a>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr><th>Nº</th><th>Descrição</th><th>Setor</th><th>Solicitante</th><th>Responsável</th><th>Status</th><th>Data</th><th class="text-end">Ações</th></tr>
      </thead>
      <tbody>
        <?php foreach ($recentes as $c): ?>
        <tr data-href="chamado.php?id=<?= $c['id'] ?>">
          <td><code style="font-size:12px"><?= h($c['numero']) ?></code></td>
          <td style="max-width:200px"><div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($c['descricao']) ?>"><?= h($c['descricao']) ?></div></td>
          <td style="font-size:12px"><?= h($c['setor']) ?></td>
          <td><?= h($c['solicitante']) ?></td>
          <td><?= $c['resp_nome'] ? h($c['resp_nome']) : '<span class="text-danger">—</span>' ?></td>
          <td><?= badgeStatus($c['status']) ?></td>
          <td style="font-size:12px;white-space:nowrap"><?= date('d/m H:i', strtotime($c['criado_em'])) ?></td>
          <td class="text-end"><a href="chamado.php?id=<?= $c['id'] ?>" class="btn btn-outline-primary btn-xs">Abrir</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$recentes): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">Nenhum chamado aberto no momento 🎉</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Auto-atualização dos KPIs a cada 60 segundos (sem reload da página)
(function pollStats() {
  setTimeout(function() {
    fetch('api_stats.php')
      .then(r => r.ok ? r.json() : null)
      .then(data => {
        if (!data) return;
        document.querySelectorAll('[data-stat]').forEach(el => {
          const k = el.dataset.stat;
          if (data[k] !== undefined) el.textContent = data[k];
        });
      })
      .catch(() => {})
      .finally(pollStats);
  }, 60000);
})();
</script>
<script>
(function() {
  const dark = document.documentElement.getAttribute('data-theme') === 'dark';
  const gridColor  = dark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.06)';
  const tickColor  = dark ? '#94a3b8' : '#6c757d';
  const lineColor  = dark ? '#457B9D' : '#1D3557';
  const fillColor  = dark ? 'rgba(69,123,157,.15)' : 'rgba(29,53,87,.08)';

  new Chart(document.getElementById('chartDash'), {
    type: 'line',
    data: {
      labels: <?= $chart_labels ?>,
      datasets: [{
        data: <?= $chart_data ?>,
        borderColor: lineColor,
        backgroundColor: fillColor,
        fill: true, tension: .35, pointRadius: 3,
        pointBackgroundColor: lineColor
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { color: gridColor }, ticks: { color: tickColor, font: { size: 11 }, maxTicksLimit: 10 } },
        y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: tickColor, precision: 0, font: { size: 11 } } }
      }
    }
  });
})();
</script>

<?php layoutFooter(); ?>
