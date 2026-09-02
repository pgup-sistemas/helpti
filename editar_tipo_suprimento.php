<?php
require 'db.php';
requireGestora();
require 'layout.php';
require_once 'estoque_helpers.php';

$pdo = db();
$u = usuario();
$erros = [];

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM tipos_suprimentos WHERE id = ?");
$stmt->execute([$id]);
$tipo = $stmt->fetch();

if (!$tipo) {
    flash("Tipo de suprimento não encontrado.", "danger");
    header("Location: tipos_suprimentos.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $nome           = trim($_POST['nome'] ?? '');
    $estoque_minimo = max(0, (int)($_POST['estoque_minimo'] ?? 0));
    $estoque_atual  = max(0, (int)($_POST['estoque_atual']  ?? 0));

    if (!$nome) {
        $erros[] = "O nome do insumo é obrigatório.";
    }

    if (!$erros) {
        $stmt = $pdo->prepare("UPDATE tipos_suprimentos SET nome=?, estoque_minimo=? WHERE id=?");
        $stmt->execute([$nome, $estoque_minimo, $id]);

        // Estoque atual: registra a diferença como movimento de "ajuste" (auditoria),
        // em vez de sobrescrever o valor silenciosamente.
        $delta = $estoque_atual - (int)$tipo['estoque_atual'];
        if ($delta !== 0) {
            estoque_movimentar($pdo, $id, 'ajuste', $delta, 'Ajuste manual via edição de cadastro', null, $u['id'] ?? null);
        }

        flash("Insumo atualizado com sucesso!");
        header("Location: tipos_suprimentos.php");
        exit;
    }
}

layoutHeader('Editar Insumo', 'tipos_suprimentos');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-pencil-square me-2 text-primary"></i>Editar Insumo</h1>
</div>

<div class="card" style="max-width: 600px;">
  <div class="card-header">Dados do Suprimento</div>
  <div class="card-body p-4">
    <?php if ($erros): ?>
      <div class="alert alert-danger py-2 px-3 mb-3" style="font-size:13px">
        <?= implode('<br>', array_map('h', $erros)) ?>
      </div>
    <?php endif; ?>
    
    <form method="post">
      <?= csrfField() ?>
      <div class="mb-3">
        <label class="form-label fw-semibold">Nome / Descrição do Insumo</label>
        <input type="text" name="nome" class="form-control" value="<?= h($_POST['nome'] ?? $tipo['nome']) ?>" required>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Estoque mínimo <span class="text-muted fw-normal">(alerta abaixo deste)</span></label>
          <input type="number" name="estoque_minimo" class="form-control" min="0" value="<?= (int)($_POST['estoque_minimo'] ?? $tipo['estoque_minimo']) ?>">
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Estoque atual</label>
          <input type="number" name="estoque_atual" class="form-control" min="0" value="<?= (int)($_POST['estoque_atual'] ?? $tipo['estoque_atual']) ?>">
        </div>
      </div>

      <div class="mt-4">
        <button type="submit" class="btn btn-primary btn-sm me-2"><i class="bi bi-save me-1"></i>Salvar Alterações</button>
        <a href="tipos_suprimentos.php" class="btn btn-outline-secondary btn-sm">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?php layoutFooter(); ?>
