<?php
require 'db.php';
requireLogin();
require 'layout.php';
require_once 'sync_inventario.php';
require_once 'impressoras_helpers.php';

// Sincroniza impressoras a partir do inventário
if (isset($_GET['sync_impressoras']) && ($u['perfil'] ?? '') !== 'tecnico') {
    $r = sync_impressoras_from_inventario();
    flash('success', "Sincronização concluída: {$r['criadas']} criada(s), {$r['atualizadas']} atualizada(s).");
    header('Location: impressoras.php'); exit;
}

$pdo = db();
$u = usuario();

// Filtros
$filtro_setor = $_GET['setor'] ?? '';
$filtro_status = $_GET['status'] ?? '';
$busca_texto = trim($_GET['busca'] ?? '');

$where = [];
$params = [];

if ($filtro_setor) {
    $where[] = "setor = :setor";
    $params['setor'] = $filtro_setor;
}

if ($filtro_status) {
    $where[] = "status = :status";
    $params['status'] = $filtro_status;
}

if ($busca_texto) {
    $b = '%' . $busca_texto . '%';
    $where[] = "(nome LIKE :b1 OR marca_modelo LIKE :b2 OR numero_serie LIKE :b3 OR ip LIKE :b4 OR modelo_toner LIKE :b5 OR setor LIKE :b6)";
    $params['b1'] = $b;
    $params['b2'] = $b;
    $params['b3'] = $b;
    $params['b4'] = $b;
    $params['b5'] = $b;
    $params['b6'] = $b;
}

// Stats
$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(status='Ativa') AS ativas,
        SUM(status='Em Manutenção') AS manutencao,
        SUM(status='Inativa') AS inativas
    FROM impressoras
")->fetch();

// Paginação
$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$limite = 20;
$offset = ($pagina - 1) * $limite;

$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// Total para paginação
$st_count = $pdo->prepare("SELECT COUNT(*) FROM impressoras $where_sql");
$st_count->execute($params);
$total_registros = (int)$st_count->fetchColumn();
$total_paginas = max(1, ceil($total_registros / $limite));

// Consulta principal com LIMIT
$sql = "SELECT * FROM impressoras $where_sql ORDER BY nome ASC LIMIT $limite OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$impressoras = $stmt->fetchAll();

// Último snapshot de toner por impressora
$snmp_map = $impressoras ? snmp_ultimo_snapshot($pdo, array_column($impressoras, 'id')) : [];

layoutHeader('Painel de Impressoras', 'impressoras');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-printer-fill me-2 text-primary"></i>Controle de Impressoras</h1>
  <div class="d-flex gap-2">
    <?php if (($u['perfil'] ?? '') !== 'tecnico'): ?>
    <a href="?sync_impressoras=1" class="btn btn-outline-success btn-sm" title="Sincroniza impressoras cadastradas no inventário"><i class="bi bi-arrow-repeat me-1"></i>Sincronizar do Inventário</a>
    <?php endif; ?>
    <a href="nova_impressora.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Cadastrar Impressora</a>
  </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:var(--brand)"><?= (int)$stats['total'] ?></div>
          <div class="stat-label">Total Cadastrado</div>
        </div>
        <i class="bi bi-printer" style="font-size:22px;color:var(--brand);opacity:.35"></i>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:#22c55e"><?= (int)$stats['ativas'] ?></div>
          <div class="stat-label">Ativas / Operantes</div>
        </div>
        <i class="bi bi-check-circle" style="font-size:22px;color:#22c55e;opacity:.35"></i>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:#f59e0b"><?= (int)$stats['manutencao'] ?></div>
          <div class="stat-label">Em Manutenção</div>
        </div>
        <i class="bi bi-wrench-adjustable" style="font-size:22px;color:#f59e0b;opacity:.35"></i>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:#ef4444"><?= (int)$stats['inativas'] ?></div>
          <div class="stat-label">Inativas / Backup</div>
        </div>
        <i class="bi bi-dash-circle" style="font-size:22px;color:#ef4444;opacity:.35"></i>
      </div>
    </div>
  </div>
</div>

<!-- Filtros -->
<div class="card mb-4">
  <div class="card-header"><i class="bi bi-funnel me-2"></i>Filtrar Equipamentos</div>
  <div class="card-body py-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label fw-semibold mb-1" style="font-size:12px">Setor</label>
        <select name="setor" class="form-select form-select-sm">
          <option value="">Todos os Setores</option>
          <?php foreach ($SETORES as $s): ?>
            <option value="<?= h($s) ?>" <?= $filtro_setor === $s ? 'selected' : '' ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold mb-1" style="font-size:12px">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">Todos os Status</option>
          <?php foreach (['Ativa', 'Em Manutenção', 'Inativa'] as $st_opt): ?>
            <option value="<?= $st_opt ?>" <?= $filtro_status === $st_opt ? 'selected' : '' ?>><?= $st_opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold mb-1" style="font-size:12px">Buscar</label>
        <input type="text" name="busca" class="form-control form-control-sm" placeholder="Nome, Marca, IP, Série, Toner, Setor..." value="<?= h($busca_texto) ?>">
      </div>
      <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold"><i class="bi bi-search me-1"></i>Filtrar</button>
        <a href="impressoras.php" class="btn btn-outline-secondary btn-sm" title="Limpar Filtros">✕</a>
      </div>
    </form>
  </div>
</div>

<!-- Tabela -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-task me-2 text-primary"></i>Lista de Impressoras</span>
    <?php if ($busca_texto || $filtro_setor || $filtro_status): ?>
      <span class="badge bg-primary"><?= $total_registros ?> resultado(s)</span>
    <?php else: ?>
      <span class="text-muted" style="font-size:12px"><?= $total_registros ?> registros</span>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Nome</th>
          <th>Marca / Modelo</th>
          <th>Setor</th>
          <th>Endereço IP</th>
          <th>Modelo Toner</th>
          <th>Toner / SNMP</th>
          <th>Status</th>
          <th>Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($impressoras as $imp): ?>
          <tr>
            <td>
              <strong><a href="impressora.php?id=<?= $imp['id'] ?>" class="text-decoration-none text-dark"><?= h($imp['nome']) ?></a></strong>
              <?php if (!empty($imp['inventario_id'])): ?>
                <br><a href="inventario.php?id=<?= $imp['inventario_id'] ?>" class="text-muted" style="font-size:11px" title="Ver no inventário"><i class="bi bi-box-seam me-1"></i>inventário</a>
              <?php endif; ?>
            </td>
            <td><?= h($imp['marca_modelo']) ?></td>
            <td style="font-size:12px"><?= h($imp['setor']) ?></td>
            <td>
              <?php if ($imp['ip']): ?>
                <a href="http://<?= h($imp['ip']) ?>" target="_blank" class="badge bg-light text-dark border text-decoration-none" title="Acessar página web da impressora">
                  <i class="bi bi-link-45deg me-1"></i><?= h($imp['ip']) ?>
                </a>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><code style="font-size:12px"><?= h($imp['modelo_toner'] ?: '—') ?></code></td>
            <td style="white-space:nowrap">
              <?php $sn = $snmp_map[$imp['id']] ?? null; ?>
              <?php if ($sn): ?>
                <?= tonerBadge($sn['toner_preto_pct'] !== null ? (int)$sn['toner_preto_pct'] : null, '⬛', true) ?>
                <?php if ($sn['toner_ciano_pct'] !== null): ?>
                  <?= tonerBadge((int)$sn['toner_ciano_pct'], '🔵', true) ?>
                  <?= tonerBadge((int)$sn['toner_magenta_pct'], '🔴', true) ?>
                  <?= tonerBadge((int)$sn['toner_amarelo_pct'], '🟡', true) ?>
                <?php endif; ?>
                <div style="font-size:10px;color:#9ca3af;margin-top:2px">
                  <?= number_format((int)$sn['paginas_total'], 0, ',', '.') ?> pág
                </div>
              <?php else: ?>
                <span class="text-muted" style="font-size:11px">sem SNMP</span>
              <?php endif; ?>
            </td>
            <td><?= badgeStatusImpressora($imp['status']) ?></td>
            <td>
              <div class="d-flex gap-1">
                <a href="impressora.php?id=<?= $imp['id'] ?>" class="btn btn-outline-primary btn-xs" title="Visualizar Histórico">
                  <i class="bi bi-eye"></i> Detalhes
                </a>
                <a href="editar_impressora.php?id=<?= $imp['id'] ?>" class="btn btn-outline-secondary btn-xs" title="Editar Equipamento">
                  <i class="bi bi-pencil"></i>
                </a>
                <form method="post" action="excluir_impressora.php" onsubmit="return confirm('Excluir a impressora \'<?= addslashes(h($imp['nome'])) ?>\'?\nImpressoras com manutenções não podem ser excluídas.')">
                  <?= csrfField() ?>
                  <input type="hidden" name="id" value="<?= $imp['id'] ?>">
                  <button type="submit" class="btn btn-outline-danger btn-xs" title="Excluir"><i class="bi bi-trash"></i></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$impressoras): ?>
          <tr>
            <td colspan="9" class="text-center text-muted py-4">Nenhuma impressora cadastrada ou encontrada com estes filtros.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($total_paginas > 1): ?>
  <div class="card-footer bg-white py-3 border-top">
    <nav>
      <ul class="pagination pagination-sm justify-content-center mb-0">
        <?php
          $queryParams = $_GET;
          $queryParams['pagina'] = max(1, $pagina - 1);
        ?>
        <li class="page-item <?= ($pagina <= 1) ? 'disabled' : '' ?>">
          <a class="page-link" href="?<?= http_build_query($queryParams) ?>">Anterior</a>
        </li>
        <?php
          $inicio = max(1, $pagina - 2);
          $fim    = min($total_paginas, $pagina + 2);
          if ($inicio > 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
          for ($i = $inicio; $i <= $fim; $i++):
            $queryParams['pagina'] = $i;
        ?>
          <li class="page-item <?= ($pagina == $i) ? 'active' : '' ?>">
            <a class="page-link" href="?<?= http_build_query($queryParams) ?>"><?= $i ?></a>
          </li>
        <?php endfor;
          if ($fim < $total_paginas) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
          $queryParams['pagina'] = min($total_paginas, $pagina + 1);
        ?>
        <li class="page-item <?= ($pagina >= $total_paginas) ? 'disabled' : '' ?>">
          <a class="page-link" href="?<?= http_build_query($queryParams) ?>">Próxima</a>
        </li>
      </ul>
    </nav>
    <div class="text-center text-muted mt-2" style="font-size:12px">
      Página <?= $pagina ?> de <?= $total_paginas ?> — <?= $total_registros ?> registro(s)
    </div>
  </div>
  <?php endif; ?>
</div>

<?php layoutFooter(); ?>
