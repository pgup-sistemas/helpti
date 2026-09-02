<?php
/**
 * HelpTI — Pedido de Suprimento (modo admin)
 * Versão dedicada para uso dentro do painel: pré-carrega a impressora,
 * salva o pedido vinculado a ela e mantém o usuário no modo admin
 * (sem cair no portal público do colaborador).
 */
require 'db.php';
requireLogin();
require 'layout.php';

$pdo = db();
$u   = usuario();
$erros = [];

// Pre-seleção da impressora via GET
$impressora_get_id = (int)($_GET['impressora_id'] ?? 0);

$impressoras = $pdo->query("SELECT id, nome, setor FROM impressoras ORDER BY nome ASC")->fetchAll();
$tipos_suprimentos = $pdo->query("SELECT id, nome FROM tipos_suprimentos WHERE ativo=1 ORDER BY nome")->fetchAll();

$tipos_ids_post   = $_POST['tipo_suprimento_id'] ?? [''];
$quantidades_post = $_POST['quantidade'] ?? [1];
$descricoes_post  = $_POST['descricao_livre'] ?? [''];

// Setor pré-selecionado com base na impressora escolhida (GET ou POST)
$impressora_sel_id = (int)($_POST['impressora_id'] ?? $impressora_get_id);
$pre_setor = '';
if ($impressora_sel_id) {
    $chk = $pdo->prepare("SELECT setor FROM impressoras WHERE id = ?");
    $chk->execute([$impressora_sel_id]);
    $pre_setor = $chk->fetchColumn() ?: '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $impressora_id = $_POST['impressora_id'] ? (int)$_POST['impressora_id'] : null;
    $setor_sup     = trim($_POST['setor'] ?? '');
    $observacoes   = trim($_POST['observacoes'] ?? '');

    if (!$setor_sup) $erros[] = 'Selecione o setor.';
    if (empty($tipos_ids_post) || (count($tipos_ids_post) === 1 && empty($tipos_ids_post[0])))
        $erros[] = 'Adicione pelo menos um insumo.';

    $itens_validos = [];
    foreach ($tipos_ids_post as $idx => $tipo_id) {
        $qtd  = (int)($quantidades_post[$idx] ?? 1);
        $desc = trim($descricoes_post[$idx] ?? '');
        if ($qtd < 1)                     $erros[] = "Quantidade do item " . ($idx + 1) . " inválida.";
        if ($tipo_id === 'outro' && !$desc) $erros[] = "Descreva o insumo (Outros) no item " . ($idx + 1) . ".";
        if (empty($tipo_id))               $erros[] = "Selecione o insumo no item " . ($idx + 1) . ".";
        $itens_validos[] = [
            'tipo_id'    => ($tipo_id === 'outro' ? null : (int)$tipo_id),
            'descricao'  => ($tipo_id === 'outro' ? $desc : null),
            'quantidade' => $qtd,
        ];
    }

    if (!$erros) {
        try {
            $pdo->beginTransaction();
            $pdo->exec("UPDATE sequences SET value = LAST_INSERT_ID(value + 1) WHERE name = 'suprimentos'");
            $seq_sup    = (int)$pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();
            $numero_sup = 'SUP-' . date('Y') . '-' . str_pad($seq_sup, 5, '0', STR_PAD_LEFT);

            $pdo->prepare("
                INSERT INTO pedidos_suprimentos (numero, impressora_id, setor, solicitante, status, observacoes)
                VALUES (?, ?, ?, ?, 'Pendente', ?)
            ")->execute([$numero_sup, $impressora_id, $setor_sup, $u['nome'], $observacoes ?: null]);

            $pedido_id = $pdo->lastInsertId();
            $st_item = $pdo->prepare("
                INSERT INTO pedidos_suprimentos_itens (pedido_id, tipo_suprimento_id, descricao_livre, quantidade)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($itens_validos as $item) {
                $st_item->execute([$pedido_id, $item['tipo_id'], $item['descricao'], $item['quantidade']]);
            }
            $pdo->commit();

            flash("Pedido {$numero_sup} registrado com sucesso.");
            header('Location: ' . ($impressora_id ? "impressora.php?id={$impressora_id}" : 'pedidos_suprimentos.php'));
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $erros[] = 'Erro ao registrar: ' . $e->getMessage();
        }
    }
}

layoutHeader('Pedir Suprimento', 'impressoras');
?>

<?php breadcrumb(array_filter([
    ['label' => 'Impressoras', 'href' => 'impressoras.php'],
    $impressora_sel_id ? ['label' => 'Detalhes', 'href' => 'impressora.php?id=' . $impressora_sel_id] : null,
    ['label' => 'Pedir Suprimento'],
])); ?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-cart-plus-fill me-2 text-primary"></i>Pedido de Suprimento</h1>
</div>

<div class="card">
  <div class="card-header">Solicitar Toner / Insumo</div>
  <div class="card-body">
    <?php if ($erros): ?>
      <div class="alert alert-danger py-2" style="font-size:13px">
        <?= implode('<br>', array_map('h', $erros)) ?>
      </div>
    <?php endif; ?>

    <form method="post" id="supAdminForm" novalidate>
      <?= csrfField() ?>
      <div class="row g-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold" style="font-size:13px">Impressora <span class="text-muted">(opcional)</span></label>
          <select name="impressora_id" id="supImpressora" class="form-select form-select-sm">
            <option value="" data-setor="">— Geral do Setor / Nenhuma específica —</option>
            <?php foreach ($impressoras as $imp):
              $selected = ($impressora_sel_id === (int)$imp['id'] || ($_POST['impressora_id'] ?? '') == $imp['id']) ? 'selected' : '';
            ?>
              <option value="<?= $imp['id'] ?>" data-setor="<?= h($imp['setor']) ?>" <?= $selected ?>><?= h($imp['nome']) ?> (<?= h($imp['setor']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" style="font-size:13px">Setor</label>
          <select name="setor" id="supSetor" class="form-select form-select-sm" required>
            <option value="">— Selecione o setor —</option>
            <?php foreach ($SETORES as $s):
              $selected = (($_POST['setor'] ?? $pre_setor) === $s) ? 'selected' : '';
            ?>
              <option value="<?= h($s) ?>" <?= $selected ?>><?= h($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <hr style="opacity:.1">
      <label class="form-label fw-bold mb-2" style="font-size:14px;color:var(--brand)">Lista de Insumos</label>
      <div id="itensContainer">
        <?php foreach ($tipos_ids_post as $idx => $tipo_post):
          $qtd_post  = $quantidades_post[$idx] ?? 1;
          $desc_post = $descricoes_post[$idx] ?? '';
        ?>
          <div class="item-row-sup d-flex align-items-start gap-2 mb-2">
            <div class="flex-grow-1">
              <select name="tipo_suprimento_id[]" class="form-select form-select-sm select-insumo" required>
                <option value="">— Selecione o Insumo —</option>
                <?php foreach ($tipos_suprimentos as $t): ?>
                  <option value="<?= $t['id'] ?>" <?= ((string)$tipo_post === (string)$t['id']) ? 'selected' : '' ?>><?= h($t['nome']) ?></option>
                <?php endforeach; ?>
                <option value="outro" <?= $tipo_post === 'outro' ? 'selected' : '' ?>>+ Outros (Especificar)</option>
              </select>
              <input type="text" name="descricao_livre[]" class="form-control form-control-sm input-desc-livre mt-1"
                     placeholder="Descreva o insumo..." value="<?= h($desc_post) ?>"
                     style="<?= $tipo_post === 'outro' ? '' : 'display:none' ?>">
            </div>
            <div style="width:72px">
              <input type="number" name="quantidade[]" class="form-control form-control-sm text-center" min="1" max="50" value="<?= h($qtd_post) ?>" required>
            </div>
            <button type="button" class="btn btn-outline-danger btn-xs btn-remove-item" title="Remover"><i class="bi bi-x-lg"></i></button>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-outline-secondary btn-sm mb-3" id="btnAddItem"><i class="bi bi-plus-lg me-1"></i>Adicionar outro insumo</button>

      <div class="mb-3">
        <label class="form-label fw-semibold" style="font-size:13px">Observações <span class="text-muted">(opcional)</span></label>
        <textarea name="observacoes" class="form-control form-control-sm" rows="2" placeholder="Ex: Entregar com urgência..."><?= h($_POST['observacoes'] ?? '') ?></textarea>
      </div>

      <div class="mt-4">
        <button type="submit" class="btn btn-primary btn-sm me-2"><i class="bi bi-send-fill me-1"></i>Enviar Pedido</button>
        <a href="<?= $impressora_sel_id ? 'impressora.php?id=' . $impressora_sel_id : 'pedidos_suprimentos.php' ?>" class="btn btn-outline-secondary btn-sm">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<template id="itemTemplateSup">
  <div class="item-row-sup d-flex align-items-start gap-2 mb-2">
    <div class="flex-grow-1">
      <select name="tipo_suprimento_id[]" class="form-select form-select-sm select-insumo" required>
        <option value="">— Selecione o Insumo —</option>
        <?php foreach ($tipos_suprimentos as $t): ?>
          <option value="<?= $t['id'] ?>"><?= h($t['nome']) ?></option>
        <?php endforeach; ?>
        <option value="outro">+ Outros (Especificar)</option>
      </select>
      <input type="text" name="descricao_livre[]" class="form-control form-control-sm input-desc-livre mt-1" placeholder="Descreva o insumo..." style="display:none">
    </div>
    <div style="width:72px">
      <input type="number" name="quantidade[]" class="form-control form-control-sm text-center" min="1" max="50" value="1" required>
    </div>
    <button type="button" class="btn btn-outline-danger btn-xs btn-remove-item" title="Remover"><i class="bi bi-x-lg"></i></button>
  </div>
</template>

<script>
(function(){
  const setorSel = document.getElementById('supSetor');
  const impSel   = document.getElementById('supImpressora');
  const container= document.getElementById('itensContainer');
  const tpl      = document.getElementById('itemTemplateSup');

  // Ao trocar a impressora, preenche o setor automaticamente
  impSel.addEventListener('change', function(){
    const opt = impSel.options[impSel.selectedIndex];
    const s = opt.getAttribute('data-setor');
    if (s) setorSel.value = s;
  });

  function bindRow(row){
    const sel  = row.querySelector('.select-insumo');
    const desc = row.querySelector('.input-desc-livre');
    sel.addEventListener('change', function(){
      desc.style.display = sel.value === 'outro' ? 'block' : 'none';
    });
    row.querySelector('.btn-remove-item').addEventListener('click', function(){
      if (container.querySelectorAll('.item-row-sup').length > 1) row.remove();
    });
  }
  container.querySelectorAll('.item-row-sup').forEach(bindRow);

  document.getElementById('btnAddItem').addEventListener('click', function(){
    const clone = tpl.content.cloneNode(true);
    container.appendChild(clone);
    bindRow(container.lastElementChild);
  });
})();
</script>

<?php layoutFooter(); ?>
