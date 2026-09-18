<?php
require 'db.php';
requireLogin();

$pdo = db();
$id  = (int)($_GET['id'] ?? 0);

$st = $pdo->prepare("SELECT * FROM contratos WHERE id=?");
$st->execute([$id]);
$c = $st->fetch();
if (!$c) { header('Location: contratos.php'); exit; }

$nomeArq = 'Contrato_'.$c['id'].'_'.preg_replace('/[^a-zA-Z0-9]/', '_', $c['nome']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Contrato — <?= h($c['nome']) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',Arial,sans-serif;font-size:13px;color:#111;background:#fff;padding:2cm}
.header{text-align:center;border-bottom:2px solid #1D3557;padding-bottom:16px;margin-bottom:24px}
.header .produto{font-size:20px;font-weight:700;color:#1D3557}
.header .vendor{font-size:12px;color:#64748b;margin-top:2px}
.titulo{font-size:16px;font-weight:700;text-align:center;margin:20px 0;text-transform:uppercase;letter-spacing:.08em;color:#1D3557}
.subtitulo{text-align:center;font-size:12px;color:#64748b;margin-bottom:28px}
.secao{margin-bottom:20px}
.secao h3{font-size:12px;font-weight:700;color:#1D3557;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;padding-bottom:4px;border-bottom:1px solid #e2e8f0}
.grid{display:grid;gap:4px 24px}
.grid-2{grid-template-columns:1fr 1fr}
.grid-3{grid-template-columns:1fr 1fr 1fr}
.campo{padding:6px 0;border-bottom:1px solid #f1f5f9}
.campo label{font-size:10px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:1px}
.campo span{font-size:13px;color:#111}
.corpo{font-size:12.5px;line-height:1.9;color:#374151;margin-bottom:24px;white-space:pre-wrap}
.corpo p{margin-bottom:8px}
.corpo strong{color:#1D3557}
.assinaturas{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:48px}
.ass-box{text-align:center}
.ass-linha{border-top:1px solid #111;padding-top:8px;margin-top:50px;font-size:12px;color:#374151}
.ass-box .cargo{font-size:11px;color:#64748b;margin-top:2px}
.rodape{margin-top:32px;font-size:10px;color:#94a3b8;text-align:center;border-top:1px solid #e2e8f0;padding-top:12px}
.badge-status{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700}
@media print{
  body{padding:1cm}
  .no-print{display:none}
  @page{size:A4;margin:1.5cm}
}
</style>
</head>
<body>

<div class="no-print" style="margin-bottom:20px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
  <button onclick="window.print()" style="background:#1D3557;color:#fff;border:none;padding:8px 20px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer">🖨 Imprimir</button>
  <button id="btnDownload" onclick="downloadPDF()" style="background:#2563eb;color:#fff;border:none;padding:8px 20px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer">⬇ Baixar PDF</button>
  <a href="contratos.php" style="background:#f1f5f9;color:#1D3557;border:1px solid #e2e8f0;padding:8px 16px;border-radius:6px;font-size:13px;text-decoration:none">← Voltar</a>
  <span id="pdfStatus" style="font-size:12px;color:#64748b;display:none">Gerando PDF…</span>
</div>

<div class="header">
  <div class="produto"><?= APP_NOME ?></div>
  <div class="vendor"><?= APP_VENDOR ?></div>
</div>

<div class="titulo"><?= h($c['nome']) ?></div>
<div class="subtitulo"><?= h($c['tipo']) ?> — gerado em <?= date('d/m/Y \à\s H:i') ?></div>

<div class="secao">
  <h3>Identificação do Contrato</h3>
  <div class="grid grid-3">
    <div class="campo"><label>Tipo</label><span><?= h($c['tipo']) ?></span></div>
    <div class="campo"><label>Nº do Contrato</label><span><?= h($c['numero_contrato'] ?: '—') ?></span></div>
    <div class="campo"><label>Status</label><span><?= h($c['status']) ?></span></div>
    <div class="campo"><label>Fornecedor</label><span><?= h($c['fornecedor'] ?: '—') ?></span></div>
    <div class="campo"><label>Início</label><span><?= $c['data_inicio'] ? date('d/m/Y', strtotime($c['data_inicio'])) : '—' ?></span></div>
    <div class="campo"><label>Vencimento</label><span><?= date('d/m/Y', strtotime($c['data_vencimento'])) ?></span></div>
    <?php if ($c['valor']): ?>
    <div class="campo"><label>Valor</label><span>R$ <?= number_format($c['valor'],2,',','.') ?> / <?= strtolower($c['periodicidade']) ?></span></div>
    <?php endif; ?>
    <div class="campo"><label>Renovação Automática</label><span><?= $c['renovacao_auto'] ? 'Sim' : 'Não' ?></span></div>
    <div class="campo"><label>Alerta (dias antes)</label><span><?= (int)$c['alerta_dias'] ?> dias</span></div>
  </div>
  <?php if ($c['observacoes']): ?>
  <div class="campo" style="margin-top:8px"><label>Observações internas</label><span><?= h($c['observacoes']) ?></span></div>
  <?php endif; ?>
</div>

<?php if ($c['corpo']): ?>
<div class="secao">
  <h3>Texto do Contrato / Cláusulas</h3>
  <div class="corpo"><?php
    $paragrafos = preg_split('/\n{2,}/', trim($c['corpo']));
    foreach ($paragrafos as $p):
      $p = trim($p);
      if (!$p) continue;
      $p_fmt = preg_replace('/^(\d+\.\s[\w\sÀ-ú]+\.)/', '<strong>$1</strong>', h($p));
      echo '<p>'.$p_fmt.'</p>';
    endforeach;
  ?></div>
</div>
<?php endif; ?>

<div class="assinaturas">
  <div class="ass-box">
    <div class="ass-linha"><?= h($c['fornecedor'] ?: 'Fornecedor') ?></div>
    <div class="cargo">Contratada — <?= h($c['tipo']) ?></div>
  </div>
  <div class="ass-box">
    <div class="ass-linha"><?= APP_NOME ?> — Setor de TI</div>
    <div class="cargo"><?= APP_VENDOR ?></div>
    <div class="cargo">Data: <?= date('d/m/Y') ?></div>
  </div>
</div>

<div class="rodape">
  <?= APP_NOME ?> · <?= APP_VENDOR ?> · <?= APP_URL ?> · Documento gerado em <?= date('d/m/Y H:i') ?> · Contrato #<?= $c['id'] ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
async function downloadPDF() {
  const btn    = document.getElementById('btnDownload');
  const status = document.getElementById('pdfStatus');
  btn.disabled = true;
  btn.textContent = '⏳ Gerando…';
  status.style.display = 'inline';

  const barra = document.querySelector('.no-print');
  barra.style.display = 'none';

  try {
    const canvas = await html2canvas(document.body, {
      scale: 2,
      useCORS: true,
      backgroundColor: '#ffffff',
      windowWidth: 794
    });

    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

    const pageW  = pdf.internal.pageSize.getWidth();
    const pageH  = pdf.internal.pageSize.getHeight();
    const imgW   = pageW;
    const imgH   = (canvas.height * pageW) / canvas.width;

    let posY = 0;
    let remaining = imgH;
    const imgData = canvas.toDataURL('image/jpeg', 0.95);
    while (remaining > 0) {
      if (posY > 0) pdf.addPage();
      pdf.addImage(imgData, 'JPEG', 0, -posY, imgW, imgH, '', 'FAST');
      posY      += pageH;
      remaining -= pageH;
    }

    pdf.save(<?= json_encode($nomeArq) ?> + '.pdf');
  } catch(e) {
    alert('Erro ao gerar PDF: ' + e.message);
  } finally {
    barra.style.display = 'flex';
    btn.disabled = false;
    btn.textContent = '⬇ Baixar PDF';
    status.style.display = 'none';
  }
}
</script>
</body>
</html>
