<?php
require 'db.php';
requireLogin();
require 'layout.php';

$pdo = db();
$u   = usuario();
$action = $_GET['action'] ?? 'listar';
$id     = (int)($_GET['id'] ?? 0);
$pode_editar = in_array($u['perfil'], ['admin','gestora']);

// ── DELETE ──────────────────────────────────────────────────────
if ($action === 'excluir' && $pode_editar && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $pdo->prepare("DELETE FROM knowledge_base WHERE id = ?")->execute([$id]);
    auditLog('kb_excluido', 'knowledge_base', $id);
    flash('Artigo excluído.');
    header('Location: kb.php'); exit;
}

// ── SAVE (novo / editar) ─────────────────────────────────────────
if (in_array($action, ['novo','editar']) && $pode_editar && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $titulo    = trim($_POST['titulo'] ?? '');
    $conteudo  = trim($_POST['conteudo'] ?? '');
    $publico   = (int)($_POST['publico'] ?? 1);
    $cat_id    = (int)($_POST['categoria_id'] ?? 0) ?: null;

    if ($titulo && $conteudo) {
        if ($action === 'novo') {
            $pdo->prepare("INSERT INTO knowledge_base (titulo, conteudo, categoria_id, publico, autor_id) VALUES (?,?,?,?,?)")
                ->execute([$titulo, $conteudo, $cat_id, $publico, $u['id']]);
            auditLog('kb_criado', 'knowledge_base', (int)$pdo->lastInsertId(), $titulo);
        } else {
            $pdo->prepare("UPDATE knowledge_base SET titulo=?, conteudo=?, categoria_id=?, publico=? WHERE id=?")
                ->execute([$titulo, $conteudo, $cat_id, $publico, $id]);
            auditLog('kb_editado', 'knowledge_base', $id, $titulo);
        }
        flash('Artigo salvo com sucesso.');
        header('Location: kb.php'); exit;
    }
    flash('Preencha título e conteúdo.', 'danger');
}

// ── Registrar visualização ───────────────────────────────────────
if ($action === 'ver' && $id) {
    $pdo->prepare("UPDATE knowledge_base SET visualizacoes = visualizacoes + 1 WHERE id = ?")->execute([$id]);
    $artigo = $pdo->prepare("SELECT kb.*, u.nome AS autor_nome, c.nome AS cat_nome
        FROM knowledge_base kb
        LEFT JOIN usuarios u ON u.id = kb.autor_id
        LEFT JOIN categorias c ON c.id = kb.categoria_id
        WHERE kb.id = ?");
    $artigo->execute([$id]);
    $artigo = $artigo->fetch();
}

// ── LISTAR / BUSCAR ──────────────────────────────────────────────
$busca = trim($_GET['busca'] ?? '');
if ($busca) {
    $st = $pdo->prepare("
        SELECT kb.*, c.nome AS cat_nome,
               MATCH(titulo, conteudo) AGAINST(? IN NATURAL LANGUAGE MODE) AS relevancia
        FROM knowledge_base kb
        LEFT JOIN categorias c ON c.id = kb.categoria_id
        WHERE MATCH(titulo, conteudo) AGAINST(? IN NATURAL LANGUAGE MODE)
        ORDER BY relevancia DESC
        LIMIT 30
    ");
    $st->execute([$busca, $busca]);
    $artigos = $st->fetchAll();
} else {
    $artigos = $pdo->query("
        SELECT kb.*, c.nome AS cat_nome
        FROM knowledge_base kb
        LEFT JOIN categorias c ON c.id = kb.categoria_id
        ORDER BY kb.visualizacoes DESC, kb.criado_em DESC
        LIMIT 50
    ")->fetchAll();
}

$categorias = $pdo->query("SELECT id, nome FROM categorias WHERE ativo=1 ORDER BY nome")->fetchAll();

// ── FORM (novo/editar) ───────────────────────────────────────────
$form_artigo = null;
if (in_array($action, ['novo','editar']) && $pode_editar) {
    if ($action === 'editar' && $id) {
        $st = $pdo->prepare("SELECT * FROM knowledge_base WHERE id = ?");
        $st->execute([$id]);
        $form_artigo = $st->fetch();
    }
}

layoutHeader('Base de Conhecimento', 'kb');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-book-fill me-2 text-primary"></i>Base de Conhecimento</h1>
  <?php if ($pode_editar): ?>
  <a href="kb.php?action=novo" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Novo artigo</a>
  <?php endif; ?>
</div>

<?php if ($flash = getFlash()): ?>
<div class="alert alert-<?= $flash['tipo'] === 'success' ? 'success' : 'danger' ?> alert-dismissible py-2 mb-3" style="font-size:13px">
  <?= h($flash['msg']) ?>
  <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php /* ── VER ARTIGO ── */ if ($action === 'ver' && $artigo): ?>
<?php breadcrumb([['label'=>'Base de Conhecimento','href'=>'kb.php'],['label'=>$artigo['titulo']]]); ?>
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <div>
      <span class="badge bg-light text-dark border me-2" style="font-size:11px"><?= h($artigo['cat_nome'] ?? 'Sem categoria') ?></span>
      <span class="text-muted" style="font-size:12px"><i class="bi bi-eye me-1"></i><?= $artigo['visualizacoes'] ?> visualizações</span>
    </div>
    <?php if ($pode_editar): ?>
    <div class="d-flex gap-2">
      <a href="kb.php?action=editar&id=<?= $artigo['id'] ?>" class="btn btn-outline-secondary btn-xs">Editar</a>
    </div>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <h4 class="mb-1"><?= h($artigo['titulo']) ?></h4>
    <p class="text-muted mb-3" style="font-size:12px">
      Por <?= h($artigo['autor_nome'] ?? 'Sistema') ?> ·
      <?= date('d/m/Y', strtotime($artigo['criado_em'])) ?>
    </p>
    <div style="white-space:pre-wrap;font-size:14px;line-height:1.7"><?= h($artigo['conteudo']) ?></div>
  </div>
</div>

<?php /* ── FORM NOVO/EDITAR ── */ elseif (in_array($action, ['novo','editar']) && $pode_editar): ?>
<div class="card mb-4">
  <div class="card-header"><strong><?= $action === 'novo' ? 'Novo artigo' : 'Editar artigo' ?></strong></div>
  <div class="card-body">
    <form method="post">
      <?= csrfField() ?>
      <div class="mb-3">
        <label class="form-label fw-semibold" style="font-size:13px">Título</label>
        <input type="text" name="titulo" class="form-control" style="font-size:13px"
               value="<?= h($form_artigo['titulo'] ?? '') ?>" required placeholder="Ex: Como configurar VPN no Windows">
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold" style="font-size:13px">Conteúdo</label>
        <textarea name="conteudo" class="form-control" rows="10" style="font-size:13px;font-family:monospace" required
                  placeholder="Descreva o problema, causa e solução passo a passo..."><?= h($form_artigo['conteudo'] ?? '') ?></textarea>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="form-label fw-semibold" style="font-size:13px">Categoria</label>
          <select name="categoria_id" class="form-select" style="font-size:13px">
            <option value="">— Sem categoria —</option>
            <?php foreach ($categorias as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= ($form_artigo['categoria_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
              <?= h($cat['nome']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold" style="font-size:13px">Visibilidade</label>
          <select name="publico" class="form-select" style="font-size:13px">
            <option value="1" <?= ($form_artigo['publico'] ?? 1) == 1 ? 'selected' : '' ?>>Público (portal)</option>
            <option value="0" <?= ($form_artigo['publico'] ?? 1) == 0 ? 'selected' : '' ?>>Interno (só equipe TI)</option>
          </select>
        </div>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Salvar artigo</button>
        <a href="kb.php" class="btn btn-outline-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?php /* ── LISTAGEM ── */ else: ?>

<!-- Busca -->
<form method="get" class="mb-3 d-flex gap-2">
  <input type="text" name="busca" class="form-control" style="font-size:13px;max-width:400px"
         placeholder="Buscar na base de conhecimento..." value="<?= h($busca) ?>">
  <button class="btn btn-outline-primary btn-sm">Buscar</button>
  <?php if ($busca): ?><a href="kb.php" class="btn btn-outline-secondary btn-sm">Limpar</a><?php endif; ?>
</form>

<?php if ($busca && !$artigos): ?>
<div class="alert alert-info" style="font-size:13px">Nenhum artigo encontrado para "<strong><?= h($busca) ?></strong>".</div>
<?php endif; ?>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Título</th>
          <th>Categoria</th>
          <th style="text-align:center">Visualizações</th>
          <th>Atualizado</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($artigos as $art): ?>
        <tr>
          <td>
            <a href="kb.php?action=ver&id=<?= $art['id'] ?>" class="fw-semibold text-decoration-none" style="font-size:13.5px">
              <?= h($art['titulo']) ?>
            </a>
            <?php if (!$art['publico']): ?>
            <span class="badge bg-light text-muted border ms-1" style="font-size:10px">Interno</span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px;color:#6b7280"><?= h($art['cat_nome'] ?? '—') ?></td>
          <td style="text-align:center;font-size:12px"><?= $art['visualizacoes'] ?></td>
          <td style="font-size:12px;white-space:nowrap"><?= date('d/m/Y', strtotime($art['atualizado_em'] ?? $art['criado_em'])) ?></td>
          <td class="d-flex gap-1">
            <a href="kb.php?action=ver&id=<?= $art['id'] ?>" class="btn btn-outline-primary btn-xs">Ver</a>
            <?php if ($pode_editar): ?>
            <a href="kb.php?action=editar&id=<?= $art['id'] ?>" class="btn btn-outline-secondary btn-xs">Editar</a>
            <form method="post" action="kb.php?action=excluir&id=<?= $art['id'] ?>" onsubmit="return confirm('Excluir este artigo?')">
              <?= csrfField() ?>
              <button class="btn btn-outline-danger btn-xs">Excluir</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$artigos): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">Nenhum artigo cadastrado ainda.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php layoutFooter(); ?>
