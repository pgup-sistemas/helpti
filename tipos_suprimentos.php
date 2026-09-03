<?php
require 'db.php';
requireGestora();
require 'layout.php';
require_once 'estoque_helpers.php';

$pdo = db();
$u = usuario();

// Processar Inativação/Ativação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle') {
    csrfVerify();
    $id = (int)$_POST['id'];
    $stmt = $pdo->prepare("UPDATE tipos_suprimentos SET ativo = NOT ativo WHERE id = ?");
    $stmt->execute([$id]);
    flash("Status atualizado com sucesso!");
    header("Location: tipos_suprimentos.php");
    exit;
}

// Registrar entrada de estoque (compra/reposição)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'entrada_estoque') {
    csrfVerify();
    $id  = (int)$_POST['id'];
    $qtd = (int)$_POST['quantidade'];
    $motivo = trim($_POST['motivo'] ?? '') ?: 'Reposição de estoque';
    if ($qtd > 0) {
        estoque_movimentar($pdo, $id, 'entrada', $qtd, $motivo, null, $u['id'] ?? null);
        flash("Entrada de {$qtd} unidade(s) registrada com sucesso!");
    } else {
        flash("Informe uma quantidade válida.", "danger");
    }
    header("Location: tipos_suprimentos.php");
    exit;
}

// Filtro e Busca
$busca_texto = trim($_GET['busca'] ?? '');
$where = [];
$params = [];

if ($busca_texto) {
    $where[] = "nome LIKE :b1";
    $params['b1'] = '%' . $busca_texto . '%';
}

$pagina  = max(1, (int)($_GET['pagina'] ?? 1));
$limite  = 20;
$offset  = ($pagina - 1) * $limite;
$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$st_count = $pdo->prepare("SELECT COUNT(*) FROM tipos_suprimentos $where_sql");
$st_count->execute($params);
$total_registros = (int)$st_count->fetchColumn();
$total_paginas   = max(1, ceil($total_registros / $limite));

$sql = "SELECT * FROM tipos_suprimentos $where_sql ORDER BY nome ASC LIMIT $limite OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tipos = $stmt->fetchAll();

layoutHeader('Tipos de Suprimentos', 'tipos_suprimentos');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-tags-fill me-2 text-primary"></i>Gerenciar Tipos de Suprimentos</h1>
  <div class="d-flex gap-2">
    <a href="importar_tipos_suprimentos.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-upload me-1"></i>Importar em Massa</a>
    <a href="novo_tipo_suprimento.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Novo Insumo</a>
  </div>
</div>

<!-- Filtros -->
<div class="card mb-4">
  <div class="card-body py-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-10">
        <label class="form-label fw-semibold mb-1" style="font-size:12px">Buscar Insumo</label>
        <input type="text" name="busca" class="form-control form-control-sm" placeholder="Nome do Insumo..." value="<?= h($busca_texto) ?>">
      </div>
      <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold"><i class="bi bi-search me-1"></i>Filtrar</button>
        <a href="tipos_suprimentos.php" class="btn btn-outline-secondary btn-sm" title="Limpar">✕</a>
      </div>
    </form>
  </div>
</div>

<!-- Tabela -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-ul me-2 text-primary"></i>Lista de Insumos Cadastrados</span>
    <?php if ($busca_texto): ?>
      <span class="badge bg-primary"><?= $total_registros ?> resultado(s)</span>
    <?php else: ?>
      <span class="text-muted" style="font-size:12px"><?= $total_registros ?> registros</span>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 table-sortable">
      <thead>
        <tr>
          <th data-sort>Nome do Insumo</th>
          <th data-sort data-sort-type="number">Estoque</th>
          <th data-sort>Status</th>
          <th>Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tipos as $t):
          $emin = (int)$t['estoque_minimo'];
          $eatl = (int)$t['estoque_atual'];
          $sem_controle = ($emin === 0 && $eatl === 0);
          if ($sem_controle) { $est_cor = 'secondary'; $est_ico = 'dash'; $est_txt = 'Sem controle'; }
          elseif ($eatl === 0) { $est_cor = 'danger'; $est_ico = 'exclamation-triangle-fill'; $est_txt = "0 / mín $emin"; }
          elseif ($eatl <= $emin) { $est_cor = 'warning'; $est_ico = 'exclamation-circle-fill'; $est_txt = "$eatl / mín $emin"; }
          else { $est_cor = 'success'; $est_ico = 'check-circle-fill'; $est_txt = "$eatl / mín $emin"; }
        ?>
          <tr>
            <td>
              <strong><a href="editar_tipo_suprimento.php?id=<?= $t['id'] ?>" class="text-decoration-none text-dark"><?= h($t['nome']) ?></a></strong>
            </td>
            <td data-sort-value="<?= $eatl ?>">
              <span class="badge bg-<?= $est_cor ?>" style="font-size:11px">
                <i class="bi bi-<?= $est_ico ?> me-1"></i><?= $est_txt ?>
              </span>
            </td>
            <td>
              <?php if ($t['ativo']): ?>
                <span class="badge bg-success">Ativo</span>
              <?php else: ?>
                <span class="badge bg-secondary">Inativo</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="d-flex gap-1">
                <button type="button" class="btn btn-outline-success btn-xs" title="Registrar entrada de estoque"
                        data-bs-toggle="modal" data-bs-target="#modalEntrada"
                        data-id="<?= $t['id'] ?>" data-nome="<?= h($t['nome']) ?>">
                  <i class="bi bi-plus-circle"></i>
                </button>
                <a href="editar_tipo_suprimento.php?id=<?= $t['id'] ?>" class="btn btn-outline-primary btn-xs" title="Editar">
                  <i class="bi bi-pencil"></i>
                </a>
                <form method="post" style="display:inline;" onsubmit="return confirm('Tem certeza que deseja alterar o status deste insumo?');">
      <?= csrfField() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= $t['id'] ?>">
                  <?php if ($t['ativo']): ?>
                    <button type="submit" class="btn btn-outline-danger btn-xs" title="Inativar">
                      <i class="bi bi-toggle-off"></i>
                    </button>
                  <?php else: ?>
                    <button type="submit" class="btn btn-outline-success btn-xs" title="Ativar">
                      <i class="bi bi-toggle-on"></i>
                    </button>
                  <?php endif; ?>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tipos): ?>
          <tr>
            <td colspan="3" class="text-center text-muted py-4">Nenhum insumo encontrado.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($total_paginas > 1): ?>
  <div class="card-footer bg-white py-3 border-top">
    <nav>
      <ul class="pagination pagination-sm justify-content-center mb-0">
        <?php
          $queryParams = $_GET;
          $queryParams['pagina'] = max(1, $pagina - 1);
        ?>
        <li class="page-item <?= ($pagina <= 1) ? 'disabled' : '' ?>">
          <a class="page-link" href="?<?= http_build_query($queryParams) ?>">Anterior</a>
        </li>
        <?php
          $inicio = max(1, $pagina - 2);
          $fim    = min($total_paginas, $pagina + 2);
          if ($inicio > 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
          for ($i = $inicio; $i <= $fim; $i++):
            $queryParams['pagina'] = $i;
        ?>
          <li class="page-item <?= ($pagina == $i) ? 'active' : '' ?>">
            <a class="page-link" href="?<?= http_build_query($queryParams) ?>"><?= $i ?></a>
          </li>
        <?php endfor;
          if ($fim < $total_paginas) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
          $queryParams['pagina'] = min($total_paginas, $pagina + 1);
        ?>
        <li class="page-item <?= ($pagina >= $total_paginas) ? 'disabled' : '' ?>">
          <a class="page-link" href="?<?= http_build_query($queryParams) ?>">Próxima</a>
        </li>
      </ul>
    </nav>
    <div class="text-center text-muted mt-2" style="font-size:12px">
      Página <?= $pagina ?> de <?= $total_paginas ?> — <?= $total_registros ?> registro(s)
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Modal: Registrar Entrada de Estoque -->
<div class="modal fade" id="modalEntrada" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="entrada_estoque">
        <input type="hidden" name="id" id="entradaId">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-box-arrow-in-down me-2 text-success"></i>Registrar Entrada de Estoque</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="mb-3">Insumo: <strong id="entradaNome"></strong></p>
          <div class="mb-3">
            <label class="form-label fw-semibold" style="font-size:13px">Quantidade recebida</label>
            <input type="number" name="quantidade" class="form-control" min="1" value="1" required>
          </div>
          <div class="mb-1">
            <label class="form-label fw-semibold" style="font-size:13px">Motivo <span class="text-muted fw-normal">(opcional)</span></label>
            <input type="text" name="motivo" class="form-control" placeholder="Ex: Compra NF 12345, reposição mensal...">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Confirmar Entrada</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('modalEntrada').addEventListener('show.bs.modal', function(ev) {
  const btn = ev.relatedTarget;
  document.getElementById('entradaId').value = btn.dataset.id;
  document.getElementById('entradaNome').textContent = btn.dataset.nome;
});
</script>

<?php layoutFooter(); ?>
