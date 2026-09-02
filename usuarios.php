<?php
require 'db.php';
requireAdmin();
require 'layout.php';

$pdo    = db();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$uid    = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

// CREATE / UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $nome   = trim($_POST['nome'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $perfil = $_POST['perfil'] ?? 'tecnico';
    $ativo  = (int)(isset($_POST['ativo']));
    $senha  = trim($_POST['senha'] ?? '');

    if ($action === 'criar') {
        if (!$senha) { flash('Senha obrigatória ao criar.','danger'); }
        else {
            try {
                $pdo->prepare("INSERT INTO usuarios (nome,email,senha,perfil,ativo) VALUES (?,?,?,?,?)")
                    ->execute([$nome,$email,password_hash($senha,PASSWORD_DEFAULT),$perfil,$ativo]);
                flash("Usuário $nome criado.");
            } catch (Exception $e) { flash('E-mail já cadastrado.','danger'); }
        }
    } elseif ($action === 'editar' && $uid) {
        if ($senha) {
            $pdo->prepare("UPDATE usuarios SET nome=?,email=?,senha=?,perfil=?,ativo=? WHERE id=?")
                ->execute([$nome,$email,password_hash($senha,PASSWORD_DEFAULT),$perfil,$ativo,$uid]);
        } else {
            $pdo->prepare("UPDATE usuarios SET nome=?,email=?,perfil=?,ativo=? WHERE id=?")
                ->execute([$nome,$email,$perfil,$ativo,$uid]);
        }
        flash("Usuário $nome atualizado.");
    } elseif ($action === 'excluir' && $uid) {
        $pdo->prepare("DELETE FROM usuarios WHERE id=?")->execute([$uid]);
        flash('Usuário removido.');
    }
    header('Location: usuarios.php'); exit;
}

$usuarios = $pdo->query("SELECT u.*, COUNT(c.id) AS chamados
    FROM usuarios u LEFT JOIN chamados c ON c.responsavel_id=u.id
    GROUP BY u.id ORDER BY u.nome")->fetchAll();

$editando = null;
if ($action === 'editar' && $uid) {
    $st = $pdo->prepare("SELECT * FROM usuarios WHERE id=?");
    $st->execute([$uid]);
    $editando = $st->fetch();
}

layoutHeader('Usuários', 'usuarios');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-people-fill me-2 text-primary"></i>Técnicos & Usuários</h1>
</div>

<div class="d-flex flex-column gap-3">
  <!-- Formulário -->
  <div>
    <div class="card">
      <div class="card-header"><?= $editando ? 'Editar usuário' : 'Novo usuário' ?></div>
      <div class="card-body">
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="<?= $editando ? 'editar' : 'criar' ?>">
          <?php if ($editando): ?><input type="hidden" name="id" value="<?= $editando['id'] ?>"><?php endif; ?>
          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label fw-semibold" style="font-size:13px">Nome</label>
              <input type="text" name="nome" class="form-control form-control-sm" required value="<?= h($editando['nome']??'') ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold" style="font-size:13px">E-mail</label>
              <input type="email" name="email" class="form-control form-control-sm" required value="<?= h($editando['email']??'') ?>">
            </div>
            <div class="col-sm-4">
              <label class="form-label fw-semibold" style="font-size:13px">Senha <?= $editando ? '<span class="text-muted fw-normal">(em branco = não altera)</span>' : '' ?></label>
              <input type="password" name="senha" class="form-control form-control-sm" <?= $editando ? '' : 'required' ?>>
            </div>
            <div class="col-sm-4">
              <label class="form-label fw-semibold" style="font-size:13px">Perfil</label>
              <select name="perfil" class="form-select form-select-sm">
                <option value="tecnico" <?= (($editando['perfil']??'')==='tecnico') ? 'selected' : '' ?>>Técnico</option>
                <option value="gestora" <?= (($editando['perfil']??'')==='gestora') ? 'selected' : '' ?>>Gestora</option>
                <option value="admin"   <?= (($editando['perfil']??'')==='admin')   ? 'selected' : '' ?>>Admin</option>
              </select>
            </div>
            <div class="col-sm-4 d-flex align-items-end">
              <div class="form-check mb-2">
                <input type="checkbox" name="ativo" class="form-check-input" id="chkAtivo" <?= ($editando['ativo'] ?? 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="chkAtivo" style="font-size:13px">Usuário ativo</label>
              </div>
            </div>
          </div>
          <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm"><?= $editando ? 'Salvar alterações' : 'Criar usuário' ?></button>
            <?php if ($editando): ?>
            <a href="usuarios.php" class="btn btn-outline-secondary btn-sm">Cancelar</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Lista -->
  <div>
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people me-2 text-primary"></i>Usuários cadastrados</span>
        <span class="badge bg-light text-dark border"><?= count($usuarios) ?> usuário(s)</span>
      </div>
      <div class="card-body p-0">
        <?php if (!$usuarios): ?>
          <div class="text-center text-muted py-5">
            <i class="bi bi-people" style="font-size:32px;opacity:.3;display:block;margin-bottom:8px"></i>
            Nenhum usuário cadastrado.
          </div>
        <?php endif; ?>
        <?php
          $me = usuario();
          foreach ($usuarios as $u):
            $perfilLabel = ['admin'=>'Admin','gestora'=>'Gestora','tecnico'=>'Técnico'][$u['perfil']] ?? $u['perfil'];
            $perfilClass  = $u['perfil']==='admin' ? 'bg-dark text-white' : ($u['perfil']==='gestora' ? 'badge-andamento' : 'badge-aberto');
        ?>
          <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom" style="gap:12px">
            <!-- Info principal -->
            <div style="min-width:0">
              <div class="fw-semibold text-dark" style="font-size:14px">
                <?= h($u['nome']) ?>
                <?php if ($u['id'] == $me['id']): ?>
                  <span class="badge bg-light text-muted border ms-1" style="font-size:10px">você</span>
                <?php endif; ?>
              </div>
              <div class="text-muted" style="font-size:12px">
                <i class="bi bi-envelope me-1"></i><?= h($u['email']) ?>
                <span class="ms-2"><i class="bi bi-ticket-detailed me-1"></i><?= $u['chamados'] ?> chamado(s)</span>
              </div>
            </div>
            <!-- Badges -->
            <div class="d-flex gap-1 flex-shrink-0">
              <span class="badge <?= $perfilClass ?>"><?= $perfilLabel ?></span>
              <?= $u['ativo']
                ? '<span class="badge badge-concluido">Ativo</span>'
                : '<span class="badge bg-secondary text-white">Inativo</span>' ?>
            </div>
            <!-- Ações -->
            <div class="d-flex gap-1 flex-shrink-0">
              <a href="?action=editar&id=<?= $u['id'] ?>" class="btn btn-outline-secondary btn-xs">
                <i class="bi bi-pencil me-1"></i>Editar
              </a>
              <?php if ($u['id'] != $me['id']): ?>
              <form method="post" onsubmit="return confirm('Excluir o usuário \'<?= addslashes(h($u['nome'])) ?>\'?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="excluir">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn btn-outline-danger btn-xs"><i class="bi bi-trash"></i></button>
              </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php layoutFooter(); ?>
