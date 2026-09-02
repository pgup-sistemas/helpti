<?php
require 'db.php';
requireLogin();
require 'layout.php';
require_once 'sync_inventario.php';

$pdo = db();
$u = usuario();
$id = (int)($_GET['id'] ?? 0);

$c = $pdo->prepare("SELECT * FROM chamados WHERE id=?");
$c->execute([$id]);
$chamado = $c->fetch();

if (!$chamado) {
    flash('Chamado não encontrado.', 'danger');
    header('Location: chamados.php');
    exit;
}

$tecnicos = $pdo->query("SELECT id,nome FROM usuarios WHERE ativo=1 AND perfil IN ('tecnico','admin') ORDER BY nome")->fetchAll();
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $descricao = trim($_POST['descricao'] ?? '');
    $setor = trim($_POST['setor'] ?? '');
    $solicitante = trim($_POST['solicitante'] ?? '');
    $criado_em = trim($_POST['criado_em'] ?? '');
    $responsavel_id = $_POST['responsavel_id'] ?: null;
    $nivel = $_POST['nivel'] ?? 'A Definir';
    $status = $_POST['status'] ?? 'Aberto';

    if (!$descricao) $erros[] = "A descrição é obrigatória.";
    if (!$setor) $erros[] = "O setor é obrigatório.";
    if (!$solicitante) $erros[] = "O solicitante é obrigatório.";
    if (!$criado_em) $erros[] = "A data de criação é obrigatória.";

    if (!$erros) {
        // Atualiza chamado
        $pdo->prepare("UPDATE chamados SET descricao=?, setor=?, solicitante=?, criado_em=?, responsavel_id=?, nivel=?, status=? WHERE id=?")
            ->execute([$descricao, $setor, $solicitante, $criado_em, $responsavel_id, $nivel, $status, $id]);

        // Registra histórico
        $pdo->prepare("INSERT INTO historico (chamado_id, usuario_id, acao) VALUES (?, ?, ?)")
            ->execute([$id, $u['id'], "Dados do chamado editados de forma abrangente pelo painel de edição."]);

        // Sincroniza status do equipamento vinculado
        sync_inventario_status_chamado($id, $status);

        flash('Chamado editado com sucesso.');
        header('Location: chamados.php');
        exit;
    }
}

layoutHeader('Editar Chamado ' . $chamado['numero'], 'chamados');
?>

<?php breadcrumb([['label'=>'Chamados','href'=>'chamados.php'],['label'=>'Editar Chamado '.$chamado['numero']]]); ?>
<div class="page-header">
  <h1 class="page-title"><i class="bi bi-pencil-square me-2 text-primary"></i>Editar Chamado <code style="font-size:16px"><?= h($chamado['numero']) ?></code></h1>
</div>

<div class="card" style="max-width: 700px;">
  <div class="card-body">
    <?php if ($erros): ?>
      <div class="alert alert-danger py-2 px-3 mb-3" style="font-size:13px">
        <?= implode('<br>', array_map('h', $erros)) ?>
      </div>
    <?php endif; ?>
    
    <form method="post">
      <?= csrfField() ?>
      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="form-label fw-semibold" style="font-size:13px">Solicitante</label>
          <input type="text" name="solicitante" class="form-control" value="<?= h($_POST['solicitante'] ?? $chamado['solicitante']) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold" style="font-size:13px">Setor</label>
          <select name="setor" class="form-select" required>
            <option value="">— Selecione —</option>
            <?php foreach ($SETORES as $s): ?>
              <option value="<?= h($s) ?>" <?= ($_POST['setor'] ?? $chamado['setor']) === $s ? 'selected' : '' ?>><?= h($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      
      <div class="row g-3 mb-3">
        <div class="col-md-4">
          <label class="form-label fw-semibold" style="font-size:13px">Responsável</label>
          <select name="responsavel_id" class="form-select">
            <option value="">Sem atribuição</option>
            <?php $respAtual = $_POST['responsavel_id'] ?? $chamado['responsavel_id']; ?>
            <?php foreach($tecnicos as $t): ?>
              <option value="<?= $t['id'] ?>" <?= $respAtual == $t['id'] ? 'selected' : '' ?>><?= h($t['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold" style="font-size:13px">Nível</label>
          <select name="nivel" class="form-select">
            <?php $nivelAtual = $_POST['nivel'] ?? $chamado['nivel']; ?>
            <?php foreach(['A Definir','Baixa Complexidade','Média Complexidade','Alta Complexidade'] as $n): ?>
              <option <?= $nivelAtual === $n ? 'selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold" style="font-size:13px">Status</label>
          <select name="status" class="form-select">
            <?php $statusAtual = $_POST['status'] ?? $chamado['status']; ?>
            <?php foreach(['Aberto','Em Andamento','Pendente','Concluído'] as $s): ?>
              <option <?= $statusAtual === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      
      <div class="mb-3">
        <label class="form-label fw-semibold" style="font-size:13px">Data de Abertura</label>
        <input type="datetime-local" name="criado_em" class="form-control" style="max-width: 250px;" value="<?= date('Y-m-d\TH:i', strtotime($_POST['criado_em'] ?? $chamado['criado_em'])) ?>" required>
      </div>

      <div class="mb-4">
        <label class="form-label fw-semibold" style="font-size:13px">Descrição</label>
        <textarea name="descricao" class="form-control" rows="5" required><?= h($_POST['descricao'] ?? $chamado['descricao']) ?></textarea>
      </div>
      
      <button type="submit" class="btn btn-primary"><i class="bi bi-save me-2"></i>Salvar Alterações</button>
      <a href="chamados.php" class="btn btn-light ms-2">Cancelar</a>
    </form>
  </div>
</div>

<?php layoutFooter(); ?>
