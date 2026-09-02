<?php
require 'db.php';
requireLogin();
require 'layout.php';

$pdo = db();

// Carrega todos os eventos para o calendário (JSON inline)
$rows = $pdo->query("
    SELECT m.id, m.tipo, m.status, m.data_manutencao, m.descricao_problema,
           i.nome AS impressora_nome, i.setor,
           u.nome AS tecnico_nome
    FROM manutencoes_impressoras m
    INNER JOIN impressoras i ON i.id = m.impressora_id
    LEFT JOIN  usuarios u    ON u.id = m.tecnico_id
    ORDER BY m.data_manutencao
")->fetchAll();

$eventos = [];
foreach ($rows as $r) {
    // Cor por status
    $cor = match($r['status']) {
        'Concluída'      => '#22c55e',
        'Em Realização'  => '#f59e0b',
        default          => '#3b82f6',   // Pendente
    };
    // Bordas por tipo
    $border = $r['tipo'] === 'Preventiva' ? '#0ea5e9' : '#ef4444';

    $eventos[] = [
        'id'             => $r['id'],
        'title'          => ($r['tipo'] === 'Preventiva' ? '🔧 ' : '🚨 ') . $r['impressora_nome'],
        'start'          => $r['data_manutencao'],
        'backgroundColor'=> $cor,
        'borderColor'    => $border,
        'extendedProps'  => [
            'tipo'       => $r['tipo'],
            'status'     => $r['status'],
            'setor'      => $r['setor'],
            'tecnico'    => $r['tecnico_nome'] ?? '—',
            'descricao'  => mb_strimwidth($r['descricao_problema'] ?? '', 0, 120, '…'),
            'link'       => 'editar_manutencao.php?id=' . $r['id'],
        ],
    ];
}
$eventos_json = json_encode($eventos);

// Stats rápidas
$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(status='Pendente') AS pendente,
        SUM(status='Em Realização') AS em_real,
        SUM(status='Concluída') AS concluida,
        SUM(tipo='Preventiva') AS preventiva,
        SUM(tipo='Corretiva') AS corretiva
    FROM manutencoes_impressoras
")->fetch();

layoutHeader('Agenda de Manutenções', 'manutencoes');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-calendar3 me-2 text-primary"></i>Agenda de Manutenções</h1>
  <div class="d-flex gap-2">
    <a href="manutencoes.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list-ul me-1"></i>Lista</a>
    <a href="nova_manutencao.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Nova</a>
  </div>
</div>

<!-- Stats cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-2">
    <div class="card text-center py-3">
      <div style="font-size:22px;font-weight:700;color:#3b82f6"><?= (int)$stats['total'] ?></div>
      <div style="font-size:11px;color:#64748b">Total</div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="card text-center py-3">
      <div style="font-size:22px;font-weight:700;color:#3b82f6"><?= (int)$stats['pendente'] ?></div>
      <div style="font-size:11px;color:#64748b">Pendentes</div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="card text-center py-3">
      <div style="font-size:22px;font-weight:700;color:#f59e0b"><?= (int)$stats['em_real'] ?></div>
      <div style="font-size:11px;color:#64748b">Em Realização</div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="card text-center py-3">
      <div style="font-size:22px;font-weight:700;color:#22c55e"><?= (int)$stats['concluida'] ?></div>
      <div style="font-size:11px;color:#64748b">Concluídas</div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="card text-center py-3">
      <div style="font-size:22px;font-weight:700;color:#0ea5e9"><?= (int)$stats['preventiva'] ?></div>
      <div style="font-size:11px;color:#64748b">Preventivas</div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="card text-center py-3">
      <div style="font-size:22px;font-weight:700;color:#ef4444"><?= (int)$stats['corretiva'] ?></div>
      <div style="font-size:11px;color:#64748b">Corretivas</div>
    </div>
  </div>
</div>

<!-- Legenda -->
<div class="d-flex flex-wrap gap-3 mb-3 align-items-center" style="font-size:12px">
  <strong style="color:#475569">Legenda:</strong>
  <span><span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:#3b82f6;vertical-align:middle;margin-right:4px"></span>Pendente</span>
  <span><span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:#f59e0b;vertical-align:middle;margin-right:4px"></span>Em Realização</span>
  <span><span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:#22c55e;vertical-align:middle;margin-right:4px"></span>Concluída</span>
  <span style="margin-left:.5rem"><span style="display:inline-block;width:12px;height:12px;border-radius:3px;border:2px solid #0ea5e9;background:transparent;vertical-align:middle;margin-right:4px"></span>Preventiva</span>
  <span><span style="display:inline-block;width:12px;height:12px;border-radius:3px;border:2px solid #ef4444;background:transparent;vertical-align:middle;margin-right:4px"></span>Corretiva</span>
</div>

<!-- Calendário -->
<div class="card">
  <div class="card-body p-3">
    <div id="calendario" style="min-height:580px"></div>
  </div>
</div>

<!-- Modal de detalhe do evento -->
<div class="modal fade" id="modalEvento" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title mb-0" id="mvTitulo"></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="font-size:13px">
        <div class="mb-2"><span class="badge" id="mvTipo"></span> <span class="badge" id="mvStatus"></span></div>
        <div class="mb-1"><i class="bi bi-building me-1 text-muted"></i><span id="mvSetor"></span></div>
        <div class="mb-1"><i class="bi bi-person me-1 text-muted"></i><span id="mvTecnico"></span></div>
        <div class="mt-2 text-muted" style="font-size:12px" id="mvDesc"></div>
      </div>
      <div class="modal-footer py-2">
        <a id="mvLink" href="#" class="btn btn-primary btn-sm"><i class="bi bi-pencil me-1"></i>Editar</a>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<!-- FullCalendar -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.11/locales/pt-br.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const cal = new FullCalendar.Calendar(document.getElementById('calendario'), {
    locale: 'pt-br',
    initialView: 'dayGridMonth',
    headerToolbar: {
      left:   'prev,next today',
      center: 'title',
      right:  'dayGridMonth,timeGridWeek,listMonth'
    },
    buttonText: {
      today:     'Hoje',
      month:     'Mês',
      week:      'Semana',
      list:      'Lista'
    },
    events: <?= $eventos_json ?>,
    eventClick: function(info) {
      const p = info.event.extendedProps;
      document.getElementById('mvTitulo').textContent  = info.event.title;
      document.getElementById('mvTipo').textContent    = p.tipo;
      document.getElementById('mvTipo').className      = 'badge ' + (p.tipo==='Preventiva' ? 'bg-info' : 'bg-danger');
      document.getElementById('mvStatus').textContent  = p.status;
      const sc = {'Pendente':'bg-primary','Em Realização':'bg-warning text-dark','Concluída':'bg-success'};
      document.getElementById('mvStatus').className    = 'badge ' + (sc[p.status] || 'bg-secondary');
      document.getElementById('mvSetor').textContent   = p.setor;
      document.getElementById('mvTecnico').textContent = p.tecnico;
      document.getElementById('mvDesc').textContent    = p.descricao;
      document.getElementById('mvLink').href           = p.link;
      new bootstrap.Modal(document.getElementById('modalEvento')).show();
    },
    eventMouseEnter: function(info) { info.el.style.cursor = 'pointer'; },
    height: 'auto',
    dayMaxEvents: 4,
  });
  cal.render();
});
</script>
<?php layoutFooter(); ?>
