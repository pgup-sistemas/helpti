<?php
require 'db.php';
requireLogin();
require 'layout.php';

$pdo = db();
$u   = usuario();

// Filtros
$filtro_tipo   = $_GET['tipo']   ?? '';
$filtro_status = $_GET['status'] ?? '';  // online|offline|novo
$filtro_rede   = $_GET['rede']   ?? '';
$busca         = trim($_GET['busca'] ?? '');

// Tabela existe?
$tem_tabela = (bool)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'hosts_rede'"
)->fetchColumn();

if (!$tem_tabela) {
    layoutHeader('Hosts de Rede', 'inventario');
    echo '<div class="alert alert-warning">Tabela hosts_rede não encontrada. Execute o scanner primeiro em <a href="ferramentas.php">Ferramentas</a>.</div>';
    layoutFooter();
    exit;
}

// Stats
$stats = $pdo->query("
    SELECT
        COUNT(*)                                    AS total,
        SUM(online = 1)                             AS online,
        SUM(online = 0)                             AS offline,
        SUM(inventario_id IS NULL AND online = 1)   AS nao_cadastrados,
        SUM(inventario_id IS NOT NULL)              AS cadastrados,
        MAX(ultimo_visto)                           AS ultimo_scan
    FROM hosts_rede
")->fetch();

// Stats por tipo de dispositivo
$stats_por_tipo = $pdo->query("
    SELECT tipo,
           COUNT(*)       AS total,
           SUM(online=1)  AS online
    FROM hosts_rede
    WHERE tipo IS NOT NULL AND tipo != ''
    GROUP BY tipo
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Redes disponíveis para filtro
$redes = $pdo->query("SELECT DISTINCT rede FROM hosts_rede WHERE rede IS NOT NULL ORDER BY rede")->fetchAll(PDO::FETCH_COLUMN);
$tipos = $pdo->query("SELECT DISTINCT tipo FROM hosts_rede WHERE tipo IS NOT NULL ORDER BY tipo")->fetchAll(PDO::FETCH_COLUMN);

// WHERE
$where  = [];
$params = [];

if ($filtro_tipo) {
    $where[] = 'h.tipo = :tipo';
    $params['tipo'] = $filtro_tipo;
}
if ($filtro_rede) {
    $where[] = 'h.rede = :rede';
    $params['rede'] = $filtro_rede;
}
if ($filtro_status === 'online')  $where[] = 'h.online = 1';
if ($filtro_status === 'offline') $where[] = 'h.online = 0';
if ($filtro_status === 'novo')    $where[] = 'h.inventario_id IS NULL AND h.online = 1';

if ($busca) {
    $b = '%' . $busca . '%';
    $where[] = '(h.ip LIKE :b1 OR h.mac_address LIKE :b2 OR h.hostname LIKE :b3 OR h.fabricante LIKE :b4 OR h.marca LIKE :b5 OR h.setor LIKE :b6)';
    $params = array_merge($params, ['b1'=>$b,'b2'=>$b,'b3'=>$b,'b4'=>$b,'b5'=>$b,'b6'=>$b]);
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$hosts = $pdo->prepare("
    SELECT h.*,
           inv.tipo     AS inv_tipo,
           inv.marca    AS inv_marca,
           inv.modelo   AS inv_modelo,
           inv.setor    AS inv_setor,
           inv.status   AS inv_status,
           inv.patrimonio
    FROM hosts_rede h
    LEFT JOIN inventario inv ON inv.id = h.inventario_id
    {$where_sql}
    ORDER BY h.online DESC, h.tipo, INET_ATON(h.ip)
");
$hosts->execute($params);
$hosts = $hosts->fetchAll();

layoutHeader('Hosts de Rede', 'inventario');

function tipoBadge(string $tipo): string {
    $map = [
        'Computador'           => ['bg-primary',          'bi-pc-display'],
        'Desktop'              => ['bg-primary',          'bi-pc-display'],
        'Notebook'             => ['bg-purple text-white','bi-laptop'],
        'Terminal'             => ['bg-secondary',        'bi-terminal-fill'],
        'Tablet'               => ['bg-purple text-white','bi-tablet-fill'],
        'Impressora'           => ['bg-info text-dark',   'bi-printer-fill'],
        'Impressora Colorida'  => ['bg-purple text-white','bi-printer-fill'],
        'Impressora Etiqueta'  => ['bg-info text-dark',   'bi-printer-fill'],
        'Switch'               => ['bg-success',          'bi-hdd-network'],
        'Switch/AP Intelbras'  => ['bg-success',          'bi-hdd-network'],
        'Access Point'         => ['bg-warning text-dark','bi-wifi'],
        'Roteador'             => ['bg-dark',             'bi-router-fill'],
        'Roteador MikroTik'    => ['bg-dark',             'bi-router-fill'],
        'Servidor'             => ['bg-danger',           'bi-server'],
        'Servidor NAS'         => ['bg-danger',           'bi-hdd-rack-fill'],
        'Monitor'              => ['bg-teal text-white',  'bi-display'],
        'Controle de Acesso'   => ['bg-primary',          'bi-door-open'],
        'Equipamento Médico'   => ['bg-pink text-white',  'bi-heart-pulse'],
        'Equipamento Especial' => ['bg-secondary',        'bi-tools'],
        'IHM/Painel'           => ['bg-secondary',        'bi-display-fill'],
        'Nobreak/UPS'          => ['bg-warning text-dark','bi-battery-charging'],
        'Celular'              => ['bg-warning text-dark','bi-phone-fill'],
        'Telefone IP'          => ['bg-pink text-white',  'bi-telephone-fill'],
    ];
    [$cls, $icon] = $map[$tipo] ?? ['bg-light text-dark', 'bi-question-circle'];
    return "<span class='badge {$cls}'><i class='bi {$icon} me-1'></i>" . h($tipo) . "</span>";
}
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-diagram-3-fill me-2 text-primary"></i>Hosts de Rede</h1>
  <div class="d-flex gap-2">
    <a href="ferramentas.php#scanner" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-search me-1"></i>Escanear agora
    </a>
    <?php if ($u['perfil'] === 'admin'): ?>
    <a href="?<?= http_build_query(array_merge($_GET, ['vincular_pendentes' => 1])) ?>" class="btn btn-outline-secondary btn-sm" title="Tenta vincular hosts ao inventário por IP/MAC automaticamente">
      <i class="bi bi-link-45deg me-1"></i>Auto-vincular
    </a>
    <?php endif; ?>
  </div>
</div>

<?php
// Ação: auto-vincular
if (isset($_GET['vincular_pendentes']) && $u['perfil'] === 'admin') {
    $sem_inv = $pdo->query("SELECT id, ip, mac_address FROM hosts_rede WHERE inventario_id IS NULL")->fetchAll();
    $vinc = 0;
    foreach ($sem_inv as $hr) {
        $r = $pdo->prepare("SELECT id FROM inventario WHERE mac_address = ? LIMIT 1");
        $r->execute([$hr['mac_address']]);
        $inv = $r->fetch();
        if (!$inv) {
            $r = $pdo->prepare("SELECT id FROM inventario WHERE ip = ? LIMIT 1");
            $r->execute([$hr['ip']]);
            $inv = $r->fetch();
        }
        if ($inv) {
            $pdo->prepare("UPDATE hosts_rede SET inventario_id = ? WHERE id = ?")->execute([$inv['id'], $hr['id']]);
            $pdo->prepare("UPDATE inventario SET ip = COALESCE(NULLIF(ip,''), ?), mac_address = COALESCE(NULLIF(mac_address,''), ?) WHERE id = ?")
                ->execute([$hr['ip'], $hr['mac_address'], $inv['id']]);
            $vinc++;
        }
    }
    flash("{$vinc} host(s) vinculado(s) ao inventário automaticamente.", $vinc > 0 ? 'success' : 'warning');
    header('Location: hosts_rede.php');
    exit;
}

// Ação: vincular manualmente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vincular_host'])) {
    csrfVerify();
    $host_id = (int)$_POST['host_id'];
    $inv_id  = (int)$_POST['inventario_id'];
    if ($host_id && $inv_id) {
        $st = $pdo->prepare("SELECT ip, mac_address FROM hosts_rede WHERE id = ?");
        $st->execute([$host_id]);
        $hr = $st->fetch();
        $pdo->prepare("UPDATE hosts_rede SET inventario_id = ? WHERE id = ?")->execute([$inv_id, $host_id]);
        if ($hr) {
            $pdo->prepare("UPDATE inventario SET ip = ?, mac_address = ? WHERE id = ?")
                ->execute([$hr['ip'], $hr['mac_address'], $inv_id]);
        }
        flash('Host vinculado ao inventário com sucesso.');
        header('Location: hosts_rede.php');
        exit;
    }
}
?>

<?php
// Mapa de tipo → [ícone, cor]
$tipo_visual = [
    // Computadores / estações
    'Computador'           => ['bi-pc-display',        '#6366f1'],
    'Desktop'              => ['bi-pc-display',        '#6366f1'],
    'Notebook'             => ['bi-laptop',             '#8b5cf6'],
    'Tablet'               => ['bi-tablet-fill',        '#a855f7'],
    'Terminal'             => ['bi-terminal-fill',      '#64748b'],
    // Impressoras
    'Impressora'           => ['bi-printer-fill',       '#0ea5e9'],
    'Impressora Colorida'  => ['bi-printer-fill',       '#7c3aed'],
    'Impressora Etiqueta'  => ['bi-printer-fill',       '#06b6d4'],
    // Rede
    'Switch'               => ['bi-hdd-network',        '#22c55e'],
    'Switch/AP Intelbras'  => ['bi-hdd-network',        '#16a34a'],
    'Access Point'         => ['bi-wifi',               '#f59e0b'],
    'Roteador'             => ['bi-router-fill',        '#10b981'],
    'Roteador MikroTik'    => ['bi-router-fill',        '#059669'],
    // Servidores / storage
    'Servidor'             => ['bi-server',             '#3b82f6'],
    'Servidor NAS'         => ['bi-hdd-rack-fill',      '#2563eb'],
    // Periféricos / outros
    'Monitor'              => ['bi-display',            '#14b8a6'],
    'Celular'              => ['bi-phone-fill',         '#f59e0b'],
    'Telefone IP'          => ['bi-telephone-fill',     '#ec4899'],
    'Nobreak/UPS'          => ['bi-battery-charging',   '#f97316'],
    'Controle de Acesso'   => ['bi-door-open',          '#3b82f6'],
    'Equipamento Médico'   => ['bi-heart-pulse',        '#ec4899'],
    'Equipamento Especial' => ['bi-tools',              '#94a3b8'],
    'IHM/Painel'           => ['bi-display-fill',       '#64748b'],
];
?>

<!-- Linha de status geral -->
<div class="row g-3 mb-2">
  <div class="col-6 col-md-2">
    <a href="hosts_rede.php" class="text-decoration-none">
      <div class="stat-card text-center">
        <div class="stat-num" style="color:var(--brand)"><?= (int)$stats['total'] ?></div>
        <div class="stat-label">Total detectado</div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-2">
    <a href="?status=online" class="text-decoration-none">
      <div class="stat-card text-center" style="<?= $filtro_status==='online'?'border-color:#22c55e':'' ?>">
        <div class="stat-num" style="color:#22c55e"><?= (int)$stats['online'] ?></div>
        <div class="stat-label">Online agora</div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-2">
    <a href="?status=offline" class="text-decoration-none">
      <div class="stat-card text-center" style="<?= $filtro_status==='offline'?'border-color:#6b7280':'' ?>">
        <div class="stat-num" style="color:#6b7280"><?= (int)$stats['offline'] ?></div>
        <div class="stat-label">Offline</div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-2">
    <a href="?status=novo" class="text-decoration-none">
      <div class="stat-card text-center" style="<?= $filtro_status==='novo'?'border-color:#f59e0b':'' ?>">
        <div class="stat-num" style="color:#f59e0b"><?= (int)$stats['nao_cadastrados'] ?></div>
        <div class="stat-label">Não cadastrados</div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-2">
    <div class="stat-card text-center">
      <div class="stat-num" style="color:#0ea5e9"><?= (int)$stats['cadastrados'] ?></div>
      <div class="stat-label">No inventário</div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="stat-card text-center">
      <div style="font-size:13px;font-weight:700;color:#6b7280;margin-top:2px">
        <?= $stats['ultimo_scan'] ? date('d/m H:i', strtotime($stats['ultimo_scan'])) : '—' ?>
      </div>
      <div class="stat-label">Último scan</div>
    </div>
  </div>
</div>

<!-- Breakdown por tipo de dispositivo -->
<?php if (!empty($stats_por_tipo)): ?>
<div class="mb-4">
  <div class="d-flex align-items-center gap-2 mb-2" style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--tx-muted)">
    <span>Por tipo de dispositivo</span>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php foreach ($stats_por_tipo as $row):
      $t   = $row['tipo'];
      $n   = (int)$row['total'];
      $on  = (int)$row['online'];
      $off = $n - $on;
      [$ico, $cor] = $tipo_visual[$t] ?? ['bi-question-circle', '#94a3b8'];
      $ativo = $filtro_tipo === $t;
    ?>
    <a href="?tipo=<?= urlencode($t) ?>" class="text-decoration-none" title="Filtrar: <?= h($t) ?>">
      <div style="background:var(--bg-surface);border:1px solid <?= $ativo ? $cor : 'var(--border)' ?>;border-radius:10px;padding:9px 14px;display:flex;align-items:center;gap:10px;min-width:0;transition:border-color .15s">
        <i class="bi <?= $ico ?>" style="color:<?= $cor ?>;font-size:20px;flex-shrink:0"></i>
        <div>
          <div style="font-size:18px;font-weight:700;line-height:1;color:<?= $cor ?>"><?= $n ?></div>
          <div style="font-size:10px;color:var(--tx-muted);white-space:nowrap;margin-top:1px"><?= h($t) ?></div>
          <div style="font-size:10px;margin-top:2px;line-height:1">
            <span style="color:#22c55e;font-weight:600"><?= $on ?>↑</span>
            <?php if ($off > 0): ?><span style="color:#94a3b8;margin-left:4px"><?= $off ?>↓</span><?php endif; ?>
          </div>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Alerta hosts não cadastrados -->
<?php if ((int)$stats['nao_cadastrados'] > 0 && !$filtro_status && !$filtro_tipo && !$busca): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
  <i class="bi bi-exclamation-triangle-fill fs-5"></i>
  <div>
    <strong><?= (int)$stats['nao_cadastrados'] ?> host(s) online não cadastrado(s) no inventário.</strong>
    Clique em <strong>Auto-vincular</strong> para tentar vincular por MAC/IP, ou vincule manualmente na tabela abaixo.
  </div>
</div>
<?php endif; ?>

<!-- Filtros -->
<div class="card mb-4">
  <div class="card-body py-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label fw-semibold mb-1" style="font-size:12px">Tipo</label>
        <select name="tipo" class="form-select form-select-sm">
          <option value="">Todos os Tipos</option>
          <?php foreach ($tipos as $t): ?>
            <option value="<?= h($t) ?>" <?= $filtro_tipo === $t ? 'selected' : '' ?>><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold mb-1" style="font-size:12px">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">Todos</option>
          <option value="online"  <?= $filtro_status === 'online'  ? 'selected' : '' ?>>Online</option>
          <option value="offline" <?= $filtro_status === 'offline' ? 'selected' : '' ?>>Offline</option>
          <option value="novo"    <?= $filtro_status === 'novo'    ? 'selected' : '' ?>>Não cadastrados</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold mb-1" style="font-size:12px">Rede</label>
        <select name="rede" class="form-select form-select-sm">
          <option value="">Todas</option>
          <?php foreach ($redes as $r2): ?>
            <option value="<?= h($r2) ?>" <?= $filtro_rede === $r2 ? 'selected' : '' ?>><?= h($r2) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold mb-1" style="font-size:12px">Buscar</label>
        <input type="text" name="busca" class="form-control form-control-sm" placeholder="IP, MAC, hostname, fabricante..." value="<?= h($busca) ?>">
      </div>
      <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="bi bi-search me-1"></i>Filtrar</button>
        <a href="hosts_rede.php" class="btn btn-outline-secondary btn-sm">✕</a>
      </div>
    </form>
  </div>
</div>

<!-- Tabela -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-task me-2 text-primary"></i>Dispositivos na Rede</span>
    <span class="text-muted" style="font-size:12px"><?= count($hosts) ?> resultado(s)</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0">
      <thead>
        <tr>
          <th style="width:20px"></th>
          <th>IP</th>
          <th>MAC</th>
          <th>Tipo</th>
          <th>Marca / Host</th>
          <th>Rede / Setor</th>
          <th>Inventário</th>
          <th>Visto</th>
          <th class="text-center">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($hosts as $h): ?>
        <tr class="<?= !$h['online'] ? 'table-secondary opacity-75' : '' ?>">
          <td>
            <span class="d-inline-block rounded-circle" style="width:9px;height:9px;background:<?= $h['online'] ? '#22c55e' : '#9ca3af' ?>;margin-top:4px" title="<?= $h['online'] ? 'Online' : 'Offline' ?>"></span>
          </td>
          <td>
            <code style="font-size:12px"><?= h($h['ip']) ?></code>
          </td>
          <td>
            <code style="font-size:11px;color:#6b7280"><?= h($h['mac_address']) ?></code>
            <?php if ($h['fabricante']): ?>
              <div style="font-size:10px;color:#9ca3af"><?= h(substr($h['fabricante'], 0, 30)) ?></div>
            <?php endif; ?>
          </td>
          <td><?= tipoBadge($h['tipo'] ?? 'Desconhecido') ?></td>
          <td>
            <div class="fw-semibold" style="font-size:13px"><?= h($h['marca'] ?: '—') ?></div>
            <?php if ($h['hostname']): ?>
              <div style="font-size:11px;color:#6b7280"><?= h($h['hostname']) ?></div>
            <?php endif; ?>
            <?php if ($h['portas']): ?>
              <div style="font-size:10px;color:#9ca3af">Portas: <?= h($h['portas']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <div style="font-size:12px"><?= h($h['rede'] ?: '—') ?></div>
            <?php if ($h['setor']): ?>
              <div style="font-size:11px;color:#6b7280"><?= h($h['setor']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($h['inventario_id']): ?>
              <a href="inventario.php?action=editar&id=<?= $h['inventario_id'] ?>" class="text-decoration-none" style="font-size:12px">
                <i class="bi bi-box-seam me-1 text-primary"></i><?= h($h['inv_marca'] . ' ' . $h['inv_modelo']) ?>
              </a>
              <?php if ($h['patrimonio']): ?>
                <div style="font-size:10px;color:#9ca3af">Pat. <?= h($h['patrimonio']) ?></div>
              <?php endif; ?>
            <?php else: ?>
              <span class="badge bg-warning text-dark" style="font-size:10px">Não cadastrado</span>
            <?php endif; ?>
          </td>
          <td style="font-size:11px;color:#6b7280;white-space:nowrap">
            <?= $h['ultimo_visto'] ? date('d/m H:i', strtotime($h['ultimo_visto'])) : '—' ?>
          </td>
          <td class="text-center">
            <div class="d-flex gap-1 justify-content-center">
              <?php if (!$h['inventario_id']): ?>
              <button type="button"
                class="btn btn-xs btn-outline-primary"
                title="Vincular ao inventário"
                onclick="abrirVincular(<?= $h['id'] ?>, '<?= h($h['ip']) ?>', '<?= h($h['mac_address']) ?>', '<?= h($h['tipo']) ?>')">
                <i class="bi bi-link-45deg"></i>
              </button>
              <?php endif; ?>
              <a href="http://<?= h($h['ip']) ?>" target="_blank" class="btn btn-xs btn-outline-secondary" title="Abrir interface web">
                <i class="bi bi-globe"></i>
              </a>
              <?php if (str_contains($h['tipo'] ?? '', 'Impressora')): ?>
              <?php
                $imp_link = $pdo->prepare("SELECT id FROM impressoras WHERE ip = ?");
                $imp_link->execute([$h['ip']]);
                $imp_row = $imp_link->fetch();
              ?>
              <?php if ($imp_row): ?>
              <a href="impressora.php?id=<?= $imp_row['id'] ?>" class="btn btn-xs btn-outline-success" title="Ver monitoramento SNMP">
                <i class="bi bi-printer"></i>
              </a>
              <?php endif; ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$hosts): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">Nenhum host encontrado. Execute o scanner em <a href="ferramentas.php">Ferramentas</a>.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal vincular -->
<div class="modal fade" id="modalVincular" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrfField() ?>
      <input type="hidden" name="vincular_host" value="1">
      <input type="hidden" name="host_id" id="v_host_id">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-link-45deg me-2"></i>Vincular ao Inventário</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted mb-3" id="v_info" style="font-size:13px"></p>
        <label class="form-label fw-semibold">Selecione o item no inventário:</label>
        <select name="inventario_id" class="form-select" id="v_inv_select" required>
          <option value="">— selecione —</option>
          <?php
          $inv_all = $pdo->query("SELECT id, tipo, marca, modelo, numero_serie, setor, patrimonio FROM inventario ORDER BY tipo, marca, modelo")->fetchAll();
          foreach ($inv_all as $i) {
              $label = "[{$i['tipo']}] {$i['marca']} {$i['modelo']}";
              if ($i['numero_serie']) $label .= " — S/N {$i['numero_serie']}";
              if ($i['patrimonio'])   $label .= " — Pat. {$i['patrimonio']}";
              if ($i['setor'])        $label .= " ({$i['setor']})";
              echo "<option value='{$i['id']}'>" . h($label) . "</option>";
          }
          ?>
        </select>
        <div class="mt-3">
          <small class="text-muted">Não encontrou? <a href="inventario.php" target="_blank">Cadastre no inventário</a> e volte aqui.</small>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-link-45deg me-1"></i>Vincular</button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirVincular(id, ip, mac, tipo) {
  document.getElementById('v_host_id').value = id;
  document.getElementById('v_info').textContent = `Host: ${ip} | MAC: ${mac} | Tipo: ${tipo}`;
  new bootstrap.Modal(document.getElementById('modalVincular')).show();
}
</script>

<?php layoutFooter(); ?>
