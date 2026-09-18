<?php
require 'db.php';
requireGestora();
require 'layout.php';

$pdo = db();

$action   = $_POST['action'] ?? $_GET['action'] ?? '';
$id       = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$editando = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();

    if ($action === 'salvar') {
        $nome  = trim($_POST['nome'] ?? '');
        $icone = trim($_POST['icone'] ?? 'bi-tag');
        $ativo = isset($_POST['ativo']) ? 1 : 0;

        if (!$nome) { flash('Informe o nome da categoria.', 'danger'); header('Location: categorias.php'); exit; }

        if ($id) {
            $pdo->prepare("UPDATE categorias SET nome=?, icone=?, ativo=? WHERE id=?")
                ->execute([$nome, $icone, $ativo, $id]);
            flash('Categoria atualizada.');
        } else {
            $pdo->prepare("INSERT INTO categorias (nome, icone, ativo) VALUES (?,?,?)")
                ->execute([$nome, $icone, 1]);
            flash('Categoria criada.');
        }
        header('Location: categorias.php'); exit;
    }

    if ($action === 'excluir' && $id) {
        $em_uso = $pdo->prepare("SELECT COUNT(*) FROM chamados WHERE categoria_id=?");
        $em_uso->execute([$id]);
        if ($em_uso->fetchColumn() > 0) {
            flash('Não é possível excluir: categoria está em uso em chamados.', 'danger');
        } else {
            $pdo->prepare("DELETE FROM categorias WHERE id=?")->execute([$id]);
            flash('Categoria excluída.');
        }
        header('Location: categorias.php'); exit;
    }

    if ($action === 'toggle' && $id) {
        $pdo->prepare("UPDATE categorias SET ativo = NOT ativo WHERE id=?")->execute([$id]);
        header('Location: categorias.php'); exit;
    }
}

if ($action === 'editar' && $id) {
    $editando = $pdo->prepare("SELECT * FROM categorias WHERE id=?");
    $editando->execute([$id]);
    $editando = $editando->fetch();
}

$cats = $pdo->query("
    SELECT c.*, (SELECT COUNT(*) FROM chamados WHERE categoria_id=c.id) AS total_chamados
    FROM categorias c ORDER BY c.nome
")->fetchAll();

// Ícones disponíveis para categoria
$icones = [
    'bi-tag'              => 'Tag (padrão)',
    'bi-laptop'           => 'Laptop',
    'bi-pc-display'       => 'Desktop / PC',
    'bi-printer'          => 'Impressora',
    'bi-wifi'             => 'Rede / Wi-Fi',
    'bi-shield-lock'      => 'Acesso / Segurança',
    'bi-envelope'         => 'E-mail',
    'bi-telephone'        => 'Telefone / VoIP',
    'bi-hdd'              => 'Armazenamento',
    'bi-camera-video'     => 'Câmera / CFTV',
    'bi-projector'        => 'Projetor',
    'bi-tablet'           => 'Tablet',
    'bi-phone'            => 'Celular',
    'bi-wrench-adjustable'=> 'Manutenção',
    'bi-gear'             => 'Software / Sistema',
    'bi-plug'             => 'Elétrica / Energia',
    'bi-router'           => 'Roteador / Switch',
    'bi-question-circle'  => 'Outro',
];

layoutHeader('Categorias de Chamado', 'categorias');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-tags-fill me-2 text-primary"></i>Categorias de Chamado</h1>
</div>

<!-- Formulário -->
<div class="card mb-3">
  <div class="card-header">
    <i class="bi bi-<?= $editando ? 'pencil' : 'plus-circle' ?> me-2 text-primary"></i>
    <?= $editando ? 'Editar categoria' : 'Nova categoria' ?>
  </div>
  <div class="card-body">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="salvar">
      <?php if ($editando): ?><input type="hidden" name="id" value="<?= $editando['id'] ?>"><?php endif; ?>

      <div class="row g-3 align-items-end">
        <div class="col-sm-5">
          <label class="form-label fw-semibold" style="font-size:13px">Nome</label>
          <input type="text" name="nome" class="form-control form-control-sm"
                 value="<?= h($editando['nome'] ?? '') ?>" required placeholder="Ex: Hardware, Software…">
        </div>
        <div class="col-sm-5">
          <label class="form-label fw-semibold" style="font-size:13px">Ícone</label>
          <div class="d-flex gap-2 align-items-center">
            <span id="iconPreview" style="font-size:22px;flex-shrink:0">
              <i class="bi <?= h($editando['icone'] ?? 'bi-tag') ?> text-primary"></i>
            </span>
            <select name="icone" id="selIcone" class="form-select form-select-sm">
              <?php foreach ($icones as $val => $label): ?>
                <option value="<?= $val ?>" <?= ($editando['icone'] ?? 'bi-tag') === $val ? 'selected' : '' ?>>
                  <?= $label ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <?php if ($editando): ?>
        <div class="col-sm-2">
          <div class="form-check mb-1">
            <input type="checkbox" name="ativo" class="form-check-input" id="chkAtivo"
                   <?= $editando['ativo'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="chkAtivo" style="font-size:13px">Ativa</label>
          </div>
        </div>
        <?php endif; ?>
        <div class="col-sm-2 d-flex gap-2">
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-save me-1"></i><?= $editando ? 'Salvar' : 'Criar' ?>
          </button>
          <?php if ($editando): ?>
            <a href="categorias.php" class="btn btn-outline-secondary btn-sm">Cancelar</a>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Lista -->
<div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul me-2 text-primary"></i>Categorias cadastradas</span>
        <span class="badge bg-light text-dark border"><?= count($cats) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if (!$cats): ?>
          <div class="text-center text-muted py-5">
            <i class="bi bi-tags" style="font-size:32px;opacity:.3;display:block;margin-bottom:8px"></i>
            Nenhuma categoria cadastrada.
          </div>
        <?php endif; ?>
        <?php foreach ($cats as $c): ?>
        <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom" style="gap:12px">
          <div style="display:flex;align-items:center;gap:12px;min-width:0;flex:1">
            <i class="bi <?= h($c['icone']) ?> text-primary" style="font-size:20px;flex-shrink:0"></i>
            <div>
              <div class="fw-semibold" style="font-size:14px;color:var(--tx-primary)"><?= h($c['nome']) ?></div>
              <div class="text-muted" style="font-size:12px"><?= (int)$c['total_chamados'] ?> chamado(s)</div>
            </div>
          </div>
          <div class="flex-shrink-0">
            <?= $c['ativo']
              ? '<span class="badge badge-concluido">Ativa</span>'
              : '<span class="badge bg-secondary text-white">Inativa</span>' ?>
          </div>
          <div class="d-flex gap-1 flex-shrink-0">
            <a href="?action=editar&id=<?= $c['id'] ?>" class="btn btn-outline-secondary btn-xs" title="Editar">
              <i class="bi bi-pencil"></i>
            </a>
            <form method="post" style="display:inline">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= $c['id'] ?>">
              <button type="submit" class="btn btn-xs <?= $c['ativo'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                      title="<?= $c['ativo'] ? 'Desativar' : 'Ativar' ?>">
                <i class="bi bi-<?= $c['ativo'] ? 'eye-slash' : 'eye' ?>"></i>
              </button>
            </form>
            <form method="post" onsubmit="return confirm('Excluir a categoria \'<?= addslashes(h($c['nome'])) ?>\'?')" style="display:inline">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="excluir">
              <input type="hidden" name="id" value="<?= $c['id'] ?>">
              <button type="submit" class="btn btn-outline-danger btn-xs"
                      <?= $c['total_chamados'] > 0 ? 'disabled title="Em uso em chamados"' : '' ?>>
                <i class="bi bi-trash"></i>
              </button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
</div>

<script>
document.getElementById('selIcone').addEventListener('change', function () {
  document.getElementById('iconPreview').innerHTML =
    '<i class="bi ' + this.value + ' text-primary" style="font-size:22px"></i>';
});
</script>

<?php layoutFooter(); ?>
