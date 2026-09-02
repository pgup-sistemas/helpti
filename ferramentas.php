<?php
require 'db.php';
requireAdmin();
require 'layout.php';

$pdo = db();

// Listas para filtros do export
$setores_inv = $pdo->query("SELECT DISTINCT setor FROM inventario WHERE setor IS NOT NULL ORDER BY setor")->fetchAll(PDO::FETCH_COLUMN);
$tipos_inv   = $pdo->query("SELECT DISTINCT tipo FROM inventario WHERE tipo IS NOT NULL ORDER BY tipo")->fetchAll(PDO::FETCH_COLUMN);

// CSV mais recente (para o painel de scan)
$csvs = glob(__DIR__ . '/scan_rede_*.csv');
usort($csvs, fn($a,$b) => filemtime($b) - filemtime($a));
$csv_ultimo = $csvs[0] ?? null;

layoutHeader('Ferramentas TI', 'ferramentas');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-tools me-2 text-primary"></i>Ferramentas de TI</h1>
</div>

<!-- Grid de 4 ferramentas -->
<div class="row g-3">

  <!-- ══════════════════════════════════════════════════════════════
       1. SCANNER DE REDE
  ══════════════════════════════════════════════════════════════ -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-radar text-primary fs-5"></i>
        <strong>Scanner de Rede</strong>
        <span id="scan-badge" class="badge bg-secondary ms-auto" style="font-size:11px">Parado</span>
      </div>
      <div class="card-body">
        <p class="text-muted mb-3" style="font-size:13px">
          Executa o <code>scanner_rede.py</code> para descobrir todos os hosts da rede via ARP, identificar tipo, marca e hostname. Atualiza automaticamente a tabela <a href="hosts_rede.php">Hosts de Rede</a> e gera um CSV para importação.
        </p>

        <div class="mb-3">
          <label class="form-label fw-semibold" style="font-size:12px">Redes (CIDR) — opcional, uma por linha</label>
          <textarea id="scan-redes" class="form-control form-control-sm" rows="3"
            placeholder="Ex:&#10;192.168.1.0/24&#10;10.0.0.0/24&#10;(vazio = auto-detecção)"
            style="font-family:monospace;font-size:12px"></textarea>
        </div>

        <div class="d-flex gap-2 mb-3">
          <button id="btn-scan-iniciar" class="btn btn-primary btn-sm fw-semibold">
            <i class="bi bi-play-fill me-1"></i>Iniciar scan
          </button>
          <button id="btn-scan-parar" class="btn btn-outline-danger btn-sm" style="display:none">
            <i class="bi bi-stop-fill me-1"></i>Parar
          </button>
          <?php if ($csv_ultimo): ?>
          <a href="api_ferramentas.php?action=baixar_csv&arquivo=<?= urlencode(basename($csv_ultimo)) ?>"
             class="btn btn-outline-success btn-sm ms-auto">
            <i class="bi bi-file-earmark-arrow-down me-1"></i>Último CSV
            <span class="text-muted ms-1" style="font-size:10px"><?= date('d/m H:i', filemtime($csv_ultimo)) ?></span>
          </a>
          <?php endif; ?>
        </div>

        <!-- Log em tempo real -->
        <div id="scan-log-box" style="display:none">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="fw-semibold" style="font-size:12px">Log em tempo real</span>
            <span id="scan-progresso" class="text-muted" style="font-size:11px"></span>
          </div>
          <pre id="scan-log" style="background:#0f172a;color:#a3e635;padding:10px;border-radius:8px;font-size:11px;max-height:200px;overflow-y:auto;margin:0"></pre>
          <div id="scan-csv-link" class="mt-2" style="display:none">
            <div class="alert alert-success py-2 px-3 mb-0 d-flex align-items-center gap-2" style="font-size:13px">
              <i class="bi bi-check-circle-fill text-success"></i>
              <strong>Scan concluído!</strong>
              <a id="scan-csv-href" href="#" class="btn btn-success btn-sm ms-auto">
                <i class="bi bi-file-earmark-arrow-down me-1"></i>Baixar CSV
              </a>
              <a id="scan-importar-href" href="importar_inventario.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-upload me-1"></i>Importar
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════
       2. VERIFICAR HOST
  ══════════════════════════════════════════════════════════════ -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-hdd-network text-primary fs-5"></i>
        <strong>Verificar Host</strong>
      </div>
      <div class="card-body">
        <p class="text-muted mb-3" style="font-size:13px">
          Diagnostica um host pela rede: ping, latência, hostname DNS, MAC e portas abertas. Útil para checar equipamentos sem sair do sistema.
        </p>

        <div class="input-group mb-3">
          <input type="text" id="host-ip" class="form-control form-control-sm"
            placeholder="Ex: 192.168.1.50" style="font-family:monospace;max-width:200px">
          <button id="btn-verificar" class="btn btn-primary btn-sm fw-semibold">
            <i class="bi bi-search me-1"></i>Verificar
          </button>
        </div>

        <div id="host-resultado" style="display:none">
          <!-- Status online/offline -->
          <div class="d-flex align-items-center gap-2 mb-3 p-2 rounded" id="host-status-box">
            <span id="host-status-ico" style="font-size:24px"></span>
            <div>
              <div id="host-status-texto" class="fw-semibold" style="font-size:14px"></div>
              <div id="host-hostname" class="text-muted" style="font-size:12px"></div>
            </div>
            <div class="ms-auto text-end">
              <div id="host-latencia" style="font-size:12px;color:#6b7280"></div>
              <div id="host-mac" style="font-family:monospace;font-size:11px;color:#9ca3af"></div>
            </div>
          </div>

          <!-- Portas -->
          <div>
            <div class="fw-semibold mb-1" style="font-size:12px">Portas abertas</div>
            <div id="host-portas" class="d-flex flex-wrap gap-1"></div>
          </div>

          <!-- SNMP (impressoras) -->
          <div id="host-snmp" style="display:none;margin-top:12px">
            <div class="fw-semibold mb-1" style="font-size:12px">SNMP — Impressora</div>
            <div id="host-snmp-dados" class="d-flex flex-wrap gap-2"></div>
          </div>
        </div>
        <div id="host-loading" style="display:none;font-size:13px" class="text-muted">
          <i class="bi bi-hourglass-split me-1"></i>Verificando...
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════
       5. COLETAR SNMP DE IMPRESSORAS
  ══════════════════════════════════════════════════════════════ -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-printer-fill text-primary fs-5"></i>
        <strong>Coleta SNMP — Impressoras</strong>
        <span id="snmp-badge" class="badge bg-secondary ms-auto" style="font-size:11px">Parado</span>
      </div>
      <div class="card-body">
        <p class="text-muted mb-3" style="font-size:13px">
          Consulta todas as impressoras ativas com IP via SNMP e registra o total de páginas impressas e nível de toner. Use para atualizar o monitoramento sem aguardar o cron.
        </p>
        <div class="d-flex gap-2 mb-3">
          <button id="btn-snmp-coletar" class="btn btn-primary btn-sm fw-semibold">
            <i class="bi bi-broadcast me-1"></i>Coletar agora
          </button>
          <a href="impressoras.php" class="btn btn-outline-secondary btn-sm ms-auto">
            <i class="bi bi-list-ul me-1"></i>Ver impressoras
          </a>
        </div>
        <div id="snmp-log-box" style="display:none">
          <pre id="snmp-log" style="background:#0f172a;color:#a3e635;padding:10px;border-radius:8px;font-size:11px;max-height:200px;overflow-y:auto;margin:0"></pre>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════
       3. EXPORTAR INVENTÁRIO
  ══════════════════════════════════════════════════════════════ -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-file-earmark-spreadsheet text-primary fs-5"></i>
        <strong>Exportar Inventário</strong>
      </div>
      <div class="card-body">
        <p class="text-muted mb-3" style="font-size:13px">
          Gera um CSV do inventário atual pronto para abrir no Excel ou LibreOffice, com filtros opcionais por setor, tipo e status.
        </p>

        <div class="row g-2 mb-3">
          <div class="col-sm-4">
            <label class="form-label fw-semibold" style="font-size:12px">Setor</label>
            <select id="exp-setor" class="form-select form-select-sm">
              <option value="">Todos</option>
              <?php foreach ($setores_inv as $s): ?>
              <option><?= h($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-semibold" style="font-size:12px">Tipo</label>
            <select id="exp-tipo" class="form-select form-select-sm">
              <option value="">Todos</option>
              <?php foreach ($tipos_inv as $t): ?>
              <option><?= h($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-semibold" style="font-size:12px">Status</label>
            <select id="exp-status" class="form-select form-select-sm">
              <option value="">Todos</option>
              <option>Em Uso</option>
              <option>Disponível</option>
              <option>Em Manutenção</option>
              <option>Descartado</option>
            </select>
          </div>
        </div>

        <div class="d-flex gap-2 align-items-center">
          <button id="btn-exportar" class="btn btn-success btn-sm fw-semibold">
            <i class="bi bi-download me-1"></i>Baixar CSV
          </button>
          <span class="text-muted" style="font-size:12px">Separador: ponto e vírgula ( ; ) — compatível Excel/LibreOffice</span>
        </div>

        <!-- Estatísticas rápidas -->
        <hr class="my-3">
        <div class="row g-2 text-center" style="font-size:12px">
          <?php
          $stats = $pdo->query("SELECT status, COUNT(*) AS n FROM inventario GROUP BY status")->fetchAll();
          $total_inv = array_sum(array_column($stats, 'n'));
          ?>
          <div class="col-6 col-sm-3">
            <div class="fw-bold" style="font-size:20px;color:var(--brand)"><?= $total_inv ?></div>
            <div class="text-muted">Total</div>
          </div>
          <?php foreach ($stats as $st): ?>
          <div class="col-6 col-sm-3">
            <div class="fw-bold" style="font-size:18px"><?= $st['n'] ?></div>
            <div class="text-muted"><?= h($st['status']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════
       4. CHAMADOS POR TÉCNICO
  ══════════════════════════════════════════════════════════════ -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-person-lines-fill text-primary fs-5"></i>
        <strong>Carga por Técnico</strong>
        <button id="btn-refresh-tecnicos" class="btn btn-outline-secondary btn-xs ms-auto" style="font-size:11px">
          <i class="bi bi-arrow-clockwise"></i>
        </button>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover mb-0" style="font-size:13px">
          <thead>
            <tr>
              <th style="padding-left:1rem">Técnico</th>
              <th class="text-center">Abertos</th>
              <th class="text-center">Concluídos (mês)</th>
              <th class="text-center">SLA Vencidos</th>
            </tr>
          </thead>
          <tbody id="tb-tecnicos">
            <tr><td colspan="4" class="text-center text-muted py-3" style="font-size:13px">Carregando...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /row -->

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;

// ══════════════════════════════════════════════════════════════
// 1. SCANNER DE REDE
// ══════════════════════════════════════════════════════════════
let scanInterval = null;

function scanPoll() {
  fetch('api_ferramentas.php?action=scan_status')
    .then(r => r.json())
    .then(d => {
      if (!d.ok) return;
      const log = document.getElementById('scan-log');
      if (d.log) { log.textContent = d.log; log.scrollTop = log.scrollHeight; }

      if (d.csv) {
        document.getElementById('scan-csv-link').style.display = 'block';
        document.getElementById('scan-csv-href').href = 'api_ferramentas.php?action=baixar_csv&arquivo=' + encodeURIComponent(d.csv);
      }

      if (!d.rodando) {
        clearInterval(scanInterval); scanInterval = null;
        document.getElementById('scan-badge').textContent = 'Concluído';
        document.getElementById('scan-badge').className = 'badge bg-success ms-auto';
        document.getElementById('btn-scan-iniciar').disabled = false;
        document.getElementById('btn-scan-parar').style.display = 'none';
        document.getElementById('scan-progresso').textContent = 'Finalizado';
      } else {
        // Extrai linha de progresso do log
        const linhas = (d.log || '').split('\n').filter(l => l.trim());
        const ultima = linhas[linhas.length - 1] || '';
        document.getElementById('scan-progresso').textContent = ultima.replace(/\x1b\[[0-9;]*m/g, '').substring(0, 80);
      }
    });
}

document.getElementById('btn-scan-iniciar').addEventListener('click', function() {
  const redes = document.getElementById('scan-redes').value;
  this.disabled = true;
  document.getElementById('scan-log-box').style.display = 'block';
  document.getElementById('scan-csv-link').style.display = 'none';
  document.getElementById('scan-log').textContent = 'Iniciando scan...';
  document.getElementById('scan-badge').textContent = 'Rodando';
  document.getElementById('scan-badge').className = 'badge bg-warning text-dark ms-auto';
  document.getElementById('btn-scan-parar').style.display = '';

  fetch('api_ferramentas.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams({action: 'scan_iniciar', redes, csrf_token: CSRF})
  })
  .then(r => r.json())
  .then(d => {
    if (!d.ok) {
      alert('Erro: ' + d.erro);
      this.disabled = false;
      document.getElementById('scan-badge').textContent = 'Parado';
      document.getElementById('scan-badge').className = 'badge bg-secondary ms-auto';
      return;
    }
    scanInterval = setInterval(scanPoll, 2000);
    scanPoll();
  });
});

document.getElementById('btn-scan-parar').addEventListener('click', function() {
  if (scanInterval) { clearInterval(scanInterval); scanInterval = null; }
  // Mata o processo via kill do PID — PHP armazena no arquivo
  fetch('api_ferramentas.php?action=scan_status').then(r=>r.json()).then(d=>{
    if (d.pid) fetch('api_ferramentas.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body: new URLSearchParams({action:'scan_iniciar', csrf_token: CSRF})});
  });
  document.getElementById('scan-badge').textContent = 'Parado';
  document.getElementById('scan-badge').className = 'badge bg-secondary ms-auto';
  document.getElementById('btn-scan-iniciar').disabled = false;
  this.style.display = 'none';
});

// Verifica se há scan em execução ao carregar
scanPoll();

// ══════════════════════════════════════════════════════════════
// 2. VERIFICAR HOST
// ══════════════════════════════════════════════════════════════
document.getElementById('btn-verificar').addEventListener('click', verificarHost);
document.getElementById('host-ip').addEventListener('keydown', e => { if (e.key === 'Enter') verificarHost(); });

function verificarHost() {
  const ip = document.getElementById('host-ip').value.trim();
  if (!ip) return;
  document.getElementById('host-resultado').style.display = 'none';
  document.getElementById('host-loading').style.display = 'block';
  document.getElementById('btn-verificar').disabled = true;

  fetch('api_ferramentas.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams({action: 'verificar_host', ip, csrf_token: CSRF})
  })
  .then(r => r.json())
  .then(d => {
    document.getElementById('host-loading').style.display = 'none';
    document.getElementById('btn-verificar').disabled = false;
    if (!d.ok) { alert('Erro: ' + d.erro); return; }

    const box = document.getElementById('host-status-box');
    box.style.background = d.online ? '#f0fdf4' : '#fef2f2';
    box.style.borderLeft = '4px solid ' + (d.online ? '#22c55e' : '#ef4444');

    document.getElementById('host-status-ico').textContent = d.online ? '🟢' : '🔴';
    document.getElementById('host-status-texto').textContent = d.ip + (d.online ? ' — Online' : ' — Offline / sem resposta');
    document.getElementById('host-hostname').textContent = d.hostname || '(sem hostname DNS)';
    document.getElementById('host-latencia').textContent = d.latencia ? d.latencia + ' ms' : '';
    document.getElementById('host-mac').textContent = d.mac || '';

    const portasDiv = document.getElementById('host-portas');
    portasDiv.innerHTML = '';
    const portas = d.portas || {};
    if (Object.keys(portas).length === 0) {
      portasDiv.innerHTML = '<span class="text-muted" style="font-size:12px">Nenhuma porta aberta detectada</span>';
    } else {
      Object.entries(portas).forEach(([porta, nome]) => {
        portasDiv.insertAdjacentHTML('beforeend',
          `<span class="badge bg-light text-dark border" style="font-size:11px;font-family:monospace">
            ${porta} <span style="color:#6b7280">${nome}</span>
           </span>`);
      });
    }

    // SNMP (impressoras)
    const snmpBox = document.getElementById('host-snmp');
    const snmpDados = document.getElementById('host-snmp-dados');
    if (d.snmp) {
      snmpDados.innerHTML = '';
      snmpDados.insertAdjacentHTML('beforeend',
        `<span class="badge bg-light text-dark border" style="font-size:12px">
          <i class="bi bi-file-earmark-text me-1"></i>
          <strong>${d.snmp.paginas.toLocaleString('pt-BR')}</strong> páginas
        </span>`);
      const cores = {preto:'#333', ciano:'#0dcaf0', magenta:'#d63384', amarelo:'#ffc107'};
      Object.entries(d.snmp.toners || {}).forEach(([cor, pct]) => {
        const badge = pct <= 15 ? 'bg-danger text-white' : pct <= 30 ? 'bg-warning text-dark' : 'bg-success text-white';
        snmpDados.insertAdjacentHTML('beforeend',
          `<span class="badge ${badge}" style="font-size:12px">
            Toner ${cor}: ${pct}%
          </span>`);
      });
      snmpBox.style.display = 'block';
    } else {
      snmpBox.style.display = 'none';
    }

    document.getElementById('host-resultado').style.display = 'block';
  })
  .catch(() => {
    document.getElementById('host-loading').style.display = 'none';
    document.getElementById('btn-verificar').disabled = false;
  });
}

// ══════════════════════════════════════════════════════════════
// 5. COLETAR SNMP
// ══════════════════════════════════════════════════════════════
let snmpInterval = null;

document.getElementById('btn-snmp-coletar').addEventListener('click', function() {
  this.disabled = true;
  document.getElementById('snmp-log-box').style.display = 'block';
  document.getElementById('snmp-log').textContent = 'Iniciando coleta SNMP...';
  document.getElementById('snmp-badge').textContent = 'Rodando';
  document.getElementById('snmp-badge').className = 'badge bg-warning text-dark ms-auto';

  fetch('api_ferramentas.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams({action: 'snmp_iniciar', csrf_token: CSRF})
  })
  .then(r => r.json())
  .then(d => {
    if (!d.ok) { alert('Erro: ' + d.erro); this.disabled = false; return; }
    snmpInterval = setInterval(snmpPoll, 2000);
    snmpPoll();
  });
});

function snmpPoll() {
  fetch('api_ferramentas.php?action=snmp_status')
    .then(r => r.json())
    .then(d => {
      if (!d.ok) return;
      const log = document.getElementById('snmp-log');
      if (d.log) { log.textContent = d.log; log.scrollTop = log.scrollHeight; }
      if (!d.rodando) {
        clearInterval(snmpInterval); snmpInterval = null;
        document.getElementById('snmp-badge').textContent = 'Concluído';
        document.getElementById('snmp-badge').className = 'badge bg-success ms-auto';
        document.getElementById('btn-snmp-coletar').disabled = false;
      }
    });
}

// ══════════════════════════════════════════════════════════════
// 3. EXPORTAR INVENTÁRIO
// ══════════════════════════════════════════════════════════════
document.getElementById('btn-exportar').addEventListener('click', function() {
  const setor  = document.getElementById('exp-setor').value;
  const tipo   = document.getElementById('exp-tipo').value;
  const status = document.getElementById('exp-status').value;
  let url = 'api_ferramentas.php?action=exportar_inventario';
  if (setor)  url += '&setor='  + encodeURIComponent(setor);
  if (tipo)   url += '&tipo='   + encodeURIComponent(tipo);
  if (status) url += '&status=' + encodeURIComponent(status);
  window.location.href = url;
});

// ══════════════════════════════════════════════════════════════
// 4. CARGA POR TÉCNICO
// ══════════════════════════════════════════════════════════════
function carregarTecnicos() {
  fetch('api_ferramentas.php?action=chamados_tecnicos')
    .then(r => r.json())
    .then(d => {
      const tb = document.getElementById('tb-tecnicos');
      if (!d.ok || !d.dados.length) {
        tb.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">Nenhum técnico com chamados atribuídos.</td></tr>';
        return;
      }
      tb.innerHTML = d.dados.map(row => {
        const sla = parseInt(row.sla_vencidos) || 0;
        const slaBadge = sla > 0 ? `<span class="badge" style="background:#fef2f2;color:#dc2626;border:1px solid #fca5a5">${sla}</span>` : '<span class="text-muted">—</span>';
        return `<tr>
          <td style="padding-left:1rem;font-weight:600">${row.nome}</td>
          <td class="text-center">
            <span class="badge ${parseInt(row.abertos)>5?'badge-pendente':'badge-andamento'}">${row.abertos}</span>
          </td>
          <td class="text-center">${row.concluidos_mes}</td>
          <td class="text-center">${slaBadge}</td>
        </tr>`;
      }).join('');
    });
}

carregarTecnicos();
document.getElementById('btn-refresh-tecnicos').addEventListener('click', carregarTecnicos);
</script>

<?php layoutFooter(); ?>
