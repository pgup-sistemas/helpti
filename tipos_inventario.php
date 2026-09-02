<?php
require 'db.php';
requireGestora();
require 'layout.php';

$pdo    = db();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

$icones = [
  'bi-laptop','bi-pc-display','bi-display','bi-phone','bi-tablet',
  'bi-printer','bi-diagram-3','bi-wifi','bi-battery-charging','bi-server',
  'bi-telephone','bi-projector','bi-camera-video','bi-keyboard','bi-mouse2',
  'bi-headset','bi-hdd','bi-usb-drive','bi-cpu','bi-motherboard','bi-box',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $nome  = trim($_POST['nome'] ?? '');
    $icone = $_POST['icone'] ?? 'bi-box';
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    if (!$nome) { flash('Nome obrigatório.', 'danger'); }
    elseif ($action === 'criar') {
        try {
            $pdo->prepare("INSERT INTO tipos_inventario (nome, icone, ativo) VALUES (?,?,?)")->execute([$nome,$icone,$ativo]);
            flash("Tipo \"$nome\" criado.");
        } catch (Exception) { flash('Já existe um tipo com esse nome.', 'danger'); }
    } elseif ($action === 'editar' && $id) {
        try {
            $pdo->prepare("UPDATE tipos_inventario SET nome=?, icone=?, ativo=? WHERE id=?")->execute([$nome,$icone,$ativo,$id]);
            flash("Tipo \"$nome\" atualizado.");
        } catch (Exception) { flash('Já existe um tipo com esse nome.', 'danger'); }
    } elseif ($action === 'excluir' && $id) {
        $uso = $pdo->prepare("SELECT COUNT(*) FROM inventario WHERE tipo=(SELECT nome FROM tipos_inventario WHERE id=?)");
        $uso->execute([$id]);
        if ($uso->fetchColumn() > 0) {
            flash('Tipo em uso por equipamentos cadastrados — inative em vez de excluir.', 'danger');
        } else {
            $pdo->prepare("DELETE FROM tipos_inventario WHERE id=?")->execute([$id]);
            flash('Tipo removido.');
        }
    }
    header('Location: tipos_inventario.php'); exit;
}

$editando = null;
if ($action === 'editar' && $id) {
    $st = $pdo->prepare("SELECT * FROM tipos_inventario WHERE id=?");
    $st->execute([$id]);
    $editando = $st->fetch();
}

$tipos = $pdo->query("
    SELECT t.*, COUNT(i.id) AS total
    FROM tipos_inventario t
    LEFT JOIN inventario i ON i.tipo = t.nome
    GROUP BY t.id ORDER BY t.nome
")->fetchAll();

layoutHeader('Tipos de Equipamento', 'tipos_inventario');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-tags-fill me-2 text-primary"></i>Tipos de Equipamento</h1>
</div>

<div class="d-flex flex-column gap-3">
  <div class="card">
    <div class="card-header"><?= $editando ? 'Editar tipo' : 'Novo tipo de equipamento' ?></div>
    <div class="card-body">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="<?= $editando ? 'editar' : 'criar' ?>">
        <?php if ($editando): ?><input type="hidden" name="id" value="<?= $editando['id'] ?>"><?php endif; ?>
        <div class="row g-3">
          <div class="col-sm-5">
            <label class="form-label fw-semibold" style="font-size:13px">Nome do tipo *</label>
            <input type="text" name="nome" class="form-control form-control-sm" required value="<?= h($editando['nome']??'') ?>" placeholder="Ex: Câmera IP, Access Point, Rádio…">
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-semibold" style="font-size:13px">Ícone (Bootstrap Icons)</label>
            <select name="icone" class="form-select form-select-sm" id="selIcone">
              <?php foreach ($icones as $ic): ?>
              <option value="<?= $ic ?>" <?= ($editando['icone']??'bi-box')===$ic?'selected':'' ?>><?= $ic ?></option>
              <?php endforeach; ?>
            </select>
            <div class="mt-1" id="iconPreview">
              <i class="bi <?= h($editando['icone']??'bi-box') ?> text-primary" style="font-size:20px"></i>
            </div>
          </div>
          <div class="col-sm-3 d-flex align-items-center">
            <div class="form-check mt-4">
              <input type="checkbox" name="ativo" class="form-check-input" id="chkAtivo" <?= ($editando['ativo']??1) ? 'checked':'' ?>>
              <label class="form-check-label" for="chkAtivo" style="font-size:13px">Tipo ativo</label>
            </div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary btn-sm"><?= $editando ? 'Salvar' : 'Criar tipo' ?></button>
          <?php if ($editando): ?><a href="tipos_inventario.php" class="btn btn-outline-secondary btn-sm">Cancelar</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span><i class="bi bi-list-ul me-2 text-primary"></i>Tipos cadastrados</span>
      <span class="badge bg-light text-dark border"><?= count($tipos) ?></span>
    </div>
    <div class="card-body p-0">
      <?php foreach ($tipos as $t): ?>
      <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom" style="gap:12px">
        <div style="min-width:0;flex:1;display:flex;align-items:center;gap:12px">
          <i class="bi <?= h($t['icone']) ?> text-primary" style="font-size:20px;flex-shrink:0"></i>
          <div>
            <div class="fw-semibold" style="font-size:14px"><?= h($t['nome']) ?></div>
            <div class="text-muted" style="font-size:12px"><?= $t['total'] ?> equipamento(s) cadastrado(s)</div>
          </div>
        </div>
        <div class="flex-shrink-0">
          <?= $t['ativo'] ? '<span class="badge badge-concluido">Ativo</span>' : '<span class="badge bg-secondary text-white">Inativo</span>' ?>
        </div>
        <div class="d-flex gap-1 flex-shrink-0">
          <a href="?action=editar&id=<?= $t['id'] ?>" class="btn btn-outline-secondary btn-xs"><i class="bi bi-pencil"></i></a>
          <form method="post" onsubmit="return confirm('Excluir o tipo \'<?= addslashes(h($t['nome'])) ?>\'?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="excluir">
            <input type="hidden" name="id" value="<?= $t['id'] ?>">
            <button type="submit" class="btn btn-outline-danger btn-xs" <?= $t['total']>0?'disabled title="Em uso"':'' ?>><i class="bi bi-trash"></i></button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
document.getElementById('selIcone').addEventListener('change', function() {
  document.getElementById('iconPreview').innerHTML =
    '<i class="bi ' + this.value + ' text-primary" style="font-size:20px"></i>';
});
</script>

<?php layoutFooter(); ?>
