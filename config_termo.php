<?php
require 'db.php';
requireGestora();
require 'layout.php';

$pdo = db();

// Carrega configurações atuais
function getConfig(PDO $pdo): array {
    $rows = $pdo->query("SELECT chave, valor FROM config_termos")->fetchAll(PDO::FETCH_KEY_PAIR);
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $campos = ['titulo', 'subtitulo', 'clausulas', 'assinatura_ti', 'rodape'];
    foreach ($campos as $c) {
        $val = trim($_POST[$c] ?? '');
        $pdo->prepare("INSERT INTO config_termos (chave, valor) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_em = NOW()")
            ->execute([$c, $val]);
    }
    flash('Modelo do termo atualizado com sucesso.', 'success');
    header('Location: config_termo.php'); exit;
}

$cfg = getConfig($pdo);
layoutHeader('Modelo do Termo', 'termos');
?>

<?php breadcrumb([['label'=>'Termos de Uso','href'=>'termos.php'],['label'=>'Modelo do Termo']]); ?>
<div class="page-header">
  <h1 class="page-title"><i class="bi bi-file-earmark-text-fill me-2 text-primary"></i>Modelo do Termo de Uso</h1>
</div>

<div class="d-flex flex-column gap-3">

  <!-- Editor -->
  <div class="card">
    <div class="card-header"><i class="bi bi-pencil-square me-2 text-primary"></i>Editar conteúdo do termo</div>
    <div class="card-body">
      <form method="post" id="formTermo">
        <?= csrfField() ?>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold" style="font-size:13px">Título do documento</label>
            <input type="text" name="titulo" class="form-control form-control-sm" value="<?= h($cfg['titulo'] ?? '') ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" style="font-size:13px">Subtítulo / descrição</label>
            <input type="text" name="subtitulo" class="form-control form-control-sm" value="<?= h($cfg['subtitulo'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" style="font-size:13px">Nome / cargo na assinatura TI</label>
            <input type="text" name="assinatura_ti" class="form-control form-control-sm" value="<?= h($cfg['assinatura_ti'] ?? '') ?>" placeholder="Ex: Setor de Tecnologia da Informação">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" style="font-size:13px">Rodapé adicional <span class="text-muted fw-normal">(opcional)</span></label>
            <input type="text" name="rodape" class="form-control form-control-sm" value="<?= h($cfg['rodape'] ?? '') ?>" placeholder="Ex: Documento confidencial — uso interno">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold" style="font-size:13px">Cláusulas e condições</label>
            <div class="form-text mb-1">
              Cada parágrafo separado por linha em branco. Use <strong>Negrito:</strong> para destacar o nome da cláusula (ex: <code>1. Finalidade. texto...</code>).
            </div>
            <textarea name="clausulas" id="editorClausulas" class="form-control" rows="14" style="font-size:13px;font-family:monospace"><?= h($cfg['clausulas'] ?? '') ?></textarea>
          </div>
        </div>
        <div class="d-flex gap-2 mt-3">
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Salvar modelo</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('preview').contentWindow.location.reload()">
            <i class="bi bi-eye me-1"></i>Atualizar prévia
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Prévia ao vivo -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span><i class="bi bi-eye me-2 text-primary"></i>Prévia do documento</span>
      <a href="imprimir_termo.php?preview=1" target="_blank" class="btn btn-outline-primary btn-xs">Abrir em nova aba</a>
    </div>
    <div class="card-body p-0">
      <iframe id="preview" src="imprimir_termo.php?preview=1"
        style="width:100%;height:700px;border:none;border-radius:0 0 8px 8px"></iframe>
    </div>
  </div>

</div>

<script>
// Auto-atualiza o iframe ao parar de digitar
let timer;
document.getElementById('editorClausulas').addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        document.getElementById('preview').contentWindow.location.reload();
    }, 1500);
});
</script>

<?php layoutFooter(); ?>
