<?php
require 'db.php';
requireLogin();
require 'layout.php';

$pdo = db();
$inventario_id = (int)($_GET['inventario_id'] ?? 0);

// Lista equipamentos em uso (sem termo ativo)
$equipamentos = $pdo->query("
    SELECT i.* FROM inventario i
    WHERE i.status = 'Em Uso'
    AND i.id NOT IN (SELECT inventario_id FROM termos_uso WHERE status='Ativo')
    ORDER BY i.tipo, i.marca, i.modelo
")->fetchAll();

// Equipamento pré-selecionado
$equip_sel = null;
if ($inventario_id) {
    $st = $pdo->prepare("SELECT * FROM inventario WHERE id=?");
    $st->execute([$inventario_id]);
    $equip_sel = $st->fetch();
}

$setores = $pdo->query("SELECT nome FROM setores WHERE ativo=1 ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $inv_id    = (int)$_POST['inventario_id'];
    $resp_nome = trim($_POST['responsavel_nome'] ?? '');
    $resp_cpf  = trim($_POST['responsavel_cpf'] ?? '');
    $resp_mat  = trim($_POST['responsavel_matricula'] ?? '');
    $setor     = trim($_POST['setor'] ?? '');
    $data_ent  = $_POST['data_entrega'] ?? date('Y-m-d');
    $data_prev = $_POST['data_prevista_devolucao'] ?? '';
    $data_prev = $data_prev ?: null;
    $cond_ent  = trim($_POST['condicao_entrega'] ?? '');
    $obs       = trim($_POST['observacoes'] ?? '');

    if (!$inv_id || !$resp_nome) {
        flash('Equipamento e responsável são obrigatórios.', 'danger');
    } else {
        // Verifica se já existe termo ativo para este equipamento
        $chk = $pdo->prepare("SELECT id FROM termos_uso WHERE inventario_id=? AND status='Ativo'");
        $chk->execute([$inv_id]);
        if ($chk->fetch()) {
            flash('Este equipamento já possui um termo ativo. Registre a devolução primeiro.', 'danger');
        } else {
            $pdo->prepare("INSERT INTO termos_uso (inventario_id,responsavel_nome,responsavel_cpf,responsavel_matricula,setor,data_entrega,data_prevista_devolucao,condicao_entrega,observacoes,status,assinado_em) VALUES (?,?,?,?,?,?,?,?,?,'Ativo',NOW())")
                ->execute([$inv_id,$resp_nome,$resp_cpf,$resp_mat,$setor,$data_ent,$data_prev,$cond_ent,$obs]);
            $termo_id = $pdo->lastInsertId();
            flash('Termo gerado com sucesso.');
            header("Location: imprimir_termo.php?id=$termo_id"); exit;
        }
    }
}

layoutHeader('Novo Termo de Uso', 'termos');
?>

<?php breadcrumb([['label'=>'Termos de Uso','href'=>'termos.php'],['label'=>'Emitir Termo']]); ?>
<div class="page-header">
  <h1 class="page-title"><i class="bi bi-file-earmark-plus-fill me-2 text-primary"></i>Emitir Termo de Guarda/Uso</h1>
</div>

<div class="card">
  <div class="card-header">Dados do termo</div>
  <div class="card-body">
    <form method="post">
      <?= csrfField() ?>
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label fw-semibold" style="font-size:13px">Equipamento *</label>
          <select name="inventario_id" class="form-select form-select-sm" required>
            <option value="">Selecione o equipamento…</option>
            <?php if ($equip_sel && !in_array($equip_sel, $equipamentos)): ?>
              <option value="<?= $equip_sel['id'] ?>" selected><?= h("{$equip_sel['tipo']} — {$equip_sel['marca']} {$equip_sel['modelo']} (S/N: {$equip_sel['numero_serie']})") ?></option>
            <?php endif; ?>
            <?php foreach ($equipamentos as $eq): ?>
            <option value="<?= $eq['id'] ?>" <?= $inventario_id===$eq['id']?'selected':'' ?>>
              <?= h("{$eq['tipo']} — {$eq['marca']} {$eq['modelo']}" . ($eq['numero_serie'] ? " · S/N: {$eq['numero_serie']}" : '') . ($eq['patrimonio'] ? " · Pat: {$eq['patrimonio']}" : '')) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <?php if (!$equipamentos && !$equip_sel): ?>
          <div class="form-text text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Todos os equipamentos "Em Uso" já possuem termos ativos.</div>
          <?php endif; ?>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" style="font-size:13px">Nome do responsável *</label>
          <input type="text" name="responsavel_nome" class="form-control form-control-sm" required placeholder="Nome completo">
        </div>
        <div class="col-sm-3">
          <label class="form-label fw-semibold" style="font-size:13px">CPF</label>
          <input type="text" name="responsavel_cpf" class="form-control form-control-sm" placeholder="000.000.000-00" maxlength="14">
        </div>
        <div class="col-sm-3">
          <label class="form-label fw-semibold" style="font-size:13px">Matrícula</label>
          <input type="text" name="responsavel_matricula" class="form-control form-control-sm">
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" style="font-size:13px">Setor</label>
          <input type="text" name="setor" class="form-control form-control-sm" list="lst-setores" placeholder="Setor do colaborador">
          <datalist id="lst-setores"><?php foreach($setores as $s): ?><option value="<?=h($s)?>">  <?php endforeach; ?></datalist>
        </div>
        <div class="col-sm-3">
          <label class="form-label fw-semibold" style="font-size:13px">Data de entrega</label>
          <input type="date" name="data_entrega" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-sm-3">
          <label class="form-label fw-semibold" style="font-size:13px">Devolução prevista</label>
          <input type="date" name="data_prevista_devolucao" class="form-control form-control-sm" min="<?= date('Y-m-d') ?>">
          <div class="form-text">Opcional — deixe em branco para guarda permanente</div>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold" style="font-size:13px">Condição do equipamento na entrega</label>
          <textarea name="condicao_entrega" class="form-control form-control-sm" rows="2" placeholder="Ex: Bom estado, sem arranhões, bateria em bom funcionamento…"></textarea>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold" style="font-size:13px">Observações</label>
          <textarea name="observacoes" class="form-control form-control-sm" rows="2" placeholder="Acessórios entregues, carregadores, bolsas…"></textarea>
        </div>
      </div>
      <div class="mt-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-printer me-1"></i>Gerar e Imprimir Termo</button>
        <a href="termos.php" class="btn btn-outline-secondary btn-sm">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?php layoutFooter(); ?>
