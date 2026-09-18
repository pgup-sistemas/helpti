<?php
require 'db.php';
requireLogin();
require 'layout.php';

$pdo = db();
$u = usuario();
$erros = [];

// Pre-seleção da impressora se passado via GET
$impressora_get_id = (int)($_GET['impressora_id'] ?? 0);

// Buscar impressoras para o dropdown
$impressoras = $pdo->query("SELECT id, nome, setor, status FROM impressoras ORDER BY nome ASC")->fetchAll();

// Buscar técnicos para o dropdown
$tecnicos = $pdo->query("SELECT id, nome FROM usuarios WHERE ativo = 1 AND perfil IN ('tecnico', 'admin') ORDER BY nome ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $impressora_id      = (int)($_POST['impressora_id'] ?? 0);
    $tecnico_id         = $_POST['tecnico_id'] ? (int)$_POST['tecnico_id'] : null;
    $tipo               = $_POST['tipo'] ?? 'Corretiva';
    $data_manutencao    = $_POST['data_manutencao'] ?? date('Y-m-d');
    $status             = $_POST['status'] ?? 'Concluída';
    $descricao_problema = trim($_POST['descricao_problema'] ?? '');
    $solucao            = trim($_POST['solucao'] ?? '');
    $pecas_trocadas     = trim($_POST['pecas_trocadas'] ?? '');

    if (!$impressora_id)      $erros[] = 'Selecione a impressora.';
    if (!$descricao_problema) $erros[] = 'Preencha a descrição do problema / motivo.';
    if (!$data_manutencao)    $erros[] = 'Selecione a data da manutenção.';
    
    if ($status === 'Concluída' && !$solucao) {
        $erros[] = 'Para manutenções Concluídas, é obrigatório registrar a solução técnica aplicada.';
    }

    if (!$erros) {
        // Inserir manutenção
        $stmt = $pdo->prepare("
            INSERT INTO manutencoes_impressoras (impressora_id, tecnico_id, tipo, descricao_problema, solucao, pecas_trocadas, data_manutencao, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $impressora_id,
            $tecnico_id,
            $tipo,
            $descricao_problema,
            $solucao ?: null,
            $pecas_trocadas ?: null,
            $data_manutencao,
            $status
        ]);

        // Sincronizar o status da impressora de forma inteligente
        if ($status === 'Pendente' || $status === 'Em Realização') {
            $pdo->prepare("UPDATE impressoras SET status = 'Em Manutenção' WHERE id = ?")->execute([$impressora_id]);
        } else {
            // Se concluída, garante que o status volta a ser 'Ativa'
            $pdo->prepare("UPDATE impressoras SET status = 'Ativa' WHERE id = ? AND status = 'Em Manutenção'")->execute([$impressora_id]);
        }

        flash("Registro de manutenção salvo com sucesso!");
        header("Location: impressora.php?id=" . $impressora_id);
        exit;
    }
}

layoutHeader('Registrar Manutenção', 'manutencoes');

// Nome da impressora pré-selecionada (se veio via GET), para o breadcrumb
$imp_nome_bc = null;
if ($impressora_get_id) {
    foreach ($impressoras as $i) {
        if ((int)$i['id'] === $impressora_get_id) { $imp_nome_bc = $i['nome']; break; }
    }
}
?>

<?php breadcrumb(array_filter([
    ['label'=>'Impressoras','href'=>'impressoras.php'],
    $imp_nome_bc ? ['label'=>$imp_nome_bc,'href'=>'impressora.php?id='.$impressora_get_id] : null,
    ['label'=>'Registrar Manutenção'],
])); ?>
<div class="page-header">
  <h1 class="page-title"><i class="bi bi-wrench-adjustable-fill me-2 text-primary"></i>Registrar Manutenção</h1>
</div>

<div class="card">
  <div class="card-header">Lançar Ordem de Serviço / Manutenção de Impressora</div>
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
          <label class="form-label fw-semibold" style="font-size:13px">Impressora</label>
          <select name="impressora_id" class="form-select form-select-sm" required>
            <option value="">— Selecione a Impressora —</option>
            <?php foreach ($impressoras as $i): ?>
              <?php 
                $selected = ($impressora_get_id === (int)$i['id'] || ($_POST['impressora_id'] ?? '') == $i['id']) ? 'selected' : '';
              ?>
              <option value="<?= $i['id'] ?>" <?= $selected ?>><?= h($i['nome']) ?> (<?= h($i['setor']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" style="font-size:13px">Técnico Responsável</label>
          <select name="tecnico_id" class="form-select form-select-sm">
            <option value="">Sem atribuição</option>
            <?php foreach ($tecnicos as $t): ?>
              <?php 
                $selected = (($u['id'] == $t['id'] && !isset($_POST['tecnico_id'])) || ($_POST['tecnico_id'] ?? '') == $t['id']) ? 'selected' : '';
              ?>
              <option value="<?= $t['id'] ?>" <?= $selected ?>><?= h($t['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-sm-4">
          <label class="form-label fw-semibold" style="font-size:13px">Tipo de Manutenção</label>
          <select name="tipo" class="form-select form-select-sm">
            <option value="Corretiva" <?= ($_POST['tipo'] ?? '') === 'Corretiva' ? 'selected' : '' ?>>Corretiva (Corrigir Falha)</option>
            <option value="Preventiva" <?= ($_POST['tipo'] ?? '') === 'Preventiva' ? 'selected' : '' ?>>Preventiva (Limpeza/Revisão)</option>
          </select>
        </div>
        <div class="col-sm-4">
          <label class="form-label fw-semibold" style="font-size:13px">Data do Serviço</label>
          <input type="date" name="data_manutencao" class="form-control form-control-sm" value="<?= h($_POST['data_manutencao'] ?? date('Y-m-d')) ?>" required>
        </div>
        <div class="col-sm-4">
          <label class="form-label fw-semibold" style="font-size:13px">Status Inicial</label>
          <select name="status" class="form-select form-select-sm">
            <option value="Concluída" <?= ($_POST['status'] ?? '') === 'Concluída' ? 'selected' : '' ?>>Concluída (Finalizada)</option>
            <option value="Pendente" <?= ($_POST['status'] ?? '') === 'Pendente' ? 'selected' : '' ?>>Pendente (Aguardando)</option>
            <option value="Em Realização" <?= ($_POST['status'] ?? '') === 'Em Realização' ? 'selected' : '' ?>>Em Realização</option>
          </select>
        </div>

        <div class="col-12">
          <label class="form-label fw-semibold" style="font-size:13px">Descrição do Problema / Motivo da Manutenção</label>
          <textarea name="descricao_problema" class="form-control form-control-sm" rows="3" placeholder="Descreva os sintomas apresentados pela impressora ou o motivo da preventiva..." required><?= h($_POST['descricao_problema'] ?? '') ?></textarea>
        </div>

        <div class="col-12">
          <label class="form-label fw-semibold" style="font-size:13px">Solução Técnica Aplicada <span class="text-muted">(obrigatório se concluída)</span></label>
          <textarea name="solucao" class="form-control form-control-sm" rows="3" placeholder="Descreva os procedimentos técnicos adotados para a solução do problema..."><?= h($_POST['solucao'] ?? '') ?></textarea>
        </div>

        <div class="col-12">
          <label class="form-label fw-semibold" style="font-size:13px">Peças / Insumos Trocados (opcional)</label>
          <input type="text" name="pecas_trocadas" class="form-control form-control-sm" placeholder="Ex: Película de fusor, rolo pressor, engrenagem..." value="<?= h($_POST['pecas_trocadas'] ?? '') ?>">
        </div>
      </div>

      <div class="mt-4">
        <button type="submit" class="btn btn-primary btn-sm me-2"><i class="bi bi-save me-1"></i>Salvar Registro</button>
        <a href="manutencoes.php" class="btn btn-outline-secondary btn-sm">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?php layoutFooter(); ?>
