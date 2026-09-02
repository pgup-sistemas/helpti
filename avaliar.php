<?php
// Página pública de avaliação — acessada pelo link no e-mail de conclusão
require 'db.php';

$pdo = db();
$id  = (int)($_GET['id'] ?? 0);
$ok  = false;
$erro = '';

// Busca chamado concluído
$chamado = null;
if ($id) {
    $st = $pdo->prepare("SELECT id, numero, setor, solicitante, status FROM chamados WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$id]);
    $chamado = $st->fetch();
}

if (!$chamado || $chamado['status'] !== 'Concluído') {
    $erro = 'Chamado não encontrado ou ainda não concluído.';
}

// Verifica se já foi avaliado
$jaAvaliado = false;
if ($chamado) {
    $jaAvaliado = (bool)$pdo->prepare("SELECT COUNT(*) FROM avaliacoes WHERE chamado_id = ?")
        ->execute([$id]) && $pdo->query("SELECT COUNT(*) FROM avaliacoes WHERE chamado_id = {$id}")->fetchColumn() > 0;
}

// POST — salvar avaliação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $chamado && !$jaAvaliado) {
    $nota       = (int)($_POST['nota'] ?? 0);
    $comentario = trim($_POST['comentario'] ?? '');

    if ($nota < 1 || $nota > 5) {
        $erro = 'Selecione uma nota de 1 a 5.';
    } else {
        $pdo->prepare("INSERT INTO avaliacoes (chamado_id, nota, comentario) VALUES (?, ?, ?)")
            ->execute([$id, $nota, $comentario ?: null]);
        $ok = true;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Avaliação do Atendimento — <?= APP_NOME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { background:#F1FAEE; min-height:100vh; display:flex; align-items:center; justify-content:center; font-family:'Segoe UI',sans-serif; }
.card { max-width:460px; width:100%; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.08); border:none; }
.star-btn { font-size:32px; background:none; border:none; color:#d1d5db; cursor:pointer; padding:0 4px; transition:color .15s; line-height:1; }
.star-btn:hover, .star-btn.ativa { color:#f59e0b; }
.header-bar { background:#1D3557; border-radius:16px 16px 0 0; padding:20px 28px; }
</style>
</head>
<body>
<div class="card">
  <div class="header-bar">
    <div style="color:#fff;font-weight:700;font-size:16px"><?= h(APP_NOME) ?></div>
    <div style="color:#A8DADC;font-size:12px">Avaliação de Atendimento</div>
  </div>
  <div class="card-body p-4">

  <?php if ($ok): ?>
    <div class="text-center py-3">
      <i class="bi bi-patch-check-fill text-success" style="font-size:56px"></i>
      <h4 class="mt-3 mb-1">Obrigado pelo feedback!</h4>
      <p class="text-muted" style="font-size:14px">Sua avaliação foi registrada e nos ajuda a melhorar o atendimento.</p>
    </div>

  <?php elseif ($jaAvaliado): ?>
    <div class="text-center py-3">
      <i class="bi bi-check-circle-fill text-primary" style="font-size:48px"></i>
      <h5 class="mt-3">Chamado já avaliado</h5>
      <p class="text-muted" style="font-size:13px">Este atendimento já recebeu sua avaliação. Obrigado!</p>
    </div>

  <?php elseif ($erro): ?>
    <div class="alert alert-danger" style="font-size:13px"><?= h($erro) ?></div>

  <?php else: ?>
    <h5 class="mb-1">Como foi o atendimento?</h5>
    <p class="text-muted mb-3" style="font-size:13px">
      Chamado <strong><?= h($chamado['numero']) ?></strong> · <?= h($chamado['setor']) ?>
    </p>

    <form method="post">
      <div class="mb-3 text-center">
        <div id="stars" class="d-flex justify-content-center gap-1 mb-1">
          <?php for ($i = 1; $i <= 5; $i++): ?>
          <button type="button" class="star-btn" data-val="<?= $i ?>" onclick="selecionarNota(<?= $i ?>)">★</button>
          <?php endfor; ?>
        </div>
        <input type="hidden" name="nota" id="nota" value="">
        <div id="nota-label" style="font-size:13px;color:#6b7280;min-height:20px"></div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold" style="font-size:13px">Comentário <span class="text-muted fw-normal">(opcional)</span></label>
        <textarea name="comentario" class="form-control" rows="3" style="font-size:13px" placeholder="Descreva sua experiência com o atendimento..."></textarea>
      </div>

      <button type="submit" class="btn btn-primary w-100 fw-semibold">Enviar avaliação</button>
    </form>
  <?php endif; ?>

  </div>
</div>

<script>
const labels = ['','Muito ruim','Ruim','Regular','Bom','Excelente'];
function selecionarNota(val) {
  document.getElementById('nota').value = val;
  document.getElementById('nota-label').textContent = labels[val];
  document.querySelectorAll('.star-btn').forEach((b, i) => {
    b.classList.toggle('ativa', i < val);
  });
}
</script>
</body>
</html>
