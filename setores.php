<?php
// ============================================================
//  setores.php — CRUD DE SETORES (apenas Admin)
// ============================================================
require 'db.php';
requireAdmin();
require 'layout.php';

$pdo    = db();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$sid    = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

// CREATE / UPDATE / DELETE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $nome  = trim($_POST['nome'] ?? '');
    $ativo = (int)(isset($_POST['ativo']));

    if ($action === 'criar') {
        if (!$nome) {
            flash('Nome do setor é obrigatório.', 'danger');
        } else {
            try {
                $pdo->prepare("INSERT INTO setores (nome, ativo) VALUES (?, ?)")
                    ->execute([$nome, $ativo]);
                flash("Setor '$nome' criado com sucesso.");
            } catch (Exception $e) {
                flash('Setor com este nome já existe.', 'danger');
            }
        }
    } elseif ($action === 'editar' && $sid) {
        if (!$nome) {
            flash('Nome do setor é obrigatório.', 'danger');
        } else {
            try {
                $pdo->prepare("UPDATE setores SET nome = ?, ativo = ? WHERE id = ?")
                    ->execute([$nome, $ativo, $sid]);
                flash("Setor '$nome' atualizado.");
            } catch (Exception $e) {
                flash('Setor com este nome já existe.', 'danger');
            }
        }
    } elseif ($action === 'excluir' && $sid) {
        try {
            $pdo->prepare("DELETE FROM setores WHERE id = ?")->execute([$sid]);
            flash('Setor removido com sucesso.');
        } catch (Exception $e) {
            flash('Não foi possível remover o setor.', 'danger');
        }
    }
    header('Location: setores.php'); exit;
}

// Buscar todos os setores e contar quantos chamados cada um possui
$setores = $pdo->query("SELECT s.*, COUNT(c.id) AS chamados 
    FROM setores s 
    LEFT JOIN chamados c ON c.setor = s.nome 
    GROUP BY s.id 
    ORDER BY s.nome")->fetchAll();

$editando = null;
if ($action === 'editar' && $sid) {
    $st = $pdo->prepare("SELECT * FROM setores WHERE id = ?");
    $st->execute([$sid]);
    $editando = $st->fetch();
}

layoutHeader('Setores', 'setores');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-building-fill me-2 text-primary"></i>Gerenciamento de Setores</h1>
</div>

<div class="d-flex flex-column gap-3">
  <!-- Formulário (Adicionar / Editar) -->
  <div>
    <div class="card">
      <div class="card-header"><?= $editando ? 'Editar Setor' : 'Novo Setor' ?></div>
      <div class="card-body">
        <form method="post">
      <?= csrfField() ?>
          <input type="hidden" name="action" value="<?= $editando ? 'editar' : 'criar' ?>">
          <?php if ($editando): ?><input type="hidden" name="id" value="<?= $editando['id'] ?>"><?php endif; ?>
          
          <div class="mb-3">
            <label class="form-label fw-semibold" style="font-size:13px">Nome do Setor</label>
            <input type="text" name="nome" class="form-control form-control-sm" required placeholder="Ex: 31 - Recepção" value="<?= h($editando['nome'] ?? '') ?>">
          </div>
          
          <div class="mb-3 form-check">
            <input type="checkbox" name="ativo" class="form-check-input" id="chkAtivo" <?= ($editando['ativo'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="chkAtivo" style="font-size:13px">Setor ativo (disponível para chamados)</label>
          </div>
          
          <button type="submit" class="btn btn-primary btn-sm w-100"><?= $editando ? 'Salvar alterações' : 'Criar setor' ?></button>
          <?php if ($editando): ?>
            <a href="setores.php" class="btn btn-outline-secondary btn-sm w-100 mt-2">Cancelar</a>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </div>

  <!-- Lista de Setores em cards -->
  <div>
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul me-2 text-primary"></i>Setores Cadastrados</span>
        <span class="badge bg-light text-dark border"><?= count($setores) ?> setor(es)</span>
      </div>
      <div class="card-body p-0">
        <?php if (!$setores): ?>
          <div class="text-center text-muted py-5">
            <i class="bi bi-building" style="font-size:32px;opacity:.3;display:block;margin-bottom:8px"></i>
            Nenhum setor cadastrado.
          </div>
        <?php endif; ?>
        <?php foreach ($setores as $s): ?>
          <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom" style="gap:12px">
            <!-- Nome e badge de chamados -->
            <div style="min-width:0">
              <div class="fw-semibold text-dark" style="font-size:14px"><?= h($s['nome']) ?></div>
              <div class="text-muted" style="font-size:12px">
                <i class="bi bi-ticket-detailed me-1"></i><?= $s['chamados'] ?> chamado(s) associado(s)
              </div>
            </div>
            <!-- Status -->
            <div class="flex-shrink-0">
              <?= $s['ativo']
                ? '<span class="badge badge-concluido">Ativo</span>'
                : '<span class="badge bg-secondary text-white">Inativo</span>' ?>
            </div>
            <!-- Ações -->
            <div class="d-flex gap-1 flex-shrink-0">
              <a href="?action=editar&id=<?= $s['id'] ?>" class="btn btn-outline-secondary btn-xs">
                <i class="bi bi-pencil me-1"></i>Editar
              </a>
              <form method="post" onsubmit="return confirm('Excluir o setor \'<?= addslashes(h($s['nome'])) ?>\'?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="excluir">
                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                <button type="submit" class="btn btn-outline-danger btn-xs"
                  <?= $s['chamados'] > 0 ? 'disabled title="Setor com chamados não pode ser excluído"' : '' ?>>
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php layoutFooter(); ?>

