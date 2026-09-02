<?php
require 'db.php';
requireGestora();
require 'layout.php';

$pdo = db();
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $nome = trim($_POST['nome'] ?? '');
    
    if (!$nome) {
        $erros[] = "O nome do insumo é obrigatório.";
    }
    
    $estoque_minimo = max(0, (int)($_POST['estoque_minimo'] ?? 0));
    $estoque_atual  = max(0, (int)($_POST['estoque_atual']  ?? 0));

    if (!$erros) {
        $stmt = $pdo->prepare("INSERT INTO tipos_suprimentos (nome, estoque_minimo, estoque_atual) VALUES (?,?,?)");
        $stmt->execute([$nome, $estoque_minimo, $estoque_atual]);
        flash("Novo tipo de suprimento cadastrado com sucesso!");
        header("Location: tipos_suprimentos.php");
        exit;
    }
}

layoutHeader('Novo Insumo', 'tipos_suprimentos');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-tags-fill me-2 text-primary"></i>Novo Insumo</h1>
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
        <input type="text" name="nome" class="form-control" placeholder="Ex: Toner Xerox C8030 Black" value="<?= h($_POST['nome'] ?? '') ?>" required>
        <div class="form-text">Este será o nome exibido no menu para o usuário solicitar.</div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Estoque mínimo <span class="text-muted fw-normal">(alerta abaixo deste)</span></label>
          <input type="number" name="estoque_minimo" class="form-control" min="0" value="<?= (int)($_POST['estoque_minimo'] ?? 0) ?>">
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Estoque atual</label>
          <input type="number" name="estoque_atual" class="form-control" min="0" value="<?= (int)($_POST['estoque_atual'] ?? 0) ?>">
        </div>
      </div>

      <div class="mt-4">
        <button type="submit" class="btn btn-primary btn-sm me-2"><i class="bi bi-save me-1"></i>Salvar Cadastro</button>
        <a href="tipos_suprimentos.php" class="btn btn-outline-secondary btn-sm">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?php layoutFooter(); ?>
