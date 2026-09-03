<?php
require 'db.php';
requireLogin();
require 'layout.php';
require_once 'sync_inventario.php';

$pdo    = db();
$u      = usuario();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Corrige status: Disponível → Em Uso para hosts online
if (isset($_GET['corrigir_status']) && ($u['perfil'] ?? '') !== 'tecnico') {
    $n = (int)db()->exec("
        UPDATE inventario i
        JOIN hosts_rede h ON h.inventario_id = i.id
        SET i.status = 'Em Uso', i.atualizado_em = NOW()
        WHERE h.online = 1 AND i.status = 'Disponível'
    ");
    flash("success", "{$n} equipamento(s) atualizados de 'Disponível' para 'Em Uso' (detectados online na rede).");
    header('Location: inventario.php'); exit;
}

// Botão "Sincronizar Impressoras"
if (isset($_GET['sync_impressoras']) && ($u['perfil'] ?? '') !== 'tecnico') {
    $sync = sync_impressoras_from_inventario();
    flash("Sincronização concluída: {$sync['criadas']} impressora(s) criada(s), {$sync['atualizadas']} atualizada(s).", 'success');
    header('Location: inventario.php'); exit;
}
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $campos = [
        'tipo'             => $_POST['tipo'] ?? '',
        'marca'            => trim($_POST['marca'] ?? ''),
        'modelo'           => trim($_POST['modelo'] ?? ''),
        'numero_serie'     => trim($_POST['numero_serie'] ?? ''),
        'patrimonio'       => trim($_POST['patrimonio'] ?? ''),
        'setor'            => trim($_POST['setor'] ?? ''),
        'responsavel_nome' => trim($_POST['responsavel_nome'] ?? ''),
        'status'           => $_POST['status'] ?? 'Em Uso',
        'data_aquisicao'   => $_POST['data_aquisicao'] ?: null,
        'valor'            => $_POST['valor'] !== '' ? (float)$_POST['valor'] : null,
        'garantia_ate'     => $_POST['garantia_ate'] ?: null,
        'imei'             => trim($_POST['imei'] ?? ''),
        'observacoes'      => trim($_POST['observacoes'] ?? ''),
    ];

    if ($action === 'criar') {
        $pdo->prepare("INSERT INTO inventario (tipo,marca,modelo,numero_serie,patrimonio,setor,responsavel_nome,status,data_aquisicao,valor,garantia_ate,imei,observacoes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute(array_values($campos));
        flash('Equipamento cadastrado com sucesso.');
    } elseif ($action === 'editar' && $id) {
        $vals = array_values($campos);
        $vals[] = $id;
        $pdo->prepare("UPDATE inventario SET tipo=?,marca=?,modelo=?,numero_serie=?,patrimonio=?,setor=?,responsavel_nome=?,status=?,data_aquisicao=?,valor=?,garantia_ate=?,imei=?,observacoes=? WHERE id=?")
            ->execute($vals);
        // Atualiza impressora vinculada se existir
        $imp_sync = $pdo->prepare("SELECT id FROM impressoras WHERE inventario_id=?");
        $imp_sync->execute([$id]);
        if ($imp_id_sync = $imp_sync->fetchColumn()) {
            $status_imp = match($campos['status']) { 'Em Manutenção'=>'Em Manutenção', 'Descartado'=>'Inativa', default=>'Ativa' };
            $pdo->prepare("UPDATE impressoras SET nome=?,marca_modelo=?,numero_serie=?,setor=?,status=?,atualizado_em=NOW() WHERE id=?")
                ->execute([
                    trim(($campos['modelo'] ?: $campos['marca']) ?: 'Impressora'),
                    trim($campos['marca'].' '.$campos['modelo']),
                    $campos['numero_serie'],
                    $campos['setor'],
                    $status_imp,
                    $imp_id_sync,
                ]);
        }
        flash('Equipamento atualizado.');
    } elseif ($action === 'excluir' && $id) {
        $termos = $pdo->prepare("SELECT COUNT(*) FROM termos_uso WHERE inventario_id=?");
        $termos->execute([$id]);
        if ($termos->fetchColumn() > 0) {
            flash('Equipamento possui termos de uso vinculados. Devolva os termos antes de excluir.', 'danger');
        } else {
            $pdo->prepare("DELETE FROM inventario WHERE id=?")->execute([$id]);
            flash('Equipamento removido.');
        }
    } elseif ($action === 'excluir_massa') {
        requireGestora();
        $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
        if (empty($ids)) { flash('Nenhum item selecionado.', 'danger'); header('Location: inventario.php'); exit; }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // Verifica se algum tem termo ativo
        $com_termo = $pdo->prepare("SELECT COUNT(*) FROM termos_uso WHERE inventario_id IN ($placeholders) AND status='Ativo'");
        $com_termo->execute($ids);
        if ($com_termo->fetchColumn() > 0) {
            flash('Alguns equipamentos possuem termos de uso ativos e não podem ser excluídos.', 'danger');
        } else {
            $pdo->prepare("DELETE FROM inventario WHERE id IN ($placeholders)")->execute($ids);
            flash(count($ids).' equipamento(s) excluído(s) com sucesso.');
        }
    }
    header('Location: inventario.php'); exit;
}

$editando = null;
if ($action === 'editar' && $id) {
    $st = $pdo->prepare("SELECT * FROM inventario WHERE id=?");
    $st->execute([$id]);
    $editando = $st->fetch();
}

// Filtros
$f_tipo   = $_GET['tipo']   ?? '';
$f_status = $_GET['status'] ?? '';
$f_setor  = $_GET['setor']  ?? '';
$f_q      = trim($_GET['q'] ?? '');

// Ordenação
$ordenacoes = [
    'recentes'  => 'criado_em DESC',
    'nome_asc'  => 'marca ASC, modelo ASC',
    'nome_desc' => 'marca DESC, modelo DESC',
    'setor_asc' => 'setor ASC',
    'status'    => "FIELD(status,'Em Uso','Disponível','Em Manutenção','Descartado')",
    'garantia'  => 'garantia_ate IS NULL, garantia_ate ASC',
];
$f_ordenar = isset($ordenacoes[$_GET['ordenar'] ?? '']) ? $_GET['ordenar'] : 'recentes';
$order_by  = $ordenacoes[$f_ordenar];

$where = ['1=1']; $params = [];
if ($f_tipo)   { $where[] = 'tipo=?';   $params[] = $f_tipo; }
if ($f_status) { $where[] = 'status=?'; $params[] = $f_status; }
if ($f_setor)  { $where[] = 'setor=?';  $params[] = $f_setor; }
if ($f_q)      { $where[] = '(marca LIKE ? OR modelo LIKE ? OR numero_serie LIKE ? OR patrimonio LIKE ? OR responsavel_nome LIKE ?)';
                 $p = "%$f_q%"; $params = array_merge($params, [$p,$p,$p,$p,$p]); }

// Paginação
$por_pag = 50;
$pag     = max(1, (int)($_GET['pag'] ?? 1));
$offset  = ($pag - 1) * $por_pag;

$sql_count = "SELECT COUNT(*) FROM inventario WHERE " . implode(' AND ', $where);
$total_itens = (int)$pdo->prepare($sql_count)->execute($params) ? $pdo->prepare($sql_count)->execute($params) : 0;
$st_count = $pdo->prepare($sql_count); $st_count->execute($params);
$total_itens = (int)$st_count->fetchColumn();
$total_pags  = (int)ceil($total_itens / $por_pag);

$sql = "SELECT * FROM inventario WHERE " . implode(' AND ', $where) . " ORDER BY $order_by LIMIT $por_pag OFFSET $offset";
$st  = $pdo->prepare($sql); $st->execute($params);
$itens = $st->fetchAll();

// Stats
$stats_inv = $pdo->query("SELECT status, COUNT(*) n FROM inventario GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$garantias = $pdo->query("SELECT COUNT(*) FROM inventario WHERE garantia_ate IS NOT NULL AND garantia_ate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)")->fetchColumn();

// Divergência: itens 'Disponível' que estão online na rede
$disp_online = 0;
try {
    $disp_online = (int)$pdo->query("
        SELECT COUNT(*) FROM inventario i
        JOIN hosts_rede h ON h.inventario_id = i.id
        WHERE h.online = 1 AND i.status = 'Disponível'
    ")->fetchColumn();
} catch (Throwable) { /* hosts_rede pode não existir ainda */ }

// Stats por categoria com breakdown de status
$cat_map = [
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

$stats_tipo_raw = $pdo->query("
    SELECT tipo,
           COUNT(*)                          AS total,
           SUM(status = 'Em Uso')            AS em_uso,
           SUM(status = 'Disponível')        AS disponivel,
           SUM(status = 'Em Manutenção')     AS manutencao,
           SUM(status = 'Descartado')        AS descartado
    FROM inventario
    GROUP BY tipo
    ORDER BY (COUNT(*) - SUM(status='Descartado')) DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Separa tipos conhecidos de outros
$stats_tipo   = [];
$outros_total = 0;
$outros_uso   = 0;
$outros_disp  = 0;
$outros_manut = 0;
foreach ($stats_tipo_raw as $row) {
    $t = $row['tipo'];
    $ativo = (int)$row['total'] - (int)$row['descartado'];
    if ($ativo <= 0) continue;
    if (isset($cat_map[$t])) {
        $stats_tipo[$t] = $row;
    } else {
        $outros_total += $ativo;
        $outros_uso   += (int)$row['em_uso'];
        $outros_disp  += (int)$row['disponivel'];
        $outros_manut += (int)$row['manutencao'];
    }
}
if ($outros_total > 0) {
    $stats_tipo['Outros'] = [
        'tipo' => 'Outros', 'total' => $outros_total,
        'em_uso' => $outros_uso, 'disponivel' => $outros_disp,
        'manutencao' => $outros_manut, 'descartado' => 0,
    ];
}
$total_ativo = array_sum(array_column($stats_tipo_raw, 'total'))
             - array_sum(array_column($stats_tipo_raw, 'descartado'));

$setores = $pdo->query("SELECT nome FROM setores WHERE ativo=1 ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN);

$tipos = $pdo->query("SELECT nome FROM tipos_inventario WHERE ativo=1 ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN);
$statuses = ['Em Uso','Disponível','Em Manutenção','Descartado'];

$statusColor = ['Em Uso'=>'badge-concluido','Disponível'=>'badge-aberto','Em Manutenção'=>'badge-andamento','Descartado'=>'bg-secondary text-white'];

layoutHeader('Inventário TI', 'inventario');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-pc-display me-2 text-primary"></i>Inventário de TI</h1>
  <div class="d-flex gap-2 align-items-center">
    <?php if ($garantias): ?>
    <span class="badge badge-andamento align-self-center"><i class="bi bi-shield-exclamation me-1"></i><?= $garantias ?> garantia(s) vencendo em 60 dias</span>
    <?php endif; ?>
    <?php if (($u['perfil'] ?? '') !== 'tecnico'): ?>
    <a href="qrcode_equipamento.php" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="bi bi-qr-code me-1"></i>Todas as etiquetas</a>
    <a href="importar_inventario.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-upload me-1"></i>Importar CSV</a>
    <a href="?sync_impressoras=1" class="btn btn-outline-success btn-sm" title="Sincroniza impressoras cadastradas no inventário para o módulo de impressoras"><i class="bi bi-arrow-repeat me-1"></i>Sincronizar Impressoras</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($disp_online > 0 && ($u['perfil'] ?? '') !== 'tecnico'): ?>
<div class="alert alert-warning d-flex align-items-center justify-content-between gap-3 mb-3 py-2 px-3" style="font-size:13px">
  <div>
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong><?= $disp_online ?> equipamento(s)</strong> marcados como <em>Disponível</em> estão <strong>online na rede agora</strong>.
    O status provavelmente está desatualizado.
  </div>
  <a href="?corrigir_status=1" class="btn btn-warning btn-sm text-nowrap" style="font-size:12px">
    <i class="bi bi-arrow-repeat me-1"></i>Corrigir agora
  </a>
</div>
<?php endif; ?>

<!-- Stats por status -->
<div class="row g-3 mb-2">
  <?php foreach([
    ['Em Uso',        ($stats_inv['Em Uso']??0),        '#22c55e','bi-check-circle-fill'],
    ['Disponível',    ($stats_inv['Disponível']??0),    '#0ea5e9','bi-archive'],
    ['Em Manutenção', ($stats_inv['Em Manutenção']??0), '#f59e0b','bi-wrench'],
    ['Descartado',    ($stats_inv['Descartado']??0),    '#94a3b8','bi-trash3'],
  ] as [$l,$v,$c,$i]): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:<?=$c?>"><?=$v?></div>
          <div class="stat-label"><?=$l?></div>
        </div>
        <i class="bi <?=$i?>" style="font-size:22px;color:<?=$c?>;opacity:.35"></i>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Stats por categoria -->
<?php if (!empty($stats_tipo)): ?>
<div class="mb-4">
  <div class="d-flex align-items-center gap-2 mb-2" style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--tx-muted)">
    <span>Por categoria</span>
    <span style="font-weight:400;text-transform:none;letter-spacing:0">(<?= $total_ativo ?> ativos)</span>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php foreach ($stats_tipo as $tipo => $row):
      [$ico, $cor] = $cat_map[$tipo] ?? ['bi-box', '#94a3b8'];
      $ativo  = isset($row['total']) ? (int)$row['total'] - (int)$row['descartado'] : (int)$row;
      $uso    = (int)($row['em_uso']    ?? 0);
      $disp   = (int)($row['disponivel'] ?? 0);
      $manut  = (int)($row['manutencao'] ?? 0);
      $ativo_f = $f_tipo === $tipo;
    ?>
    <a href="inventario.php?tipo=<?= urlencode($tipo) ?>" class="text-decoration-none" title="Filtrar por <?= h($tipo) ?>">
      <div style="background:var(--bg-surface);border:1px solid <?= $ativo_f ? $cor : 'var(--border)' ?>;border-radius:10px;padding:9px 14px;display:flex;align-items:center;gap:10px;min-width:0;transition:border-color .15s">
        <i class="bi <?= $ico ?>" style="color:<?= $cor ?>;font-size:20px;flex-shrink:0"></i>
        <div>
          <div style="font-size:18px;font-weight:700;line-height:1;color:<?= $cor ?>"><?= $ativo ?></div>
          <div style="font-size:10px;color:var(--tx-muted);white-space:nowrap;margin-top:1px"><?= h($tipo) ?></div>
          <div style="font-size:10px;margin-top:2px;line-height:1.2">
            <?php if ($uso > 0): ?><span style="color:#22c55e;font-weight:600" title="Em Uso"><?= $uso ?>✓</span><?php endif; ?>
            <?php if ($disp > 0): ?><span style="color:#0ea5e9;margin-left:4px" title="Disponível"><?= $disp ?>▫</span><?php endif; ?>
            <?php if ($manut > 0): ?><span style="color:#f59e0b;margin-left:4px" title="Em Manutenção"><?= $manut ?>⚙</span><?php endif; ?>
          </div>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="d-flex flex-column gap-3">

  <!-- Formulário -->
  <div class="card" id="cardCadastro">
    <div class="card-header d-flex align-items-center justify-content-between" style="cursor:pointer" onclick="toggleCadastro()">
      <span><i class="bi bi-<?= $editando ? 'pencil' : 'plus-circle' ?> me-2"></i><?= $editando ? 'Editar equipamento' : 'Cadastrar equipamento' ?></span>
      <i class="bi bi-chevron-up" id="iconCadastro" style="transition:.2s"></i>
    </div>
    <div class="card-body" id="corpoCadastro">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="<?= $editando ? 'editar' : 'criar' ?>">
        <?php if ($editando): ?><input type="hidden" name="id" value="<?= $editando['id'] ?>"><?php endif; ?>
        <div class="row g-3">
          <div class="col-sm-3">
            <label class="form-label fw-semibold" style="font-size:13px">Tipo *</label>
            <select name="tipo" class="form-select form-select-sm" required>
              <?php foreach($tipos as $t): ?>
              <option <?= ($editando['tipo']??'')===$t?'selected':'' ?>><?=$t?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label fw-semibold" style="font-size:13px">Marca</label>
            <input type="text" name="marca" class="form-control form-control-sm" value="<?= h($editando['marca']??'') ?>" placeholder="Dell, Apple, HP…">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold" style="font-size:13px">Modelo</label>
            <input type="text" name="modelo" class="form-control form-control-sm" value="<?= h($editando['modelo']??'') ?>" placeholder="Latitude 5540, iPhone 14…">
          </div>
          <div class="col-sm-3">
            <label class="form-label fw-semibold" style="font-size:13px">Nº de Série</label>
            <input type="text" name="numero_serie" class="form-control form-control-sm" value="<?= h($editando['numero_serie']??'') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label fw-semibold" style="font-size:13px">Patrimônio</label>
            <input type="text" name="patrimonio" class="form-control form-control-sm" value="<?= h($editando['patrimonio']??'') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label fw-semibold" style="font-size:13px">IMEI / MEID</label>
            <input type="text" name="imei" class="form-control form-control-sm" value="<?= h($editando['imei']??'') ?>" placeholder="Para celulares">
          </div>
          <div class="col-sm-3">
            <label class="form-label fw-semibold" style="font-size:13px">Status</label>
            <select name="status" class="form-select form-select-sm">
              <?php foreach($statuses as $s): ?>
              <option <?= ($editando['status']??'Em Uso')===$s?'selected':'' ?>><?=$s?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-semibold" style="font-size:13px">Setor</label>
            <input type="text" name="setor" class="form-control form-control-sm" list="lst-setores" value="<?= h($editando['setor']??'') ?>">
            <datalist id="lst-setores"><?php foreach($setores as $s): ?><option value="<?=h($s)?>">  <?php endforeach; ?></datalist>
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-semibold" style="font-size:13px">Responsável</label>
            <input type="text" name="responsavel_nome" class="form-control form-control-sm" value="<?= h($editando['responsavel_nome']??'') ?>" placeholder="Nome do colaborador">
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-semibold" style="font-size:13px">Valor de Aquisição (R$)</label>
            <input type="number" name="valor" step="0.01" min="0" class="form-control form-control-sm" value="<?= h($editando['valor']??'') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label fw-semibold" style="font-size:13px">Data de Aquisição</label>
            <input type="date" name="data_aquisicao" class="form-control form-control-sm" value="<?= h($editando['data_aquisicao']??'') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label fw-semibold" style="font-size:13px">Garantia até</label>
            <input type="date" name="garantia_ate" class="form-control form-control-sm" value="<?= h($editando['garantia_ate']??'') ?>">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold" style="font-size:13px">Observações</label>
            <input type="text" name="observacoes" class="form-control form-control-sm" value="<?= h($editando['observacoes']??'') ?>">
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary btn-sm"><?= $editando ? 'Salvar' : 'Cadastrar' ?></button>
          <?php if ($editando): ?><a href="inventario.php" class="btn btn-outline-secondary btn-sm">Cancelar</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- Filtros -->
  <form method="get" class="card card-body py-2">
    <div class="row g-2 align-items-center">
      <div class="col"><input type="text" name="q" class="form-control form-control-sm" placeholder="Marca, modelo, série, responsável…" value="<?= h($f_q) ?>"></div>
      <div class="col-6 col-md-auto">
        <select name="tipo" class="form-select form-select-sm">
          <option value="">Todos os tipos</option>
          <?php foreach($tipos as $t): ?><option <?= $f_tipo===$t?'selected':'' ?>><?=$t?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-auto">
        <select name="status" class="form-select form-select-sm">
          <option value="">Todos os status</option>
          <?php foreach($statuses as $s): ?><option <?= $f_status===$s?'selected':'' ?>><?=$s?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-auto">
        <input type="text" name="setor" class="form-control form-control-sm" list="lst-setores2" placeholder="Setor" value="<?= h($f_setor) ?>" style="min-width:110px">
        <datalist id="lst-setores2"><?php foreach($setores as $s): ?><option value="<?=h($s)?>">  <?php endforeach; ?></datalist>
      </div>
      <div class="col-6 col-md-auto">
        <select name="ordenar" class="form-select form-select-sm" title="Ordenar por" style="min-width:150px">
          <option value="recentes"  <?= $f_ordenar==='recentes' ?'selected':'' ?>>↕ Mais recentes</option>
          <option value="nome_asc"  <?= $f_ordenar==='nome_asc' ?'selected':'' ?>>↕ Nome (A-Z)</option>
          <option value="nome_desc" <?= $f_ordenar==='nome_desc'?'selected':'' ?>>↕ Nome (Z-A)</option>
          <option value="setor_asc" <?= $f_ordenar==='setor_asc'?'selected':'' ?>>↕ Setor</option>
          <option value="status"    <?= $f_ordenar==='status'   ?'selected':'' ?>>↕ Status</option>
          <option value="garantia"  <?= $f_ordenar==='garantia' ?'selected':'' ?>>↕ Garantia</option>
        </select>
      </div>
      <div class="col-auto d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm" title="Filtrar"><i class="bi bi-search"></i></button>
        <a href="inventario.php" class="btn btn-outline-secondary btn-sm" title="Limpar filtros"><i class="bi bi-x-lg"></i></a>
      </div>
    </div>
  </form>

  <!-- Toolbar seleção em massa -->
  <?php if ($itens && ($u['perfil'] ?? '') !== 'tecnico'): ?>
  <div id="toolbarMassa" class="card card-body py-2 px-3 d-none" style="background:var(--venc-warn-bg,#fff3cd);border:1px solid #ffc107;color:var(--venc-warn-tx,#856404)">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <span id="lblSelecionados" class="fw-semibold" style="font-size:13px;color:var(--venc-warn-tx,#856404)">0 selecionado(s)</span>
      <form method="post" id="formMassa" onsubmit="return confirmaExclusaoMassa()">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="excluir_massa">
        <input type="hidden" name="ids" id="inputIdsMassa">
        <button type="submit" class="btn btn-danger btn-sm">
          <i class="bi bi-trash me-1"></i>Excluir selecionados
        </button>
      </form>
      <button class="btn btn-outline-secondary btn-sm" onclick="limparSelecao()">
        <i class="bi bi-x me-1"></i>Cancelar seleção
      </button>
    </div>
  </div>
  <?php endif; ?>

  <!-- Lista -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div class="d-flex align-items-center gap-2">
        <?php if ($itens && ($u['perfil'] ?? '') !== 'tecnico'): ?>
        <input type="checkbox" class="form-check-input mt-0" id="chkTodos" title="Selecionar todos" onchange="toggleTodos(this)">
        <?php endif; ?>
        <span><i class="bi bi-list-ul me-2 text-primary"></i>Equipamentos cadastrados</span>
      </div>
      <span class="badge bg-light text-dark border"><?= count($itens) ?> item(ns)</span>
    </div>
    <div class="card-body p-0">
      <?php if (!$itens): ?>
        <div class="text-center text-muted py-5"><i class="bi bi-pc-display" style="font-size:32px;opacity:.3;display:block;margin-bottom:8px"></i>Nenhum equipamento encontrado.</div>
      <?php endif; ?>
      <?php foreach ($itens as $inv):
        $garantiaVence   = $inv['garantia_ate'] && strtotime($inv['garantia_ate']) < strtotime('+60 days') && strtotime($inv['garantia_ate']) >= strtotime('today');
        $garantiaVencida = $inv['garantia_ate'] && strtotime($inv['garantia_ate']) < strtotime('today');

        // Extrai IP, Host e MAC do campo observacoes (itens vindos do scanner)
        $obs = $inv['observacoes'] ?? '';
        $obs_ip  = ''; $obs_host = ''; $obs_mac = ''; $obs_rede = '';
        if (preg_match('/\bIP:\s*([\d\.]+)/',   $obs, $m)) $obs_ip   = $m[1];
        if (preg_match('/\bHost:\s*([^\s|]+)/',  $obs, $m)) $obs_host = $m[1];
        if (preg_match('/\bMAC:\s*([\w:]+)/',    $obs, $m)) $obs_mac  = $m[1];
        if (preg_match('/\bRede:\s*([\d\.\/]+)/', $obs, $m)) $obs_rede = $m[1];

        // Nome de exibição principal: usa marca+modelo; se ambos genéricos, usa host ou IP
        $nome_principal = trim($inv['marca'].' '.$inv['modelo']);
        $m_lower = strtolower(trim($inv['marca'] ?? ''));
        $marca_generica = empty($m_lower)
            || str_contains($m_lower, 'unknown')
            || str_contains($m_lower, 'desconhecido')
            || str_contains($m_lower, 'private')
            || str_contains($m_lower, 'privado')
            || str_contains($m_lower, 'ieee registration')
            || str_contains($m_lower, 'sem identif');
        if ($marca_generica && empty(trim($inv['modelo'] ?? ''))) {
            $nome_principal = $obs_host ?: ($obs_ip ? 'IP '.$obs_ip : 'Sem identificação');
        }
      ?>
      <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom item-row" style="gap:12px">
        <?php if (($u['perfil'] ?? '') !== 'tecnico'): ?>
        <div class="flex-shrink-0">
          <input type="checkbox" class="form-check-input chk-item" value="<?= $inv['id'] ?>" onchange="atualizaSelecao()">
        </div>
        <?php endif; ?>
        <div style="min-width:0;flex:1">
          <div class="fw-semibold text-dark" style="font-size:14px">
            <span class="badge bg-light text-dark border me-1" style="font-size:11px"><?= h($inv['tipo']) ?></span>
            <?= h($nome_principal) ?>
          </div>
          <div class="text-muted" style="font-size:12px;margin-top:2px">
            <?php if ($inv['numero_serie']): ?><i class="bi bi-upc me-1"></i><?= h($inv['numero_serie']) ?> <?php endif; ?>
            <?php if ($inv['patrimonio']): ?> · <i class="bi bi-tag me-1"></i>Pat. <?= h($inv['patrimonio']) ?> <?php endif; ?>
            <?php if ($inv['setor']): ?> · <i class="bi bi-building me-1"></i><?= h($inv['setor']) ?> <?php endif; ?>
            <?php if ($inv['responsavel_nome']): ?> · <i class="bi bi-person me-1"></i><?= h($inv['responsavel_nome']) ?> <?php endif; ?>
            <?php if ($obs_ip): ?> · <i class="bi bi-hdd-network me-1"></i><?= h($obs_ip) ?><?= $obs_rede ? ' <span class="opacity-50">('.h($obs_rede).')</span>' : '' ?> <?php endif; ?>
            <?php if ($obs_host && $nome_principal !== $obs_host): ?> · <i class="bi bi-pc me-1"></i><?= h($obs_host) ?> <?php endif; ?>
            <?php if ($obs_mac): ?> · <span class="font-monospace opacity-50" style="font-size:11px"><?= h($obs_mac) ?></span><?php endif; ?>
          </div>
          <?php if ($garantiaVence): ?>
          <div class="venc-alerta"><i class="bi bi-shield-exclamation me-1"></i>Garantia vence em <?= date('d/m/Y', strtotime($inv['garantia_ate'])) ?></div>
          <?php elseif ($garantiaVencida): ?>
          <div class="venc-vencido"><i class="bi bi-shield-x me-1"></i>Garantia vencida em <?= date('d/m/Y', strtotime($inv['garantia_ate'])) ?></div>
          <?php endif; ?>
        </div>
        <div class="flex-shrink-0">
          <span class="badge <?= $statusColor[$inv['status']] ?? 'bg-secondary text-white' ?>"><?= h($inv['status']) ?></span>
        </div>
        <div class="d-flex gap-1 flex-shrink-0">
          <button type="button" class="btn btn-outline-info btn-xs" title="Ver detalhes"
            onclick='verEquipamento(<?= json_encode([
              'id'              => $inv['id'],
              'tipo'            => $inv['tipo'],
              'marca'           => $inv['marca'],
              'modelo'          => $inv['modelo'],
              'numero_serie'    => $inv['numero_serie'],
              'patrimonio'      => $inv['patrimonio'],
              'imei'            => $inv['imei'],
              'status'          => $inv['status'],
              'setor'           => $inv['setor'],
              'responsavel_nome'=> $inv['responsavel_nome'],
              'valor'           => $inv['valor'],
              'data_aquisicao'  => $inv['data_aquisicao'],
              'garantia_ate'    => $inv['garantia_ate'],
              'observacoes'     => $inv['observacoes'],
              'criado_em'       => $inv['criado_em'],
              'ip'              => $obs_ip,
              'mac'             => $obs_mac,
              'host'            => $obs_host,
              'rede'            => $obs_rede,
            ]) ?>)'>
            <i class="bi bi-eye"></i>
          </button>
          <a href="novo_termo.php?inventario_id=<?= $inv['id'] ?>" class="btn btn-outline-primary btn-xs" title="Gerar Termo de Uso"><i class="bi bi-file-earmark-text"></i></a>
          <a href="qrcode_equipamento.php?id=<?= $inv['id'] ?>" target="_blank" class="btn btn-outline-primary btn-xs" title="Etiqueta QR Code"><i class="bi bi-qr-code"></i></a>
          <a href="?action=editar&id=<?= $inv['id'] ?>" class="btn btn-outline-secondary btn-xs" title="Editar"><i class="bi bi-pencil"></i></a>
          <form method="post" onsubmit="return confirm('Excluir este equipamento?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="excluir">
            <input type="hidden" name="id" value="<?= $inv['id'] ?>">
            <button type="submit" class="btn btn-outline-danger btn-xs" title="Excluir"><i class="bi bi-trash"></i></button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

<!-- Modal Ver Equipamento -->
<div class="modal fade" id="modalVer" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pc-display me-2"></i><span id="mTitulo"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div class="row g-0">
          <!-- Coluna esquerda: dados principais -->
          <div class="col-md-7 p-4 border-end">
            <div class="row g-3" id="mCampos"></div>
          </div>
          <!-- Coluna direita: rede + observações -->
          <div class="col-md-5 p-4" style="background:var(--bg-surface-alt)">
            <div id="mRede" class="mb-3"></div>
            <div id="mObs"></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <a id="mBtnEditar" href="#" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Editar</a>
        <a id="mBtnTermo" href="#" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-text me-1"></i>Gerar Termo</a>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<script>
// ── Ver equipamento ───────────────────────────────────────
function verEquipamento(d) {
  const fmt = v => v || '<span class="text-muted">—</span>';
  const fmtData = v => v ? new Date(v+'T00:00:00').toLocaleDateString('pt-BR') : '<span class="text-muted">—</span>';
  const fmtVal  = v => v ? 'R$ '+parseFloat(v).toLocaleString('pt-BR',{minimumFractionDigits:2}) : '<span class="text-muted">—</span>';

  document.getElementById('mTitulo').innerHTML =
    `<span class="badge bg-light text-dark border me-1" style="font-size:12px">${d.tipo}</span>${d.marca} ${d.modelo||''}`;

  const campos = [
    ['Tipo',         d.tipo,            'bi-tag'],
    ['Marca',        d.marca,           'bi-building'],
    ['Modelo',       d.modelo,          'bi-cpu'],
    ['Nº de Série',  d.numero_serie,    'bi-upc'],
    ['Patrimônio',   d.patrimonio,      'bi-bookmark'],
    ['IMEI / MEID',  d.imei,            'bi-phone'],
    ['Status',       d.status,          'bi-circle-fill'],
    ['Setor',        d.setor,           'bi-building-fill'],
    ['Responsável',  d.responsavel_nome,'bi-person-fill'],
    ['Valor',        null,              'bi-currency-dollar', fmtVal(d.valor)],
    ['Aquisição',    null,              'bi-calendar',        fmtData(d.data_aquisicao)],
    ['Garantia até', null,              'bi-shield-check',    fmtData(d.garantia_ate)],
  ];

  document.getElementById('mCampos').innerHTML = campos.map(([label, val, icon, html]) => `
    <div class="col-6">
      <div class="field-label">
        <i class="bi ${icon} me-1"></i>${label}
      </div>
      <div style="font-size:13.5px;font-weight:500;margin-top:2px">${html ?? fmt(val)}</div>
    </div>`).join('');

  // Rede / IP / MAC
  let redeHtml = '';
  if (d.ip || d.mac || d.host) {
    redeHtml = `<div class="fw-semibold mb-2" class="field-label" style="font-size:12px"><i class="bi bi-hdd-network me-1"></i>Rede</div>`;
    if (d.ip)   redeHtml += `<div class="d-flex justify-content-between mb-1"><span class="text-muted" style="font-size:12px">IP</span><span class="font-monospace" style="font-size:13px">${d.ip}${d.rede?` <small class="text-muted">(${d.rede})</small>`:''}</span></div>`;
    if (d.mac)  redeHtml += `<div class="d-flex justify-content-between mb-1"><span class="text-muted" style="font-size:12px">MAC</span><span class="font-monospace" style="font-size:13px">${d.mac}</span></div>`;
    if (d.host) redeHtml += `<div class="d-flex justify-content-between mb-1"><span class="text-muted" style="font-size:12px">Hostname</span><span class="font-monospace" style="font-size:13px">${d.host}</span></div>`;
  }
  document.getElementById('mRede').innerHTML = redeHtml;

  // Observações
  const obsHtml = d.observacoes ? `
    <div class="fw-semibold mb-2" class="field-label" style="font-size:12px"><i class="bi bi-chat-text me-1"></i>Observações</div>
    <div style="font-size:12.5px;line-height:1.6;word-break:break-word">${d.observacoes.replace(/\|/g,'<br>')}</div>` : '';
  document.getElementById('mObs').innerHTML = obsHtml;

  document.getElementById('mBtnEditar').href = `?action=editar&id=${d.id}`;
  document.getElementById('mBtnTermo').href  = `novo_termo.php?inventario_id=${d.id}`;

  new bootstrap.Modal(document.getElementById('modalVer')).show();
}

// ── Cadastro recolhível ───────────────────────────────────
const _editando = <?= $editando ? 'true' : 'false' ?>;
function toggleCadastro(forcar) {
  const corpo = document.getElementById('corpoCadastro');
  const icon  = document.getElementById('iconCadastro');
  const aberto = corpo.style.display !== 'none';
  const novoEstado = forcar !== undefined ? forcar : !aberto;
  corpo.style.display = novoEstado ? '' : 'none';
  icon.style.transform = novoEstado ? '' : 'rotate(180deg)';
  try { localStorage.setItem('inv_cadastro_aberto', novoEstado ? '1' : '0'); } catch(e){}
}
// Restaura estado: aberto ao editar, ou conforme localStorage
(function() {
  if (_editando) { toggleCadastro(true); return; }
  const salvo = (() => { try { return localStorage.getItem('inv_cadastro_aberto'); } catch(e){ return null; } })();
  toggleCadastro(salvo === '1');
})();

function atualizaSelecao() {
  const checks = document.querySelectorAll('.chk-item:checked');
  const total  = document.querySelectorAll('.chk-item').length;
  const n = checks.length;
  const toolbar = document.getElementById('toolbarMassa');
  document.getElementById('lblSelecionados').textContent = n + ' selecionado(s)';
  toolbar.classList.toggle('d-none', n === 0);
  const chkTodos = document.getElementById('chkTodos');
  if (chkTodos) chkTodos.indeterminate = n > 0 && n < total;
  if (chkTodos) chkTodos.checked = n === total && total > 0;
}

function toggleTodos(cb) {
  document.querySelectorAll('.chk-item').forEach(c => c.checked = cb.checked);
  atualizaSelecao();
}

function limparSelecao() {
  document.querySelectorAll('.chk-item').forEach(c => c.checked = false);
  const chkTodos = document.getElementById('chkTodos');
  if (chkTodos) { chkTodos.checked = false; chkTodos.indeterminate = false; }
  atualizaSelecao();
}

function confirmaExclusaoMassa() {
  const ids = Array.from(document.querySelectorAll('.chk-item:checked')).map(c => c.value);
  if (!ids.length) return false;
  if (!confirm('Excluir ' + ids.length + ' equipamento(s)? Esta ação não pode ser desfeita.')) return false;
  document.getElementById('inputIdsMassa').value = ids.join(',');
  return true;
}
</script>
  <!-- Paginação -->
  <?php if ($total_pags > 1):
    $qs = http_build_query(array_filter(['q'=>$f_q,'tipo'=>$f_tipo,'status'=>$f_status,'setor'=>$f_setor]));
    $base = 'inventario.php?' . ($qs ? $qs.'&' : '');
  ?>
  <nav class="d-flex align-items-center justify-content-between">
    <span class="text-muted" style="font-size:13px">
      Exibindo <?= $offset+1 ?>–<?= min($offset+$por_pag, $total_itens) ?> de <?= $total_itens ?> equipamento(s)
    </span>
    <ul class="pagination pagination-sm mb-0">
      <li class="page-item <?= $pag<=1?'disabled':'' ?>">
        <a class="page-link" href="<?= $base ?>pag=<?= $pag-1 ?>"><i class="bi bi-chevron-left"></i></a>
      </li>
      <?php
      $ini = max(1, $pag-2); $fim = min($total_pags, $pag+2);
      if ($ini > 1): ?><li class="page-item"><a class="page-link" href="<?= $base ?>pag=1">1</a></li><?php if ($ini > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; endif;
      for ($p = $ini; $p <= $fim; $p++):
      ?><li class="page-item <?= $p===$pag?'active':'' ?>"><a class="page-link" href="<?= $base ?>pag=<?= $p ?>"><?= $p ?></a></li><?php endfor;
      if ($fim < $total_pags): if ($fim < $total_pags-1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?><li class="page-item"><a class="page-link" href="<?= $base ?>pag=<?= $total_pags ?>"><?= $total_pags ?></a></li><?php endif; ?>
      <li class="page-item <?= $pag>=$total_pags?'disabled':'' ?>">
        <a class="page-link" href="<?= $base ?>pag=<?= $pag+1 ?>"><i class="bi bi-chevron-right"></i></a>
      </li>
    </ul>
  </nav>
  <?php endif; ?>

</div>

<?php layoutFooter(); ?>
