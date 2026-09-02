<?php
require 'db.php';
requireLogin();
require 'layout.php';

$pdo = db();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    if ($action === 'devolver' && $id) {
        $pdo->prepare("UPDATE termos_uso SET status='Devolvido', data_devolucao=CURDATE(), condicao_devolucao=? WHERE id=?")
            ->execute([trim($_POST['condicao_devolucao'] ?? ''), $id]);
        flash('Equipamento registrado como devolvido.');
    } elseif ($action === 'excluir' && $id) {
        $pdo->prepare("DELETE FROM termos_uso WHERE id=?")->execute([$id]);
        flash('Termo removido.');
    }
    header('Location: termos.php'); exit;
}

$f_status = $_GET['status'] ?? 'Ativo';

$termos = $pdo->prepare("
    SELECT t.*, i.tipo, i.marca, i.modelo, i.numero_serie, i.patrimonio
    FROM termos_uso t
    JOIN inventario i ON i.id = t.inventario_id
    " . ($f_status ? "WHERE t.status=?" : "") . "
    ORDER BY t.criado_em DESC
");
$termos->execute($f_status ? [$f_status] : []);
$lista = $termos->fetchAll();

$stats = $pdo->query("SELECT status, COUNT(*) n FROM termos_uso GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$stats_venc = $pdo->query("
    SELECT
      SUM(data_prevista_devolucao < CURDATE()) AS vencidos,
      SUM(data_prevista_devolucao BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AS vencendo
    FROM termos_uso WHERE status='Ativo' AND data_prevista_devolucao IS NOT NULL
")->fetch();

layoutHeader('Termos de Uso', 'termos');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-file-earmark-person-fill me-2 text-primary"></i>Termos de Guarda & Uso</h1>
  <div class="d-flex gap-2">
    <a href="novo_termo.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Novo termo</a>
    <?php if (($u['perfil'] ?? '') !== 'tecnico'): ?>
    <a href="config_termo.php" class="btn btn-outline-secondary btn-sm" title="Editar modelo do termo"><i class="bi bi-gear me-1"></i>Modelo do Termo</a>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-4">
    <div class="stat-card"><div class="d-flex justify-content-between align-items-start">
      <div><div class="stat-num" style="color:var(--brand-dark)"><?= (int)($stats['Ativo']??0) ?></div><div class="stat-label">Ativos (sob guarda)</div></div>
      <i class="bi bi-person-check-fill" style="font-size:22px;color:var(--brand-dark);opacity:.5"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-4">
    <div class="stat-card"><div class="d-flex justify-content-between align-items-start">
      <div><div class="stat-num" style="color:#22c55e"><?= (int)($stats['Devolvido']??0) ?></div><div class="stat-label">Devolvidos</div></div>
      <i class="bi bi-arrow-return-left" style="font-size:22px;color:#22c55e;opacity:.35"></i>
    </div></div>
  </div>
  <?php if ((int)($stats_venc['vencidos']??0) > 0): ?>
  <div class="col-6 col-md-4">
    <div class="stat-card" style="border-left:3px solid #E63946"><div class="d-flex justify-content-between align-items-start">
      <div><div class="stat-num" style="color:#E63946"><?= (int)$stats_venc['vencidos'] ?></div><div class="stat-label">Empréstimos vencidos</div></div>
      <i class="bi bi-exclamation-triangle-fill" style="font-size:22px;color:#E63946;opacity:.4"></i>
    </div></div>
  </div>
  <?php endif; ?>
  <?php if ((int)($stats_venc['vencendo']??0) > 0): ?>
  <div class="col-6 col-md-4">
    <div class="stat-card" style="border-left:3px solid #f59e0b"><div class="d-flex justify-content-between align-items-start">
      <div><div class="stat-num" style="color:#f59e0b"><?= (int)$stats_venc['vencendo'] ?></div><div class="stat-label">Vencem em 7 dias</div></div>
      <i class="bi bi-clock-history" style="font-size:22px;color:#f59e0b;opacity:.4"></i>
    </div></div>
  </div>
  <?php endif; ?>
</div>

<!-- Filtro -->
<div class="card card-body py-2 mb-3">
  <div class="d-flex gap-2 align-items-center">
    <a href="?status=Ativo"   class="btn btn-sm <?= $f_status==='Ativo'   ?'btn-primary':'btn-outline-secondary' ?>">Ativos</a>
    <a href="?status=Devolvido" class="btn btn-sm <?= $f_status==='Devolvido' ?'btn-primary':'btn-outline-secondary' ?>">Devolvidos</a>
    <a href="?"               class="btn btn-sm <?= !$f_status            ?'btn-primary':'btn-outline-secondary' ?>">Todos</a>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-ul me-2 text-primary"></i>Termos emitidos</span>
    <span class="badge bg-light text-dark border"><?= count($lista) ?></span>
  </div>
  <div class="card-body p-0">
    <?php if (!$lista): ?>
      <div class="text-center text-muted py-5"><i class="bi bi-file-earmark" style="font-size:32px;opacity:.3;display:block;margin-bottom:8px"></i>Nenhum termo encontrado.</div>
    <?php endif; ?>
    <?php foreach ($lista as $t): ?>
    <div class="d-flex align-items-start justify-content-between px-4 py-3 border-bottom" style="gap:12px">
      <div style="min-width:0;flex:1">
        <div class="fw-semibold" style="font-size:14px;color:var(--tx-primary)">
          <span class="badge me-1" style="font-size:11px;background:var(--bg-surface-alt);color:var(--tx-secondary);border:1px solid var(--border)"><?= h($t['tipo']) ?></span>
          <?= h($t['marca'].' '.$t['modelo']) ?>
          <?php if ($t['patrimonio']): ?><span class="text-muted" style="font-size:12px"> · Pat. <?= h($t['patrimonio']) ?></span><?php endif; ?>
        </div>
        <div class="text-muted" style="font-size:12px;margin-top:2px">
          <i class="bi bi-person me-1"></i><?= h($t['responsavel_nome']) ?>
          <?php if ($t['responsavel_matricula']): ?> · Matrícula: <?= h($t['responsavel_matricula']) ?><?php endif; ?>
          <?php if ($t['setor']): ?> · <i class="bi bi-building me-1"></i><?= h($t['setor']) ?><?php endif; ?>
        </div>
        <div style="font-size:12px;margin-top:2px;color:var(--tx-muted)">
          <i class="bi bi-calendar-check me-1"></i>Entregue em <?= date('d/m/Y', strtotime($t['data_entrega'])) ?>
          <?php if ($t['data_prevista_devolucao'] && $t['status']==='Ativo'):
            $hoje   = new DateTime();
            $prev   = new DateTime($t['data_prevista_devolucao']);
            $diff   = (int)$hoje->diff($prev)->format('%r%a');
            if ($diff < 0):?>
              · <span class="badge" class="badge" style="background:var(--venc-danger-bg,#fee2e2);color:var(--venc-danger-tx,#991b1b);font-size:11px"><i class="bi bi-exclamation-triangle-fill me-1"></i>Vencido há <?= abs($diff) ?> dia(s)</span>
            <?php elseif ($diff <= 7): ?>
              · <span class="badge" class="badge" style="background:var(--venc-warn-bg,#fef3c7);color:var(--venc-warn-tx,#92400e);font-size:11px"><i class="bi bi-clock-history me-1"></i>Vence em <?= $diff ?> dia(s) (<?= $prev->format('d/m/Y') ?>)</span>
            <?php else: ?>
              · <i class="bi bi-calendar-event me-1"></i>Devolução prevista: <?= $prev->format('d/m/Y') ?>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($t['data_devolucao']): ?>
            · <i class="bi bi-arrow-return-left me-1"></i>Devolvido em <?= date('d/m/Y', strtotime($t['data_devolucao'])) ?>
          <?php endif; ?>
        </div>
        <?php if ($t['condicao_entrega']): ?>
        <div style="font-size:12px;color:var(--tx-muted);margin-top:2px"><i class="bi bi-info-circle me-1"></i><?= h($t['condicao_entrega']) ?></div>
        <?php endif; ?>
      </div>
      <div class="flex-shrink-0">
        <?= $t['status']==='Ativo'
          ? '<span class="badge badge-concluido">Ativo</span>'
          : '<span class="badge bg-secondary text-white">Devolvido</span>' ?>
      </div>
      <div class="d-flex gap-1 flex-shrink-0 flex-column flex-sm-row">
        <a href="imprimir_termo.php?id=<?= $t['id'] ?>" target="_blank" class="btn btn-outline-primary btn-xs" title="Imprimir"><i class="bi bi-printer me-1"></i>Imprimir</a>
        <?php if ($t['status']==='Ativo'): ?>
        <button type="button" class="btn btn-outline-warning btn-xs" onclick="devolverTermo(<?= $t['id'] ?>)" title="Registrar devolução">
          <i class="bi bi-arrow-return-left me-1"></i>Devolver
        </button>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('Excluir este termo?')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="excluir">
          <input type="hidden" name="id" value="<?= $t['id'] ?>">
          <button type="submit" class="btn btn-outline-danger btn-xs"><i class="bi bi-trash"></i></button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Modal devolução -->
<div class="modal fade" id="modalDevolucao" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="devolver">
      <input type="hidden" name="id" id="devolver_id">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" style="font-size:15px">Registrar Devolução</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <label class="form-label fw-semibold" style="font-size:13px">Condição na devolução</label>
          <textarea name="condicao_devolucao" class="form-control form-control-sm" rows="3" placeholder="Descreva o estado do equipamento…"></textarea>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary btn-sm">Confirmar</button></div>
      </div>
    </form>
  </div>
</div>
<script>
function devolverTermo(id) {
  document.getElementById('devolver_id').value = id;
  new bootstrap.Modal(document.getElementById('modalDevolucao')).show();
}
</script>

<?php layoutFooter(); ?>
