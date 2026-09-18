<?php
require 'db.php';
requireLogin();
require 'layout.php';

$pdo = db();
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $nome          = trim($_POST['nome'] ?? '');
    $marca_modelo  = trim($_POST['marca_modelo'] ?? '');
    $numero_serie  = trim($_POST['numero_serie'] ?? '');
    $ip            = trim($_POST['ip'] ?? '');
    $setor         = trim($_POST['setor'] ?? '');
    $modelo_toner  = trim($_POST['modelo_toner'] ?? '');
    $status        = $_POST['status'] ?? 'Ativa';

    if (!$nome)         $erros[] = 'Informe um nome de identificação para a impressora.';
    if (!$marca_modelo) $erros[] = 'Informe a marca / modelo do equipamento.';
    if (!$setor)        $erros[] = 'Selecione o setor de instalação.';

    // Opcional: Validar formato do IP se preenchido
    if ($ip && !filter_var($ip, FILTER_VALIDATE_IP)) {
        $erros[] = 'O endereço IP informado é inválido.';
    }

    if (!$erros) {
        $stmt = $pdo->prepare("
            INSERT INTO impressoras (nome, marca_modelo, numero_serie, ip, setor, modelo_toner, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$nome, $marca_modelo, $numero_serie ?: null, $ip ?: null, $setor, $modelo_toner ?: null, $status]);

        $iid = $pdo->lastInsertId();
        flash("Impressora '$nome' cadastrada com sucesso!");
        header("Location: impressora.php?id=$iid");
        exit;
    }
}

layoutHeader('Cadastrar Impressora', 'impressoras');
?>

<?php breadcrumb([['label'=>'Impressoras','href'=>'impressoras.php'],['label'=>'Cadastrar Nova']]); ?>
<div class="page-header">
  <h1 class="page-title"><i class="bi bi-plus-circle-fill me-2 text-primary"></i>Cadastrar Nova Impressora</h1>
</div>

<div class="card">
  <div class="card-header">Ficha de Cadastro de Equipamento</div>
  <div class="card-body">
    <?php if ($erros): ?>
      <div class="alert alert-danger py-2" style="font-size:13px">
        <?= implode('<br>', array_map('h', $erros)) ?>
      </div>
    <?php endif; ?>
    
    <form method="post" novalidate>
      <?= csrfField() ?>
      <div class="row g-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold" style="font-size:13px">Nome / Identificação</label>
          <input type="text" name="nome" class="form-control form-control-sm" placeholder="Ex: Impressora HP - Recepção SUS" value="<?= h($_POST['nome'] ?? '') ?>" required>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" style="font-size:13px">Setor de Instalação</label>
          <select name="setor" class="form-select form-select-sm" required>
            <option value="">— Selecione —</option>
            <?php foreach ($SETORES as $s): ?>
              <option value="<?= h($s) ?>" <?= (($_POST['setor'] ?? '') === $s) ? 'selected' : '' ?>><?= h($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" style="font-size:13px">Marca / Modelo</label>
          <input type="text" name="marca_modelo" class="form-control form-control-sm" placeholder="Ex: HP LaserJet M402dn" value="<?= h($_POST['marca_modelo'] ?? '') ?>" required>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" style="font-size:13px">Modelo de Toner Principal</label>
          <input type="text" name="modelo_toner" class="form-control form-control-sm" placeholder="Ex: CF226A (26A)" value="<?= h($_POST['modelo_toner'] ?? '') ?>">
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" style="font-size:13px">Número de Série</label>
          <input type="text" name="numero_serie" class="form-control form-control-sm" placeholder="Ex: PHB3K91283" value="<?= h($_POST['numero_serie'] ?? '') ?>">
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" style="font-size:13px">Endereço IP (opcional)</label>
          <input type="text" name="ip" class="form-control form-control-sm" placeholder="Ex: 192.168.1.55" value="<?= h($_POST['ip'] ?? '') ?>">
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" style="font-size:13px">Status Inicial</label>
          <select name="status" class="form-select form-select-sm">
            <?php foreach (['Ativa', 'Em Manutenção', 'Inativa'] as $st_opt): ?>
              <option value="<?= $st_opt ?>" <?= (($_POST['status'] ?? '') === $st_opt) ? 'selected' : '' ?>><?= $st_opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      
      <div class="mt-4">
        <button type="submit" class="btn btn-primary btn-sm me-2"><i class="bi bi-save me-1"></i>Salvar Impressora</button>
        <a href="impressoras.php" class="btn btn-outline-secondary btn-sm">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?php layoutFooter(); ?>
