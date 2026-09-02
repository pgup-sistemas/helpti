<?php
require 'db.php';
requireLogin();
require 'layout.php';

$pdo    = db();
$u      = usuario();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

// Garante a tabela de histórico de renovações
$pdo->exec("CREATE TABLE IF NOT EXISTS `contratos_renovacoes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `contrato_id` int NOT NULL,
  `data_anterior` date NOT NULL,
  `data_nova` date NOT NULL,
  `tipo` enum('auto','manual') NOT NULL,
  `usuario_id` int DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contrato` (`contrato_id`),
  CONSTRAINT `fk_renov_contrato` FOREIGN KEY (`contrato_id`) REFERENCES `contratos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Renovação manual (botão dedicado, com escolha de período) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'renovar') {
    csrfVerify();
    $periodo = $_POST['periodo'] ?? '';
    $data_custom = $_POST['data_custom'] ?? '';

    $st = $pdo->prepare("SELECT data_vencimento FROM contratos WHERE id=?");
    $st->execute([$id]);
    $data_atual = $st->fetchColumn();

    $mapa = ['Mensal'=>'+1 month','Trimestral'=>'+3 months','Semestral'=>'+6 months','Anual'=>'+1 year'];
    if ($periodo === 'Personalizado' && $data_custom) {
        $nova_data = $data_custom;
    } elseif (isset($mapa[$periodo])) {
        $dt = new DateTime($data_atual > date('Y-m-d') ? $data_atual : date('Y-m-d'));
        $dt->modify($mapa[$periodo]);
        $nova_data = $dt->format('Y-m-d');
    } else {
        flash('Selecione um período de renovação válido.', 'danger');
        header('Location: contratos.php'); exit;
    }

    $pdo->prepare("UPDATE contratos SET data_vencimento=?, status='Ativo' WHERE id=?")->execute([$nova_data, $id]);
    $pdo->prepare("INSERT INTO contratos_renovacoes (contrato_id, data_anterior, data_nova, tipo, usuario_id) VALUES (?,?,?,'manual',?)")
        ->execute([$id, $data_atual, $nova_data, $u['id'] ?? null]);

    flash('Contrato renovado até ' . date('d/m/Y', strtotime($nova_data)) . '.');
    header('Location: contratos.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'renovar') {
    csrfVerify();
    $campos = [
        'tipo'             => $_POST['tipo'] ?? 'Contrato',
        'nome'             => trim($_POST['nome'] ?? ''),
        'fornecedor'       => trim($_POST['fornecedor'] ?? ''),
        'numero_contrato'  => trim($_POST['numero_contrato'] ?? ''),
        'valor'            => $_POST['valor'] !== '' ? (float)$_POST['valor'] : null,
        'periodicidade'    => $_POST['periodicidade'] ?? 'Anual',
        'data_inicio'      => $_POST['data_inicio'] ?: null,
        'data_vencimento'  => $_POST['data_vencimento'] ?? '',
        'renovacao_auto'   => isset($_POST['renovacao_auto']) ? 1 : 0,
        'alerta_dias'      => (int)($_POST['alerta_dias'] ?? 30),
        'status'           => $_POST['status'] ?? 'Ativo',
        'observacoes'      => trim($_POST['observacoes'] ?? ''),
        'corpo'            => trim($_POST['corpo'] ?? ''),
    ];

    if (!$campos['nome'] || !$campos['data_vencimento']) {
        flash('Nome e data de vencimento são obrigatórios.', 'danger');
    } elseif ($action === 'criar') {
        $pdo->prepare("INSERT INTO contratos (tipo,nome,fornecedor,numero_contrato,valor,periodicidade,data_inicio,data_vencimento,renovacao_auto,alerta_dias,status,observacoes,corpo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute(array_values($campos));
        flash("Contrato/Licença \"{$campos['nome']}\" cadastrado.");
    } elseif ($action === 'editar' && $id) {
        $vals = array_values($campos); $vals[] = $id;
        $pdo->prepare("UPDATE contratos SET tipo=?,nome=?,fornecedor=?,numero_contrato=?,valor=?,periodicidade=?,data_inicio=?,data_vencimento=?,renovacao_auto=?,alerta_dias=?,status=?,observacoes=?,corpo=? WHERE id=?")
            ->execute($vals);
        flash("Registro atualizado.");
    } elseif ($action === 'excluir' && $id) {
        $pdo->prepare("DELETE FROM contratos WHERE id=?")->execute([$id]);
        flash('Registro removido.');
    }
    header('Location: contratos.php'); exit;
}

$editando = null;
if ($action === 'editar' && $id) {
    $st = $pdo->prepare("SELECT * FROM contratos WHERE id=?");
    $st->execute([$id]);
    $editando = $st->fetch();
}

// Atualiza status vencidos automaticamente (contratos sem renovação automática)
$pdo->exec("UPDATE contratos SET status='Vencido' WHERE data_vencimento < CURDATE() AND status='Ativo' AND renovacao_auto=0");

// Contratos com renovação automática: avança a data de vencimento para o próximo
// período (em vez de deixar uma data vencida marcada como "Ativo" para sempre)
$intervalo_map = ['Mensal'=>'+1 month','Trimestral'=>'+3 months','Semestral'=>'+6 months','Anual'=>'+1 year'];
$auto_vencidos = $pdo->query("SELECT id, data_vencimento, periodicidade FROM contratos
    WHERE data_vencimento < CURDATE() AND status='Ativo' AND renovacao_auto=1")->fetchAll();
foreach ($auto_vencidos as $c) {
    $intervalo = $intervalo_map[$c['periodicidade']] ?? null;
    if (!$intervalo) {
        // "Único" não tem como se renovar automaticamente — marca como vencido de verdade
        $pdo->prepare("UPDATE contratos SET status='Vencido' WHERE id=?")->execute([$c['id']]);
        continue;
    }
    // Avança quantos períodos forem necessários até a data ficar no futuro
    $nova_data = new DateTime($c['data_vencimento']);
    $hoje = new DateTime('today');
    while ($nova_data < $hoje) {
        $nova_data->modify($intervalo);
    }
    $nova_data_str = $nova_data->format('Y-m-d');
    $pdo->prepare("UPDATE contratos SET data_vencimento=? WHERE id=?")
        ->execute([$nova_data_str, $c['id']]);
    $pdo->prepare("INSERT INTO contratos_renovacoes (contrato_id, data_anterior, data_nova, tipo) VALUES (?,?,?,'auto')")
        ->execute([$c['id'], $c['data_vencimento'], $nova_data_str]);
}

// Filtros
$f_tipo   = $_GET['tipo']   ?? '';
$f_status = $_GET['status'] ?? '';
$where = ['1=1']; $params = [];
if ($f_tipo)   { $where[] = 'tipo=?';   $params[] = $f_tipo; }
if ($f_status) { $where[] = 'status=?'; $params[] = $f_status; }

$contratos = $pdo->prepare("SELECT * FROM contratos WHERE ".implode(' AND ',$where)." ORDER BY data_vencimento ASC");
$contratos->execute($params);
$lista = $contratos->fetchAll();

// Stats
$stats = $pdo->query("
  SELECT
    SUM(status='Ativo') AS ativos,
    SUM(status='Vencido') AS vencidos,
    SUM(data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status='Ativo') AS vencendo_30,
    SUM(data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY) AND status='Ativo') AS vencendo_60
  FROM contratos
")->fetch();

$tipos     = ['Contrato','Licença','Garantia','Assinatura','Suporte','Outro'];
$statuses  = ['Ativo','Vencido','Cancelado','Em Renovação'];
$periods   = ['Mensal','Trimestral','Semestral','Anual','Único'];

layoutHeader('Contratos & Licenças', 'contratos');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-file-earmark-check-fill me-2 text-primary"></i>Contratos & Licenças</h1>
  <?php
    $qs_export = http_build_query(['status'=>$f_status,'tipo'=>$f_tipo]);
  ?>
  <a href="exportar_contratos.php?<?= $qs_export ?>" class="btn btn-outline-success btn-sm" title="Exportar lista de contratos em Excel">
    <i class="bi bi-file-earmark-excel me-1"></i>Exportar Excel
  </a>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card"><div class="d-flex justify-content-between align-items-start">
      <div><div class="stat-num" style="color:#22c55e"><?= (int)$stats['ativos'] ?></div><div class="stat-label">Ativos</div></div>
      <i class="bi bi-check-circle-fill" style="font-size:22px;color:#22c55e;opacity:.35"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card" style="border-top:3px solid #f59e0b"><div class="d-flex justify-content-between align-items-start">
      <div><div class="stat-num" style="color:#f59e0b"><?= (int)$stats['vencendo_30'] ?></div><div class="stat-label">Vencendo em 30 dias</div></div>
      <i class="bi bi-clock-history" style="font-size:22px;color:#f59e0b;opacity:.35"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card" style="border-top:3px solid #0ea5e9"><div class="d-flex justify-content-between align-items-start">
      <div><div class="stat-num" style="color:#0ea5e9"><?= (int)$stats['vencendo_60'] ?></div><div class="stat-label">Vencendo em 60 dias</div></div>
      <i class="bi bi-calendar-event" style="font-size:22px;color:#0ea5e9;opacity:.35"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card" style="border-top:3px solid #ef4444"><div class="d-flex justify-content-between align-items-start">
      <div><div class="stat-num" style="color:#ef4444"><?= (int)$stats['vencidos'] ?></div><div class="stat-label">Vencidos</div></div>
      <i class="bi bi-x-circle-fill" style="font-size:22px;color:#ef4444;opacity:.35"></i>
    </div></div>
  </div>
</div>

<div class="d-flex flex-column gap-3">

  <!-- Formulário -->
  <div class="card" id="cardCadastro">
    <div class="card-header d-flex align-items-center justify-content-between" style="cursor:pointer" onclick="toggleCadastro()">
      <span><i class="bi bi-<?= $editando ? 'pencil' : 'plus-circle' ?> me-2"></i><?= $editando ? 'Editar registro' : 'Novo contrato / licença' ?></span>
      <i class="bi bi-chevron-up" id="iconCadastro" style="transition:.2s"></i>
    </div>
    <div class="card-body" id="corpoCadastro">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="<?= $editando ? 'editar' : 'criar' ?>">
        <?php if ($editando): ?><input type="hidden" name="id" value="<?= $editando['id'] ?>"><?php endif; ?>
        <div class="row g-3">
          <div class="col-sm-2">
            <label class="form-label fw-semibold" style="font-size:13px">Tipo *</label>
            <select name="tipo" class="form-select form-select-sm">
              <?php foreach($tipos as $t): ?><option <?= ($editando['tipo']??'Contrato')===$t?'selected':'' ?>><?=$t?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-5">
            <label class="form-label fw-semibold" style="font-size:13px">Nome *</label>
            <input type="text" name="nome" class="form-control form-control-sm" required value="<?= h($editando['nome']??'') ?>" placeholder="Ex: Microsoft 365 Business, Suporte Dell…">
          </div>
          <div class="col-sm-5">
            <label class="form-label fw-semibold" style="font-size:13px">Fornecedor</label>
            <input type="text" name="fornecedor" class="form-control form-control-sm" value="<?= h($editando['fornecedor']??'') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label fw-semibold" style="font-size:13px">Nº do contrato</label>
            <input type="text" name="numero_contrato" class="form-control form-control-sm" value="<?= h($editando['numero_contrato']??'') ?>">
          </div>
          <div class="col-sm-2">
            <label class="form-label fw-semibold" style="font-size:13px">Valor (R$)</label>
            <input type="number" name="valor" step="0.01" min="0" class="form-control form-control-sm" value="<?= h($editando['valor']??'') ?>">
          </div>
          <div class="col-sm-2">
            <label class="form-label fw-semibold" style="font-size:13px">Periodicidade</label>
            <select name="periodicidade" class="form-select form-select-sm">
              <?php foreach($periods as $p): ?><option <?= ($editando['periodicidade']??'Anual')===$p?'selected':'' ?>><?=$p?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-2">
            <label class="form-label fw-semibold" style="font-size:13px">Início</label>
            <input type="date" name="data_inicio" class="form-control form-control-sm" value="<?= h($editando['data_inicio']??'') ?>">
          </div>
          <div class="col-sm-2">
            <label class="form-label fw-semibold" style="font-size:13px">Vencimento *</label>
            <input type="date" name="data_vencimento" class="form-control form-control-sm" required value="<?= h($editando['data_vencimento']??'') ?>">
          </div>
          <div class="col-sm-2">
            <label class="form-label fw-semibold" style="font-size:13px">Alertar (dias antes)</label>
            <input type="number" name="alerta_dias" min="1" max="365" class="form-control form-control-sm" value="<?= h($editando['alerta_dias']??30) ?>">
          </div>
          <div class="col-sm-2">
            <label class="form-label fw-semibold" style="font-size:13px">Status</label>
            <select name="status" class="form-select form-select-sm">
              <?php foreach($statuses as $s): ?><option <?= ($editando['status']??'Ativo')===$s?'selected':'' ?>><?=$s?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-2 d-flex align-items-end">
            <div class="form-check mb-1">
              <input type="checkbox" name="renovacao_auto" class="form-check-input" id="chkRenov" <?= ($editando['renovacao_auto']??0) ? 'checked' : '' ?>>
              <label class="form-check-label" for="chkRenov" style="font-size:13px">Renovação automática</label>
            </div>
          </div>
          <div class="col-sm-10">
            <label class="form-label fw-semibold" style="font-size:13px">Observações internas</label>
            <textarea name="observacoes" rows="2" class="form-control form-control-sm"><?= h($editando['observacoes']??'') ?></textarea>
          </div>
          <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <label class="form-label fw-semibold mb-0" style="font-size:13px">Texto do contrato / cláusulas</label>
              <div class="dropdown">
                <button type="button" class="btn btn-outline-secondary btn-xs dropdown-toggle" data-bs-toggle="dropdown">
                  <i class="bi bi-file-earmark-text me-1"></i>Carregar modelo
                </button>
                <ul class="dropdown-menu dropdown-menu-end" style="font-size:13px;min-width:200px">
                  <li><h6 class="dropdown-header">Selecione o tipo de modelo</h6></li>
                  <li><a class="dropdown-item" href="#" onclick="carregarModelo('contrato');return false"><i class="bi bi-file-earmark-check me-2 text-primary"></i>Contrato de Serviço</a></li>
                  <li><a class="dropdown-item" href="#" onclick="carregarModelo('licenca');return false"><i class="bi bi-key me-2 text-success"></i>Licença de Software</a></li>
                  <li><a class="dropdown-item" href="#" onclick="carregarModelo('garantia');return false"><i class="bi bi-shield-check me-2 text-warning"></i>Garantia de Equipamento</a></li>
                  <li><a class="dropdown-item" href="#" onclick="carregarModelo('assinatura');return false"><i class="bi bi-credit-card me-2 text-info"></i>Assinatura / SaaS</a></li>
                  <li><a class="dropdown-item" href="#" onclick="carregarModelo('suporte');return false"><i class="bi bi-headset me-2 text-danger"></i>Contrato de Suporte</a></li>
                </ul>
              </div>
            </div>
            <div class="form-text mb-2">Escreva o corpo completo do contrato. Cada parágrafo em uma linha separada. Use <code>1. Cláusula: texto…</code> para numerar.</div>
            <textarea name="corpo" id="editorCorpo" rows="14" class="form-control" style="font-size:13px;font-family:monospace"><?= h($editando['corpo']??'') ?></textarea>

            <!-- Prévia com placeholders destacados -->
            <div id="corpoPreviewWrap" style="display:none;margin-top:10px;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden">
              <div class="preview-bar">
                <span class="section-label"><i class="bi bi-eye me-1"></i>Prévia — campos a preencher destacados</span>
                <span id="contadorPlaceholders" style="font-size:11px;color:#f59e0b;font-weight:600"></span>
              </div>
              <div id="corpoPreview" style="padding:1rem 1.25rem;font-size:13px;line-height:1.8;white-space:pre-wrap;font-family:'Segoe UI',system-ui,sans-serif;max-height:340px;overflow-y:auto"></div>
            </div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary btn-sm"><?= $editando ? 'Salvar' : 'Cadastrar' ?></button>
          <?php if ($editando): ?><a href="contratos.php" class="btn btn-outline-secondary btn-sm">Cancelar</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- Filtros -->
  <form method="get" class="card card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-sm-3">
        <select name="tipo" class="form-select form-select-sm">
          <option value="">Todos os tipos</option>
          <?php foreach($tipos as $t): ?><option <?= $f_tipo===$t?'selected':'' ?>><?=$t?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <select name="status" class="form-select form-select-sm">
          <option value="">Todos os status</option>
          <?php foreach($statuses as $s): ?><option <?= $f_status===$s?'selected':'' ?>><?=$s?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2 d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm flex-fill">Filtrar</button>
        <a href="contratos.php" class="btn btn-outline-secondary btn-sm">✕</a>
      </div>
    </div>
  </form>

  <!-- Lista -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span><i class="bi bi-list-ul me-2 text-primary"></i>Contratos e licenças</span>
      <span class="badge bg-light text-dark border"><?= count($lista) ?></span>
    </div>
    <div class="card-body p-0">
      <?php if (!$lista): ?>
        <div class="text-center text-muted py-5"><i class="bi bi-file-earmark" style="font-size:32px;opacity:.3;display:block;margin-bottom:8px"></i>Nenhum registro.</div>
      <?php endif; ?>
      <?php foreach ($lista as $c):
        $diasRestantes = (int)ceil((strtotime($c['data_vencimento']) - time()) / 86400);
        $vencido = $diasRestantes < 0;
        $alerta  = !$vencido && $diasRestantes <= $c['alerta_dias'];
        $statusBadge = match($c['status']) {
          'Ativo'        => 'badge-concluido',
          'Vencido'      => 'badge-pendente',
          'Em Renovação' => 'badge-andamento',
          default        => 'bg-secondary text-white',
        };
      ?>
      <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom <?= $vencido ? 'linha-vencida' : ($alerta ? 'linha-alerta' : '') ?>" style="gap:12px">
        <div style="min-width:0;flex:1">
          <div class="fw-semibold text-dark" style="font-size:14px">
            <span class="badge bg-light text-dark border me-1" style="font-size:11px"><?= h($c['tipo']) ?></span>
            <?= h($c['nome']) ?>
            <?php if ($c['renovacao_auto']): ?><span class="badge badge-aberto ms-1" style="font-size:10px">Renovação Auto</span><?php endif; ?>
          </div>
          <div class="text-muted" style="font-size:12px;margin-top:2px">
            <?php if ($c['fornecedor']): ?><i class="bi bi-building me-1"></i><?= h($c['fornecedor']) ?><?php endif; ?>
            <?php if ($c['numero_contrato']): ?> · Nº <?= h($c['numero_contrato']) ?><?php endif; ?>
            <?php if ($c['valor']): ?> · R$ <?= number_format($c['valor'],2,',','.') ?>/<?= strtolower($c['periodicidade']) ?><?php endif; ?>
          </div>
          <?php if ($vencido): ?>
            <div class="venc-vencido"><i class="bi bi-x-circle me-1"></i>Vencido há <?= abs($diasRestantes) ?> dia(s) — <?= date('d/m/Y', strtotime($c['data_vencimento'])) ?></div>
          <?php elseif ($alerta): ?>
            <div class="venc-alerta"><i class="bi bi-exclamation-triangle me-1"></i>Vence em <?= $diasRestantes ?> dia(s) — <?= date('d/m/Y', strtotime($c['data_vencimento'])) ?></div>
          <?php else: ?>
            <div class="venc-ok"><i class="bi bi-calendar-check me-1"></i>Vence em <?= date('d/m/Y', strtotime($c['data_vencimento'])) ?> (<?= $diasRestantes ?> dias)</div>
          <?php endif; ?>
        </div>
        <div class="flex-shrink-0"><span class="badge <?= $statusBadge ?>"><?= h($c['status']) ?></span></div>
        <div class="d-flex gap-1 flex-shrink-0">
          <button type="button" class="btn btn-outline-primary btn-xs" onclick='abrirView(<?= json_encode($c) ?>)'><i class="bi bi-eye"></i></button>
          <button type="button" class="btn btn-outline-success btn-xs" title="Renovar contrato"
                  data-bs-toggle="modal" data-bs-target="#modalRenovar"
                  data-id="<?= $c['id'] ?>" data-nome="<?= h($c['nome']) ?>" data-periodicidade="<?= h($c['periodicidade']) ?>">
            <i class="bi bi-arrow-repeat"></i>
          </button>
          <a href="imprimir_contrato.php?id=<?= $c['id'] ?>" target="_blank" class="btn btn-outline-dark btn-xs" title="Imprimir / PDF"><i class="bi bi-printer"></i></a>
          <a href="?action=editar&id=<?= $c['id'] ?>" class="btn btn-outline-secondary btn-xs"><i class="bi bi-pencil"></i></a>
          <form method="post" onsubmit="return confirm('Remover este registro?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="excluir">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button type="submit" class="btn btn-outline-danger btn-xs"><i class="bi bi-trash"></i></button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
// ── Templates de contrato ─────────────────────────────────
const _modelos = {
  contrato: `CONTRATO DE PRESTAÇÃO DE SERVIÇOS DE TECNOLOGIA DA INFORMAÇÃO

Pelo presente instrumento particular, as partes abaixo qualificadas celebram o presente Contrato de Prestação de Serviços, que se regerá pelas cláusulas e condições seguintes:

CONTRATANTE: [Nome da Empresa / Instituição]
CNPJ: [00.000.000/0000-00]
Endereço: [Endereço completo]

CONTRATADA (FORNECEDOR): [Nome do Fornecedor]
CNPJ: [00.000.000/0000-00]
Endereço: [Endereço completo]

1. OBJETO DO CONTRATO
O presente contrato tem por objeto a prestação de serviços de [descrever o serviço], conforme especificações técnicas acordadas entre as partes e detalhadas no Anexo I deste instrumento.

2. PRAZO DE VIGÊNCIA
O presente contrato vigorará pelo período de [XX] meses, com início em [data de início] e término em [data de vencimento], podendo ser renovado mediante acordo formal entre as partes com antecedência mínima de [30] dias antes do vencimento.

3. VALOR E FORMA DE PAGAMENTO
3.1. O valor total dos serviços é de R$ [valor], com periodicidade de pagamento [mensal/anual/única].
3.2. O pagamento será realizado até o dia [XX] de cada [mês/ano], mediante emissão de nota fiscal/fatura pela CONTRATADA.
3.3. Em caso de atraso no pagamento, incidirá multa de 2% sobre o valor em aberto, acrescida de juros de 1% ao mês.

4. OBRIGAÇÕES DA CONTRATADA
4.1. Prestar os serviços com qualidade, pontualidade e profissionalismo.
4.2. Designar equipe técnica qualificada para execução dos serviços.
4.3. Manter sigilo absoluto sobre informações e dados do CONTRATANTE.
4.4. Comunicar imediatamente qualquer impedimento na prestação dos serviços.

5. OBRIGAÇÕES DO CONTRATANTE
5.1. Fornecer todas as informações e acessos necessários à execução dos serviços.
5.2. Efetuar os pagamentos nos prazos estabelecidos.
5.3. Designar responsável para acompanhamento e validação dos serviços.

6. RESCISÃO
6.1. Este contrato poderá ser rescindido por qualquer das partes, mediante notificação por escrito com antecedência mínima de [30] dias.
6.2. A rescisão imotivada por parte do CONTRATANTE antes do término do prazo implicará multa de [XX]% sobre o valor remanescente.

7. CONFIDENCIALIDADE
As partes comprometem-se a manter sigilo sobre informações confidenciais obtidas em razão deste contrato, durante a vigência e por [2] anos após o encerramento.

8. FORO
Fica eleito o foro da comarca de [Cidade/Estado] para dirimir eventuais controvérsias oriundas deste instrumento.

[Local e data]

_______________________________          _______________________________
CONTRATANTE                              CONTRATADA`,

  licenca: `LICENÇA DE USO DE SOFTWARE

O presente instrumento regula os termos e condições de uso da licença de software concedida pelo LICENCIANTE ao LICENCIADO, conforme abaixo:

LICENCIANTE (FORNECEDOR): [Nome do Fabricante/Distribuidora]
LICENCIADO: [Nome da Empresa]
CNPJ: [00.000.000/0000-00]

PRODUTO LICENCIADO: [Nome do Software / Plataforma]
VERSÃO: [Versão do produto]
Nº DE LICENÇAS: [quantidade]
TIPO DE LICENÇA: [Perpétua / Assinatura Anual / Por usuário]

1. OBJETO
O LICENCIANTE concede ao LICENCIADO o direito não exclusivo e intransferível de usar o software identificado acima, nas condições estabelecidas neste instrumento.

2. ESCOPO DE USO
2.1. A licença autoriza o uso do software em até [XX] dispositivos ou por até [XX] usuários simultâneos.
2.2. É vedada a sublicença, revenda, engenharia reversa, cópia ou distribuição não autorizada do software.
2.3. O uso está restrito ao ambiente interno do LICENCIADO para fins [comerciais/educacionais/operacionais].

3. VIGÊNCIA E RENOVAÇÃO
3.1. A licença vigorará por [XX] meses/anos, com início em [data de início] e vencimento em [data de vencimento].
3.2. A renovação deverá ser solicitada com [30] dias de antecedência, sob pena de suspensão do acesso ao software.

4. SUPORTE E ATUALIZAÇÕES
4.1. O LICENCIANTE prestará suporte técnico nos canais [e-mail / telefone / portal], em horário [horário de atendimento].
4.2. Atualizações e novas versões [estão / não estão] inclusas nesta licença.

5. VALOR
Valor da licença: R$ [valor] — periodicidade: [mensal/anual/única].
Forma de pagamento: [descrever].

6. RESCISÃO
O descumprimento de qualquer cláusula deste instrumento acarretará rescisão imediata da licença, sem prejuízo de indenização por danos causados.

7. PROPRIEDADE INTELECTUAL
O software permanece propriedade exclusiva do LICENCIANTE. Esta licença não transfere qualquer direito de propriedade intelectual ao LICENCIADO.

[Local e data]

_______________________________          _______________________________
LICENCIADO                               LICENCIANTE`,

  garantia: `TERMO DE GARANTIA DE EQUIPAMENTO

Identificação do Equipamento:
Tipo: [Notebook / Desktop / Servidor / Impressora / Outro]
Marca: [Marca]
Modelo: [Modelo]
Número de Série: [S/N]
Patrimônio / TAG: [número]

FORNECEDOR / FABRICANTE: [Nome]
CNPJ: [00.000.000/0000-00]
Contato de suporte: [telefone / e-mail]

1. COBERTURA DA GARANTIA
1.1. O presente termo assegura ao CONTRATANTE garantia contra defeitos de fabricação, falhas de componentes e problemas de funcionamento decorrentes do uso normal do equipamento.
1.2. A garantia cobre: [peças / mão de obra / atendimento on-site / envio para assistência].

2. PRAZO DE GARANTIA
Início: [data de início]
Término: [data de vencimento]
Vigência total: [XX] meses.

3. PROCEDIMENTO PARA ACIONAMENTO
3.1. Para acionar a garantia, o CONTRATANTE deverá contatar o suporte pelo canal [canal de atendimento], informando número de série e descrição da falha.
3.2. O prazo de atendimento para análise é de [XX] horas úteis após abertura do chamado.
3.3. Em caso de troca ou reparo, o prazo máximo de resolução é de [XX] dias úteis.

4. EXCLUSÕES DE GARANTIA
A garantia não cobre:
- Danos causados por mau uso, quedas, líquidos ou agentes externos.
- Danos por surto de energia ou instalação elétrica inadequada.
- Modificações realizadas por terceiros não autorizados.
- Desgaste natural de consumíveis (bateria, teclado, tela por uso).

5. OBRIGAÇÕES DO CONTRATANTE
5.1. Utilizar o equipamento conforme manual do fabricante.
5.2. Manter o equipamento em ambiente adequado (temperatura, umidade, ventilação).
5.3. Não efetuar reparos por conta própria ou por terceiros não autorizados.

[Local e data]

_______________________________          _______________________________
CONTRATANTE                              FORNECEDOR / FABRICANTE`,

  assinatura: `CONTRATO DE ASSINATURA DE SERVIÇO (SaaS / Plataforma Digital)

CONTRATANTE: [Nome da Empresa]
CNPJ: [00.000.000/0000-00]
Responsável: [Nome do responsável]
E-mail: [e-mail]

PLATAFORMA / SERVIÇO: [Nome da plataforma]
FORNECEDOR: [Nome do fornecedor]
CNPJ: [00.000.000/0000-00]
Site: [URL da plataforma]

1. OBJETO
Assinatura de acesso à plataforma [nome], modalidade [plano contratado], com as funcionalidades descritas no Anexo I — Escopo de Funcionalidades.

2. PLANO E LIMITES DE USO
Plano: [Nome do plano]
Usuários incluídos: [quantidade]
Armazenamento: [GB/TB]
Funcionalidades adicionais: [descrever, se houver]

3. VIGÊNCIA
Período de assinatura: [mensal / anual]
Início: [data de início]
Próxima renovação / vencimento: [data de vencimento]
Renovação automática: [Sim / Não]

4. VALOR E PAGAMENTO
4.1. Valor da assinatura: R$ [valor] por [mês/ano].
4.2. Cobrança via [cartão de crédito / boleto / PIX / nota fiscal].
4.3. Em caso de não renovação, o acesso será suspenso em [XX] dias após o vencimento.

5. POLÍTICA DE DADOS E PRIVACIDADE
5.1. Os dados inseridos na plataforma pelo CONTRATANTE são de sua exclusiva propriedade.
5.2. O FORNECEDOR compromete-se a não compartilhar dados com terceiros sem consentimento expresso.
5.3. Em caso de rescisão, o CONTRATANTE terá [XX] dias para exportar seus dados antes da exclusão definitiva.

6. SLA (Disponibilidade)
O FORNECEDOR garante disponibilidade mínima de [99%] do serviço por mês, excluindo janelas de manutenção programada previamente comunicadas.

7. CANCELAMENTO
O cancelamento pode ser solicitado a qualquer momento via [canal de cancelamento], com efeito ao final do período pago vigente.

[Local e data]`,

  suporte: `CONTRATO DE SUPORTE TÉCNICO E MANUTENÇÃO

CONTRATANTE: [Nome da Empresa / Instituição]
CNPJ: [00.000.000/0000-00]
Endereço: [Endereço]
Responsável TI: [Nome]

CONTRATADA (SUPORTE): [Nome da Empresa de Suporte]
CNPJ: [00.000.000/0000-00]
Contato: [telefone / e-mail]

1. OBJETO
Prestação de serviços de suporte técnico, manutenção preventiva e corretiva dos ativos de TI do CONTRATANTE, conforme descrito neste contrato.

2. ESCOPO DOS SERVIÇOS
2.1. Suporte remoto via [telefone / e-mail / sistema de chamados] para resolução de incidentes.
2.2. Manutenção preventiva [mensal / trimestral] dos equipamentos listados no Anexo I.
2.3. Atendimento presencial para ocorrências que não possam ser resolvidas remotamente.
2.4. Monitoramento de [servidores / rede / backup / outros].

3. SLA — NÍVEL DE SERVIÇO
| Nível       | Tempo de resposta | Tempo de resolução |
|-------------|-------------------|--------------------|
| Crítico     | até 1 hora        | até 4 horas úteis  |
| Alta        | até 2 horas       | até 8 horas úteis  |
| Média       | até 4 horas       | até 2 dias úteis   |
| Baixa       | até 1 dia útil    | até 5 dias úteis   |

4. HORÁRIO DE ATENDIMENTO
Suporte remoto: [horário de atendimento, ex: seg–sex 08h–18h]
Plantão emergencial: [horário e condições de cobrança adicional, se aplicável]

5. VALOR E PAGAMENTO
5.1. Valor mensal da mensalidade: R$ [valor].
5.2. Chamados fora do escopo serão cobrados à parte, mediante aprovação prévia, no valor de R$ [valor/hora].
5.3. Pagamento até o dia [XX] de cada mês, via [forma de pagamento].

6. ATIVOS COBERTOS
Os serviços abrangem os equipamentos e sistemas listados no Anexo I — Inventário Coberto, podendo ser atualizado mediante aditivo.

7. VIGÊNCIA E RENOVAÇÃO
Início: [data de início]
Término: [data de vencimento]
Renovação automática: [Sim / Não] — aviso prévio de [30] dias.

8. PENALIDADES POR DESCUMPRIMENTO DE SLA
O descumprimento do SLA acordado por mais de [XX]% dos chamados em um mês ensejará desconto de [XX]% na mensalidade do período.

[Local e data]

_______________________________          _______________________________
CONTRATANTE                              CONTRATADA`,
};

function atualizarPreview() {
  const editor  = document.getElementById('editorCorpo');
  const wrap    = document.getElementById('corpoPreviewWrap');
  const preview = document.getElementById('corpoPreview');
  const contador = document.getElementById('contadorPlaceholders');
  const texto   = editor.value;

  if (!texto.trim()) { wrap.style.display = 'none'; return; }

  wrap.style.display = '';

  // Escapa HTML e destaca [placeholders]
  const escapado = texto
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const destacado = escapado.replace(/\[([^\]]+)\]/g,
    '<mark style="background:#fef3c7;color:#92400e;border-radius:4px;padding:1px 5px;font-weight:600;border:1px solid #fcd34d">[$1]</mark>');
  preview.innerHTML = destacado;

  const total = (texto.match(/\[[^\]]+\]/g) || []).length;
  if (total > 0) {
    contador.textContent = total + ' campo(s) a preencher';
    contador.style.display = '';
  } else {
    contador.textContent = '✓ Nenhum placeholder pendente';
    contador.style.color = '#22c55e';
  }
}

function carregarModelo(tipo) {
  const editor = document.getElementById('editorCorpo');
  if (editor.value.trim() && !confirm('O campo já tem conteúdo. Substituir pelo modelo?')) return;
  editor.value = _modelos[tipo] || '';
  editor.focus();
  atualizarPreview();
  // Garante que o card esteja aberto
  const corpo = document.getElementById('corpoCadastro');
  if (corpo && corpo.style.display === 'none') toggleCadastro(true);
}

// Atualiza prévia ao digitar
// ── Cadastro recolhível ───────────────────────────────────
const _editando = <?= $editando ? 'true' : 'false' ?>;
function toggleCadastro(forcar) {
  const corpo = document.getElementById('corpoCadastro');
  const icon  = document.getElementById('iconCadastro');
  const aberto = corpo.style.display !== 'none';
  const novoEstado = forcar !== undefined ? forcar : !aberto;
  corpo.style.display = novoEstado ? '' : 'none';
  icon.style.transform = novoEstado ? '' : 'rotate(180deg)';
  try { localStorage.setItem('con_cadastro_aberto', novoEstado ? '1' : '0'); } catch(e){}
}
// Restaura estado: aberto ao editar, ou conforme localStorage
(function() {
  if (_editando) { toggleCadastro(true); return; }
  const salvo = (() => { try { return localStorage.getItem('con_cadastro_aberto'); } catch(e){ return null; } })();
  toggleCadastro(salvo === '1');
})();

// Editor de corpo — prévia ao vivo
document.addEventListener('DOMContentLoaded', () => {
  const editor = document.getElementById('editorCorpo');
  if (editor) {
    editor.addEventListener('input', atualizarPreview);
    if (editor.value.trim()) atualizarPreview();
  }
});
</script>

<!-- Modal de renovação manual -->
<div class="modal fade" id="modalRenovar" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="renovar">
        <input type="hidden" name="id" id="renovId">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-arrow-repeat me-2 text-success"></i>Renovar Contrato</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="mb-3">Contrato: <strong id="renovNome"></strong></p>
          <div class="mb-3">
            <label class="form-label fw-semibold" style="font-size:13px">Período de renovação</label>
            <select name="periodo" id="renovPeriodo" class="form-select" required>
              <option value="Mensal">Mensal (+1 mês)</option>
              <option value="Trimestral">Trimestral (+3 meses)</option>
              <option value="Semestral">Semestral (+6 meses)</option>
              <option value="Anual">Anual (+1 ano)</option>
              <option value="Personalizado">Data personalizada...</option>
            </select>
            <div class="form-text">A data atual do contrato é usada como base (ou hoje, se já estiver vencida).</div>
          </div>
          <div class="mb-1" id="renovDataCustomWrap" style="display:none">
            <label class="form-label fw-semibold" style="font-size:13px">Nova data de vencimento</label>
            <input type="date" name="data_custom" id="renovDataCustom" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Confirmar Renovação</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('modalRenovar').addEventListener('show.bs.modal', function(ev) {
  const btn = ev.relatedTarget;
  document.getElementById('renovId').value = btn.dataset.id;
  document.getElementById('renovNome').textContent = btn.dataset.nome;
  const sel = document.getElementById('renovPeriodo');
  if (btn.dataset.periodicidade && ['Mensal','Trimestral','Semestral','Anual'].includes(btn.dataset.periodicidade)) {
    sel.value = btn.dataset.periodicidade;
  }
});
document.getElementById('renovPeriodo').addEventListener('change', function() {
  document.getElementById('renovDataCustomWrap').style.display = this.value === 'Personalizado' ? 'block' : 'none';
});
</script>

<!-- Modal de visualização -->
<div class="modal fade" id="modalView" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-check-fill me-2 text-primary"></i><span id="mv-nome"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-sm-6">
            <div class="mb-3">
              <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em">Tipo</div>
              <div class="fw-semibold" id="mv-tipo"></div>
            </div>
            <div class="mb-3">
              <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em">Fornecedor</div>
              <div id="mv-fornecedor" class="fw-semibold"></div>
            </div>
            <div class="mb-3">
              <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em">Nº do Contrato</div>
              <div id="mv-numero" class="fw-semibold"></div>
            </div>
            <div class="mb-3">
              <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em">Valor / Periodicidade</div>
              <div id="mv-valor" class="fw-semibold"></div>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="mb-3">
              <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em">Status</div>
              <div id="mv-status"></div>
            </div>
            <div class="mb-3">
              <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em">Data de Início</div>
              <div id="mv-inicio" class="fw-semibold"></div>
            </div>
            <div class="mb-3">
              <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em">Vencimento</div>
              <div id="mv-vencimento" class="fw-semibold"></div>
            </div>
            <div class="mb-3">
              <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em">Alerta (dias antes)</div>
              <div id="mv-alerta" class="fw-semibold"></div>
            </div>
            <div class="mb-3">
              <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em">Renovação Automática</div>
              <div id="mv-renovacao" class="fw-semibold"></div>
            </div>
          </div>
          <div class="col-12" id="mv-obs-wrap" style="display:none">
            <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em">Observações internas</div>
            <div id="mv-obs" class="fw-semibold mt-1" style="white-space:pre-wrap;background:var(--bs-light);border-radius:6px;padding:10px;font-size:13px"></div>
          </div>
          <div class="col-12">
            <div class="text-muted mb-1" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em">Texto do contrato / cláusulas</div>
            <div id="mv-corpo" style="white-space:pre-wrap;border:1px solid #e2e8f0;border-radius:6px;padding:14px;font-size:13px;line-height:1.7;max-height:400px;overflow-y:auto"></div>
            <div id="mv-corpo-vazio" style="display:none;border:1px dashed #e2e8f0;border-radius:6px;padding:24px;text-align:center;color:#94a3b8;font-size:13px">
              <i class="bi bi-file-earmark-text" style="font-size:28px;display:block;margin-bottom:8px;opacity:.4"></i>
              Nenhum texto cadastrado para este contrato.<br>
              <a id="mv-edit-link" href="#" style="font-size:12px;margin-top:6px;display:inline-block">Clique em Editar para adicionar o texto →</a>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <a id="mv-print-btn" href="#" target="_blank" class="btn btn-outline-dark btn-sm"><i class="bi bi-printer me-1"></i>Imprimir / PDF</a>
        <a id="mv-edit-btn" href="#" class="btn btn-primary btn-sm"><i class="bi bi-pencil me-1"></i>Editar</a>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<script>
function abrirView(c) {
  const fmt = d => d ? d.split('-').reverse().join('/') : '—';
  const fmtVal = (v, p) => v ? 'R$ ' + parseFloat(v).toLocaleString('pt-BR', {minimumFractionDigits:2}) + ' / ' + p.toLowerCase() : '—';
  const statusClass = {Ativo:'badge-concluido', Vencido:'badge-pendente', 'Em Renovação':'badge-andamento'};

  document.getElementById('mv-nome').textContent      = c.nome;
  document.getElementById('mv-tipo').textContent      = c.tipo;
  document.getElementById('mv-fornecedor').textContent= c.fornecedor || '—';
  document.getElementById('mv-numero').textContent    = c.numero_contrato || '—';
  document.getElementById('mv-valor').textContent     = fmtVal(c.valor, c.periodicidade);
  document.getElementById('mv-inicio').textContent    = fmt(c.data_inicio);
  document.getElementById('mv-vencimento').textContent= fmt(c.data_vencimento);
  document.getElementById('mv-alerta').textContent    = c.alerta_dias + ' dias';
  document.getElementById('mv-renovacao').textContent = c.renovacao_auto == 1 ? 'Sim' : 'Não';
  document.getElementById('mv-edit-btn').href         = '?action=editar&id=' + c.id;
  document.getElementById('mv-print-btn').href        = 'imprimir_contrato.php?id=' + c.id;

  const statusEl = document.getElementById('mv-status');
  statusEl.innerHTML = '<span class="badge ' + (statusClass[c.status] || 'bg-secondary text-white') + '">' + c.status + '</span>';

  const obsWrap = document.getElementById('mv-obs-wrap');
  if (c.observacoes && c.observacoes.trim()) {
    document.getElementById('mv-obs').textContent = c.observacoes;
    obsWrap.style.display = '';
  } else {
    obsWrap.style.display = 'none';
  }

  const editLink = document.getElementById('mv-edit-link');
  if (editLink) editLink.href = '?action=editar&id=' + c.id;

  if (c.corpo && c.corpo.trim()) {
    document.getElementById('mv-corpo').textContent = c.corpo;
    document.getElementById('mv-corpo').style.display = '';
    document.getElementById('mv-corpo-vazio').style.display = 'none';
  } else {
    document.getElementById('mv-corpo').style.display = 'none';
    document.getElementById('mv-corpo-vazio').style.display = '';
  }

  new bootstrap.Modal(document.getElementById('modalView')).show();
}
</script>

<?php layoutFooter(); ?>
