<?php
require 'db.php';
requireLogin();
require 'layout.php';

$pdo = db();
$u = usuario();

// Filtros
$filtro_tipo = $_GET['tipo'] ?? '';
$filtro_status = $_GET['status'] ?? '';
$busca_texto = trim($_GET['busca'] ?? '');

$where = [];
$params = [];

if ($filtro_tipo) {
    $where[] = "m.tipo = :tipo";
    $params['tipo'] = $filtro_tipo;
}

if ($filtro_status) {
    $where[] = "m.status = :status";
    $params['status'] = $filtro_status;
}

if ($busca_texto) {
    $b = '%' . $busca_texto . '%';
    $where[] = "(i.nome LIKE :b1 OR i.marca_modelo LIKE :b2 OR i.ip LIKE :b3 OR i.setor LIKE :b4 OR m.descricao_problema LIKE :b5 OR m.solucao LIKE :b6 OR m.pecas_trocadas LIKE :b7)";
    $params['b1'] = $b;
    $params['b2'] = $b;
    $params['b3'] = $b;
    $params['b4'] = $b;
    $params['b5'] = $b;
    $params['b6'] = $b;
    $params['b7'] = $b;
}

// Stats
$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(tipo='Preventiva') AS preventiva,
        SUM(tipo='Corretiva') AS corretiva,
        SUM(status='Pendente') AS pendente
    FROM manutencoes_impressoras
")->fetch();

// Paginação
$pagina  = max(1, (int)($_GET['pagina'] ?? 1));
$limite  = 20;
$offset  = ($pagina - 1) * $limite;
$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$st_count = $pdo->prepare("SELECT COUNT(*) FROM manutencoes_impressoras m INNER JOIN impressoras i ON i.id = m.impressora_id LEFT JOIN usuarios u ON u.id = m.tecnico_id $where_sql");
$st_count->execute($params);
$total_registros = (int)$st_count->fetchColumn();
$total_paginas   = max(1, ceil($total_registros / $limite));

// Consulta principal
$sql = "
    SELECT m.*, i.nome AS impressora_nome, i.setor AS impressora_setor, u.nome AS tecnico_nome
    FROM manutencoes_impressoras m
    INNER JOIN impressoras i ON i.id = m.impressora_id
    LEFT JOIN usuarios u ON u.id = m.tecnico_id
    $where_sql ORDER BY m.data_manutencao DESC, m.criado_em DESC LIMIT $limite OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$manutencoes = $stmt->fetchAll();

layoutHeader('Histórico de Manutenções', 'manutencoes');

function badgeTipo(string $t): string {
    return $t === 'Preventiva' 
        ? '<span class="badge bg-info text-white">Preventiva</span>' 
        : '<span class="badge bg-warning text-dark">Corretiva</span>';
}

function badgeStatusM(string $s): string {
    $map = [
        'Concluída' => 'badge-concluido',
        'Em Realização' => 'badge-andamento',
        'Pendente' => 'badge-pendente'
    ];
    $cls = $map[$s] ?? 'bg-secondary text-white';
    return "<span class=\"badge $cls\">$s</span>";
}
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-wrench-adjustable me-2 text-primary"></i>Histórico de Manutenções</h1>
  <div class="d-flex gap-2">
    <a href="agenda_manutencoes.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-calendar3 me-1"></i>Agenda</a>
    <a href="nova_manutencao.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Registrar Manutenção</a>
  </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:var(--brand)"><?= (int)$stats['total'] ?></div>
          <div class="stat-label">Total Realizado</div>
        </div>
        <i class="bi bi-calendar-check" style="font-size:22px;color:var(--brand);opacity:.35"></i>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:#0ea5e9"><?= (int)$stats['preventiva'] ?></div>
          <div class="stat-label">Manutenções Preventivas</div>
        </div>
        <i class="bi bi-shield-check" style="font-size:22px;color:#0ea5e9;opacity:.35"></i>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:#f59e0b"><?= (int)$stats['corretiva'] ?></div>
          <div class="stat-label">Manutenções Corretivas</div>
        </div>
        <i class="bi bi-tools" style="font-size:22px;color:#f59e0b;opacity:.35"></i>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:#ef4444"><?= (int)$stats['pendente'] ?></div>
          <div class="stat-label">Pendentes / Em Aberto</div>
        </div>
        <i class="bi bi-clock-history" style="font-size:22px;color:#ef4444;opacity:.35"></i>
      </div>
    </div>
  </div>
</div>

<!-- Filtros -->
<div class="card mb-4">
  <div class="card-header"><i class="bi bi-funnel me-2"></i>Filtrar Histórico</div>
  <div class="card-body py-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label fw-semibold mb-1" style="font-size:12px">Tipo</label>
        <select name="tipo" class="form-select form-select-sm">
          <option value="">Todos os Tipos</option>
          <option value="Corretiva" <?= $filtro_tipo === 'Corretiva' ? 'selected' : '' ?>>Corretiva</option>
          <option value="Preventiva" <?= $filtro_tipo === 'Preventiva' ? 'selected' : '' ?>>Preventiva</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold mb-1" style="font-size:12px">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">Todos os Status</option>
          <?php foreach (['Pendente', 'Em Realização', 'Concluída'] as $st_opt): ?>
            <option value="<?= $st_opt ?>" <?= $filtro_status === $st_opt ? 'selected' : '' ?>><?= $st_opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold mb-1" style="font-size:12px">Buscar texto</label>
        <input type="text" name="busca" class="form-control form-control-sm" placeholder="Impressora, IP, Setor, problema, peças, solução..." value="<?= h($busca_texto) ?>">
      </div>
      <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold"><i class="bi bi-search me-1"></i>Filtrar</button>
        <a href="manutencoes.php" class="btn btn-outline-secondary btn-sm" title="Limpar Filtros">✕</a>
      </div>
    </form>
  </div>
</div>

<!-- Tabela -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-task me-2 text-primary"></i>Lista de Manutenções</span>
    <?php if ($busca_texto || $filtro_tipo || $filtro_status): ?>
      <span class="badge bg-primary"><?= $total_registros ?> resultado(s)</span>
    <?php else: ?>
      <span class="text-muted" style="font-size:12px"><?= $total_registros ?> registros</span>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Data</th>
          <th>Impressora</th>
          <th>Setor</th>
          <th>Tipo</th>
          <th>Técnico</th>
          <th>Descrição do Problema</th>
          <th>Status</th>
          <th>Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($manutencoes as $m): ?>
          <tr>
            <td style="white-space:nowrap;font-size:12px">
              <?= date('d/m/Y', strtotime($m['data_manutencao'])) ?>
            </td>
            <td>
              <strong><a href="impressora.php?id=<?= $m['impressora_id'] ?>" class="text-decoration-none text-dark"><?= h($m['impressora_nome']) ?></a></strong>
            </td>
            <td style="font-size:12px"><?= h($m['impressora_setor']) ?></td>
            <td><?= badgeTipo($m['tipo']) ?></td>
            <td><?= h($m['tecnico_nome'] ?? '—') ?></td>
            <td style="max-width:250px">
              <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($m['descricao_problema']) ?>">
                <?= h($m['descricao_problema']) ?>
              </div>
            </td>
            <td><?= badgeStatusM($m['status']) ?></td>
            <td>
              <a href="editar_manutencao.php?id=<?= $m['id'] ?>" class="btn btn-outline-secondary btn-xs" title="Editar / Resolver">
                <i class="bi bi-pencil-square"></i>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$manutencoes): ?>
          <tr>
            <td colspan="8" class="text-center text-muted py-4">Nenhum registro de manutenção encontrado com estes filtros.</td>
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
