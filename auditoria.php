<?php
// Painel de campo — técnico confirma ativos durante auditoria
require 'db.php';
requireLogin();
require 'layout.php';

$pdo      = db();
$u        = usuario();
$ciclo_id = (int)($_GET['ciclo_id'] ?? 0);

// Lista ciclos em andamento para seleção
$ciclos_abertos = $pdo->query("
    SELECT ca.*, u.nome AS resp_nome
    FROM ciclos_auditoria ca
    LEFT JOIN usuarios u ON u.id = ca.responsavel_id
    WHERE ca.status = 'Em Andamento'
    ORDER BY ca.criado_em DESC
")->fetchAll();

$ciclo = null;
if ($ciclo_id) {
    $st = $pdo->prepare("SELECT ca.*, u.nome AS resp_nome FROM ciclos_auditoria ca
        LEFT JOIN usuarios u ON u.id = ca.responsavel_id WHERE ca.id=?");
    $st->execute([$ciclo_id]);
    $ciclo = $st->fetch();
    if (!$ciclo || $ciclo['status'] !== 'Em Andamento') {
        flash('Este ciclo não está mais em andamento.', 'warning');
        header('Location: auditoria.php'); exit;
    }
}

layoutHeader('Auditoria de Campo', 'auditoria');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-clipboard2-check-fill text-primary me-2"></i>Auditoria de Campo</h1>
  <?php if (($u['perfil'] ?? '') !== 'tecnico'): ?>
  <a href="ciclos_auditoria.php" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-list-check me-1"></i>Gerenciar Ciclos
  </a>
  <?php endif; ?>
</div>

<?php if (!$ciclo): ?>
<!-- Seleção de ciclo -->
<div class="row justify-content-center">
  <div class="col-lg-6">
    <?php if (empty($ciclos_abertos)): ?>
      <div class="card">
        <div class="card-body text-center py-5">
          <i class="bi bi-clipboard2-x" style="font-size:40px;color:var(--tx-faint)"></i>
          <p class="mt-3 mb-1 fw-semibold">Nenhum ciclo em andamento</p>
          <p class="text-muted small">Um gestor precisa iniciar um ciclo de auditoria primeiro.</p>
          <?php if (($u['perfil'] ?? '') !== 'tecnico'): ?>
          <a href="ciclos_auditoria.php" class="btn btn-primary btn-sm mt-2">Iniciar Ciclo</a>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="card-header"><i class="bi bi-play-circle me-2 text-primary"></i>Selecione o ciclo</div>
        <div class="card-body">
          <?php foreach ($ciclos_abertos as $c): ?>
          <a href="auditoria.php?ciclo_id=<?= $c['id'] ?>" class="d-block p-3 border rounded mb-2 text-decoration-none"
             style="background:var(--bg-surface-alt);transition:.15s" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background='var(--bg-surface-alt)'">
            <div class="fw-semibold" style="color:var(--tx-primary)"><?= h($c['nome']) ?></div>
            <div class="small text-muted mt-1">
              <i class="bi bi-person me-1"></i><?= h($c['resp_nome'] ?? '—') ?>
              <?php if ($c['setor_filtro']): ?>
              · <i class="bi bi-building me-1"></i><?= h($c['setor_filtro']) ?>
              <?php endif; ?>
              · <?= $c['total_verificado'] ?>/<?= $c['total_esperado'] ?> verificados
              <?php if ($c['taxa_acuracia'] !== null): ?>
              · <span class="text-success fw-semibold"><?= $c['taxa_acuracia'] ?>%</span>
              <?php endif; ?>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<!-- Painel de campo do ciclo selecionado -->
<div class="row g-3 mb-3">
  <!-- Progresso -->
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-num text-success" id="stat-acuracia">
        <?= $ciclo['taxa_acuracia'] !== null ? $ciclo['taxa_acuracia'].'%' : '—' ?>
      </div>
      <div class="stat-label">Acurácia atual</div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-num" id="stat-verificados"><?= $ciclo['total_verificado'] ?></div>
      <div class="stat-label">Verificados de <?= $ciclo['total_esperado'] ?></div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-num text-danger" id="stat-diverg">—</div>
      <div class="stat-label">Divergências</div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-num text-warning" id="stat-pend">—</div>
      <div class="stat-label">Pendentes</div>
    </div>
  </div>
</div>

<!-- Barra de progresso -->
<div class="card mb-3">
  <div class="card-body py-2">
    <div class="d-flex justify-content-between align-items-center mb-1">
      <small class="fw-semibold"><?= h($ciclo['nome']) ?></small>
      <small class="text-muted" id="prog-label"><?= $ciclo['total_verificado'] ?>/<?= $ciclo['total_esperado'] ?></small>
    </div>
    <div class="progress" style="height:8px">
      <?php $pct = $ciclo['total_esperado'] > 0 ? round($ciclo['total_verificado'] / $ciclo['total_esperado'] * 100) : 0; ?>
      <div class="progress-bar bg-success" id="prog-bar" style="width:<?= $pct ?>%" role="progressbar"></div>
    </div>
  </div>
</div>

<!-- Busca por patrimônio -->
<div class="card mb-3">
  <div class="card-body">
    <label class="form-label fw-semibold mb-2"><i class="bi bi-search me-1 text-primary"></i>Buscar ativo</label>
    <div class="input-group">
      <input type="text" class="form-control form-control-lg" id="inputBusca"
             placeholder="Patrimônio, número de série ou IMEI…"
             autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
             style="font-family:monospace;letter-spacing:.05em">
      <button class="btn btn-primary" id="btnBuscar" type="button">
        <i class="bi bi-search"></i>
      </button>
      <button class="btn btn-outline-secondary" id="btnQr" type="button" title="Ler QR Code">
        <i class="bi bi-qr-code-scan"></i>
      </button>
    </div>
    <div class="form-text">Ou aponte a câmera via QR Code gerado em <a href="qrcode_equipamento.php" target="_blank">Etiquetas</a>.</div>
  </div>
</div>

<!-- Resultado da busca -->
<div id="resultadoBusca" class="mb-3" style="display:none"></div>

<!-- Lista de todos os itens -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-check me-2 text-primary"></i>Checklist do ciclo</span>
    <div class="d-flex gap-2">
      <button class="btn btn-sm btn-outline-secondary" id="btnFiltroTodos" onclick="filtrarLista('todos')">Todos</button>
      <button class="btn btn-sm btn-outline-warning"   id="btnFiltroPend"  onclick="filtrarLista('pendentes')">Pendentes</button>
      <button class="btn btn-sm btn-outline-danger"    id="btnFiltroDivg"  onclick="filtrarLista('divergencias')">Divergências</button>
    </div>
  </div>
  <div class="card-body p-0">
    <div id="listaItens" style="max-height:520px;overflow-y:auto">
      <div class="text-center py-4 text-muted"><i class="bi bi-arrow-clockwise"></i> Carregando…</div>
    </div>
  </div>
</div>

<!-- Modal de confirmação -->
<div class="modal fade" id="modalVerificar" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-clipboard2-check me-2"></i>Confirmar verificação</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="modalEquip" class="fw-semibold mb-3"></div>
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label small fw-semibold">Status encontrado</label>
            <select class="form-select form-select-sm" id="selStatus">
              <option value="Em Uso">Em Uso</option>
              <option value="Disponível">Disponível</option>
              <option value="Em Manutenção">Em Manutenção</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold">Setor encontrado</label>
            <select class="form-select form-select-sm" id="selSetor">
              <?php foreach ($SETORES as $s): ?>
              <option value="<?= h($s) ?>"><?= h($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold">Responsável encontrado</label>
            <input type="text" class="form-control form-control-sm" id="inpResp" placeholder="Nome do responsável">
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold">Observação <span class="text-muted fw-normal">(opcional)</span></label>
            <textarea class="form-control form-control-sm" id="txtObs" rows="2"></textarea>
          </div>
        </div>
        <!-- Alerta de divergência -->
        <div id="alertaDivergencia" class="alert alert-warning mt-3 d-none small">
          <i class="bi bi-exclamation-triangle-fill me-2"></i>
          <span id="txtDivergencia"></span>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success btn-sm" id="btnConfirmar">
          <i class="bi bi-check-lg me-1"></i>Confirmar
        </button>
      </div>
    </div>
  </div>
</div>

<input type="hidden" id="cicloId" value="<?= $ciclo_id ?>">
<input type="hidden" id="csrfToken" value="<?= csrfToken() ?>">

<script>
const CICLO_ID   = <?= $ciclo_id ?>;
const CSRF_TOKEN = document.getElementById('csrfToken').value;
let todosItens   = [];
let itemAtual    = null;
let modalBS      = null;

// ── Inicialização ────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    modalBS = new bootstrap.Modal(document.getElementById('modalVerificar'));
    carregarLista();
    document.getElementById('btnBuscar').addEventListener('click', buscarAtivo);
    document.getElementById('inputBusca').addEventListener('keydown', e => { if (e.key === 'Enter') buscarAtivo(); });
    document.getElementById('btnConfirmar').addEventListener('click', confirmarItem);

    // Detecta mudança nos campos para alertar divergência
    ['selStatus','selSetor','inpResp'].forEach(id =>
        document.getElementById(id).addEventListener('change', checarDivergencia));

    // QR Code: se patrimônio vem via URL (link do qrcode_equipamento.php)
    const urlParams = new URLSearchParams(window.location.search);
    const qr = urlParams.get('q');
    if (qr) { document.getElementById('inputBusca').value = qr; buscarAtivo(); }
});

// ── Carregar lista completa ──────────────────────────────────────────────────
async function carregarLista() {
    const res = await fetch(`api_auditoria.php?action=checklist&ciclo_id=${CICLO_ID}`);
    const json = await res.json();
    if (!json.ok) return;
    todosItens = json.data;
    atualizarStats();
    renderizarLista(todosItens);
}

function atualizarStats() {
    const pend  = todosItens.filter(i => !i.verificado).length;
    const divg  = todosItens.filter(i => i.divergencia == 1).length;
    const verif = todosItens.filter(i => i.verificado == 1).length;
    const total = todosItens.length;
    const acur  = total > 0 ? Math.round(verif / total * 100 * 100) / 100 : 0;

    document.getElementById('stat-acuracia').textContent  = acur + '%';
    document.getElementById('stat-verificados').textContent = verif;
    document.getElementById('stat-diverg').textContent    = divg;
    document.getElementById('stat-pend').textContent      = pend;
    document.getElementById('prog-label').textContent     = verif + '/' + total;
    document.getElementById('prog-bar').style.width       = acur + '%';
}

// ── Renderizar lista ─────────────────────────────────────────────────────────
function renderizarLista(itens) {
    const el = document.getElementById('listaItens');
    if (!itens.length) {
        el.innerHTML = '<div class="text-center py-4 text-muted">Nenhum item.</div>'; return;
    }
    el.innerHTML = itens.map(i => {
        const ok   = i.verificado == 1 && i.divergencia == 0;
        const divg = i.divergencia == 1;
        const pend = !i.verificado;
        const bg   = divg ? '#fff5f5' : (ok ? '' : '');
        const bdr  = divg ? '2px solid #fecaca' : (ok ? '2px solid #bbf7d0' : '');

        let badge = '';
        if (ok)   badge = '<span class="badge" style="background:#dcfce7;color:#166534">OK</span>';
        else if (divg) badge = '<span class="badge" style="background:#fee2e2;color:#991b1b">Divergência</span>';
        else           badge = '<span class="badge" style="background:#fef3c7;color:#92400e">Pendente</span>';

        const icon = ok
            ? '<i class="bi bi-check-circle-fill text-success"></i>'
            : (divg ? '<i class="bi bi-exclamation-circle-fill text-danger"></i>'
                    : '<i class="bi bi-circle text-muted"></i>');

        return `<div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom"
                     style="background:${bg};border-left:${bdr};cursor:${pend?'pointer':'default'}"
                     onclick="${pend ? `abrirModal(${i.inventario_id})` : ''}">
            ${icon}
            <code class="small text-primary">${h(i.patrimonio||'—')}</code>
            <div class="flex-fill overflow-hidden">
                <div class="fw-semibold text-truncate" style="font-size:13px">${h(i.tipo+' '+i.marca+' '+i.modelo)}</div>
                <div class="small text-muted text-truncate">${h(i.setor_esperado||'—')} · ${h(i.responsavel_esperado||'—')} · S/N: ${h(i.numero_serie||'—')}</div>
                ${divg ? `<div class="small text-danger">Encontrado: ${h(i.setor_encontrado||'—')} · ${h(i.responsavel_encontrado||'—')}</div>` : ''}
            </div>
            ${badge}
        </div>`;
    }).join('');
}

function h(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── Filtros ─────────────────────────────────────────────────────────────────
function filtrarLista(tipo) {
    let itens = todosItens;
    if (tipo === 'pendentes')    itens = todosItens.filter(i => !i.verificado);
    if (tipo === 'divergencias') itens = todosItens.filter(i => i.divergencia == 1);
    renderizarLista(itens);
}

// ── Busca ────────────────────────────────────────────────────────────────────
async function buscarAtivo() {
    const q = document.getElementById('inputBusca').value.trim();
    if (!q) return;
    const el = document.getElementById('resultadoBusca');
    el.innerHTML = '<div class="text-muted small"><i class="bi bi-arrow-clockwise"></i> Buscando…</div>';
    el.style.display = 'block';

    const res  = await fetch(`api_auditoria.php?action=buscar&ciclo_id=${CICLO_ID}&q=${encodeURIComponent(q)}`);
    const json = await res.json();

    if (!json.ok) {
        el.innerHTML = `<div class="alert alert-warning py-2"><i class="bi bi-search me-2"></i>${json.erro}</div>`;
        return;
    }
    const i = json.data;
    const verif = i.verificado == 1;
    el.innerHTML = `
        <div class="card border-2 ${verif ? 'border-success' : 'border-primary'}">
          <div class="card-body">
            <div class="d-flex align-items-start gap-3">
              <i class="bi bi-pc-display text-primary" style="font-size:24px;flex-shrink:0;margin-top:2px"></i>
              <div class="flex-fill">
                <div class="fw-semibold">${h(i.tipo+' '+i.marca+' '+i.modelo)}</div>
                <div class="small text-muted">Patrimônio: <code>${h(i.patrimonio||'—')}</code> · S/N: <code>${h(i.numero_serie||'—')}</code></div>
                <div class="small text-muted">Esperado: <strong>${h(i.setor_esperado||'—')}</strong> · ${h(i.responsavel_esperado||'—')}</div>
              </div>
              ${verif
                ? '<span class="badge bg-success align-self-center">Já verificado</span>'
                : `<button class="btn btn-primary btn-sm align-self-center" onclick="abrirModal(${i.inventario_id})">
                     <i class="bi bi-check me-1"></i>Confirmar
                   </button>`
              }
            </div>
          </div>
        </div>`;
}

// ── Modal de verificação ─────────────────────────────────────────────────────
function abrirModal(invId) {
    itemAtual = todosItens.find(i => i.inventario_id == invId);
    if (!itemAtual) return;

    document.getElementById('modalEquip').textContent =
        itemAtual.tipo + ' ' + itemAtual.marca + ' ' + itemAtual.modelo +
        ' — ' + (itemAtual.patrimonio || itemAtual.numero_serie || '');

    // Pré-preenche com valores esperados
    document.getElementById('selStatus').value = itemAtual.status_esperado || 'Em Uso';
    const selSetor = document.getElementById('selSetor');
    const opt = Array.from(selSetor.options).find(o => o.value === itemAtual.setor_esperado);
    if (opt) selSetor.value = itemAtual.setor_esperado;
    document.getElementById('inpResp').value = itemAtual.responsavel_esperado || '';
    document.getElementById('txtObs').value  = '';
    document.getElementById('alertaDivergencia').classList.add('d-none');
    modalBS.show();
}

function checarDivergencia() {
    if (!itemAtual) return;
    const diffs = [];
    if (document.getElementById('selStatus').value !== itemAtual.status_esperado)
        diffs.push('Status: esperado "'+itemAtual.status_esperado+'"');
    if (document.getElementById('selSetor').value !== itemAtual.setor_esperado)
        diffs.push('Setor: esperado "'+itemAtual.setor_esperado+'"');
    const resp = document.getElementById('inpResp').value.trim();
    if (resp && resp !== itemAtual.responsavel_esperado)
        diffs.push('Responsável: esperado "'+itemAtual.responsavel_esperado+'"');

    const alerta = document.getElementById('alertaDivergencia');
    if (diffs.length) {
        document.getElementById('txtDivergencia').textContent = 'Divergência detectada — ' + diffs.join('; ');
        alerta.classList.remove('d-none');
    } else {
        alerta.classList.add('d-none');
    }
}

async function confirmarItem() {
    if (!itemAtual) return;
    const btn = document.getElementById('btnConfirmar');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando…';

    const body = new FormData();
    body.append('csrf_token',              CSRF_TOKEN);
    body.append('ciclo_id',               CICLO_ID);
    body.append('inventario_id',          itemAtual.inventario_id);
    body.append('status_encontrado',      document.getElementById('selStatus').value);
    body.append('setor_encontrado',       document.getElementById('selSetor').value);
    body.append('responsavel_encontrado', document.getElementById('inpResp').value.trim());
    body.append('observacao',             document.getElementById('txtObs').value.trim());

    const res  = await fetch('api_auditoria.php?action=verificar', { method:'POST', body });
    const json = await res.json();

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Confirmar';

    if (!json.ok) { alert(json.erro); return; }

    modalBS.hide();
    document.getElementById('resultadoBusca').style.display = 'none';
    document.getElementById('inputBusca').value = '';

    // Atualiza localmente sem recarregar
    const idx = todosItens.findIndex(i => i.inventario_id == itemAtual.inventario_id);
    if (idx >= 0) {
        todosItens[idx].verificado            = 1;
        todosItens[idx].divergencia           = json.data.divergencia;
        todosItens[idx].setor_encontrado      = document.getElementById('selSetor').value;
        todosItens[idx].responsavel_encontrado = document.getElementById('inpResp').value.trim();
        todosItens[idx].status_encontrado     = document.getElementById('selStatus').value;
    }
    atualizarStats();
    renderizarLista(todosItens);
}
</script>
<?php endif; ?>

<?php layoutFooter(); ?>
