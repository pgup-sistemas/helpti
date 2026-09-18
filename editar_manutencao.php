<?php
require 'db.php';
requireLogin();
require 'layout.php';

$pdo = db();
$u = usuario();
$id = (int)($_GET['id'] ?? 0);
$erros = [];

// Buscar manutenção
$stmt = $pdo->prepare("
    SELECT m.*, i.nome AS impressora_nome, i.setor AS impressora_setor 
    FROM manutencoes_impressoras m
    INNER JOIN impressoras i ON i.id = m.impressora_id
    WHERE m.id = ?
");
$stmt->execute([$id]);
$manut = $stmt->fetch();

if (!$manut) {
    flash("Manutenção não encontrada.", "danger");
    header("Location: manutencoes.php");
    exit;
}

// Buscar técnicos para o dropdown
$tecnicos = $pdo->query("SELECT id, nome FROM usuarios WHERE ativo = 1 AND perfil IN ('tecnico', 'admin') ORDER BY nome ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $tecnico_id         = $_POST['tecnico_id'] ? (int)$_POST['tecnico_id'] : null;
    $tipo               = $_POST['tipo'] ?? 'Corretiva';
    $data_manutencao    = $_POST['data_manutencao'] ?? date('Y-m-d');
    $status             = $_POST['status'] ?? 'Concluída';
    $descricao_problema = trim($_POST['descricao_problema'] ?? '');
    $solucao            = trim($_POST['solucao'] ?? '');
    $pecas_trocadas     = trim($_POST['pecas_trocadas'] ?? '');

    if (!$descricao_problema) $erros[] = 'Preencha a descrição do problema / motivo.';
    if (!$data_manutencao)    $erros[] = 'Selecione a data da manutenção.';
    
    if ($status === 'Concluída' && !$solucao) {
        $erros[] = 'Para manutenções Concluídas, é obrigatório registrar a solução técnica aplicada.';
    }

    if (!$erros) {
        // Atualizar manutenção
        $stmt_update = $pdo->prepare("
            UPDATE manutencoes_impressoras 
            SET tecnico_id = ?, tipo = ?, data_manutencao = ?, status = ?, descricao_problema = ?, solucao = ?, pecas_trocadas = ?
            WHERE id = ?
        ");
        $stmt_update->execute([
            $tecnico_id,
            $tipo,
            $data_manutencao,
            $status,
            $descricao_problema,
            $solucao ?: null,
            $pecas_trocadas ?: null,
            $id
        ]);

        $imp_id = $manut['impressora_id'];

        // Sincronizar o status da impressora de forma inteligente
        if ($status === 'Pendente' || $status === 'Em Realização') {
            $pdo->prepare("UPDATE impressoras SET status = 'Em Manutenção' WHERE id = ?")->execute([$imp_id]);
        } else {
            // Se concluída, verifica se não existem OUTRAS manutenções pendentes/em andamento para a mesma impressora
            $chk = $pdo->prepare("
                SELECT COUNT(*) FROM manutencoes_impressoras 
                WHERE impressora_id = ? AND status IN ('Pendente', 'Em Realização') AND id != ?
            ");
            $chk->execute([$imp_id, $id]);
            $outras_pendentes = (int)$chk->fetchColumn();

            if ($outras_pendentes === 0) {
                // Caso não tenha mais manutenções pendentes, a impressora volta a ficar "Ativa"
                $pdo->prepare("UPDATE impressoras SET status = 'Ativa' WHERE id = ? AND status = 'Em Manutenção'")->execute([$imp_id]);
            }
        }

        flash("Registro de manutenção atualizado com sucesso!");
        header("Location: impressora.php?id=" . $imp_id);
        exit;
    }
}

layoutHeader('Editar Manutenção', 'manutencoes');
?>

<?php breadcrumb([['label'=>'Impressoras','href'=>'impressoras.php'],['label'=>$manut['impressora_nome'],'href'=>'impressora.php?id='.$manut['impressora_id']],['label'=>'Editar Manutenção']]); ?>

<div class="page-header mt-1">
  <h1 class="page-title"><i class="bi bi-pencil-square me-2 text-primary"></i>Editar Registro de Manutenção</h1>
</div>

<div class="card">
  <div class="card-header">
    Ordem de Serviço Nº <?= $id ?> — <strong><?= h($manut['impressora_nome']) ?></strong> (<?= h($manut['impressora_setor']) ?>)
  </div>
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
          <label class="form-label fw-semibold" style="font-size:13px">Técnico Responsável</label>
          <select name="tecnico_id" class="form-select form-select-sm">
            <option value="">Sem atribuição</option>
            <?php foreach ($tecnicos as $t): ?>
              <?php 
                $selected = (($manut['tecnico_id'] == $t['id'] && !isset($_POST['tecnico_id'])) || ($_POST['tecnico_id'] ?? '') == $t['id']) ? 'selected' : '';
              ?>
              <option value="<?= $t['id'] ?>" <?= $selected ?>><?= h($t['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-sm-6">
          <label class="form-label fw-semibold" style="font-size:13px">Tipo de Manutenção</label>
          <select name="tipo" class="form-select form-select-sm">
            <option value="Corretiva" <?= ($_POST['tipo'] ?? $manut['tipo']) === 'Corretiva' ? 'selected' : '' ?>>Corretiva (Corrigir Falha)</option>
            <option value="Preventiva" <?= ($_POST['tipo'] ?? $manut['tipo']) === 'Preventiva' ? 'selected' : '' ?>>Preventiva (Limpeza/Revisão)</option>
          </select>
        </div>
        
        <div class="col-sm-6">
          <label class="form-label fw-semibold" style="font-size:13px">Data do Serviço</label>
          <input type="date" name="data_manutencao" class="form-control form-control-sm" value="<?= h($_POST['data_manutencao'] ?? $manut['data_manutencao']) ?>" required>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" style="font-size:13px">Status</label>
          <select name="status" class="form-select form-select-sm">
            <option value="Concluída" <?= ($_POST['status'] ?? $manut['status']) === 'Concluída' ? 'selected' : '' ?>>Concluída (Finalizada)</option>
            <option value="Pendente" <?= ($_POST['status'] ?? $manut['status']) === 'Pendente' ? 'selected' : '' ?>>Pendente (Aguardando)</option>
            <option value="Em Realização" <?= ($_POST['status'] ?? $manut['status']) === 'Em Realização' ? 'selected' : '' ?>>Em Realização</option>
          </select>
        </div>

        <div class="col-12">
          <label class="form-label fw-semibold" style="font-size:13px">Descrição do Problema / Motivo da Manutenção</label>
          <textarea name="descricao_problema" class="form-control form-control-sm" rows="3" placeholder="Descreva os sintomas..." required><?= h($_POST['descricao_problema'] ?? $manut['descricao_problema']) ?></textarea>
        </div>

        <div class="col-12">
          <label class="form-label fw-semibold" style="font-size:13px">Solução Técnica Aplicada <span class="text-muted">(obrigatório se concluída)</span></label>
          <textarea name="solucao" class="form-control form-control-sm" rows="3" placeholder="Descreva a solução adotada..."><?= h($_POST['solucao'] ?? $manut['solucao'] ?? '') ?></textarea>
        </div>

        <div class="col-12">
          <label class="form-label fw-semibold" style="font-size:13px">Peças / Insumos Trocados (opcional)</label>
          <input type="text" name="pecas_trocadas" class="form-control form-control-sm" placeholder="Ex: Tracionador de papel..." value="<?= h($_POST['pecas_trocadas'] ?? $manut['pecas_trocadas'] ?? '') ?>">
        </div>
      </div>

      <div class="mt-4">
        <button type="submit" class="btn btn-primary btn-sm me-2"><i class="bi bi-save me-1"></i>Salvar Alterações</button>
        <a href="impressora.php?id=<?= $manut['impressora_id'] ?>" class="btn btn-outline-secondary btn-sm">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?php layoutFooter(); ?>
