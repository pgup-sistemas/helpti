<?php
require 'db.php';
requireLogin();

$pdo = db();
$id  = (int)($_GET['id'] ?? 0);

if ($id) {
    $st = $pdo->prepare("SELECT * FROM inventario WHERE id=?");
    $st->execute([$id]);
    $inv = $st->fetch();
    if (!$inv) { header('Location: inventario.php'); exit; }
    $lista = [$inv];
} else {
    // Todos os equipamentos ativos
    $lista = $pdo->query("SELECT * FROM inventario WHERE status != 'Descartado' ORDER BY tipo, marca, modelo")->fetchAll();
}

$portalUrl = APP_URL . '/portal.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Etiquetas QR — <?= APP_NOME ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',Arial,sans-serif;background:#f1f5f9;padding:1.5rem;font-size:13px;color:#111}

/* Barra de ações — some na impressão */
.toolbar{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:.85rem 1.25rem;margin-bottom:1.5rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.toolbar h1{font-size:15px;font-weight:700;color:#1D3557}
.toolbar p{font-size:12px;color:#64748b;margin-top:2px}
.toolbar-btns{display:flex;gap:.5rem;flex-wrap:wrap}
.btn{padding:.4rem .9rem;border-radius:6px;font-size:12.5px;font-weight:600;cursor:pointer;border:1px solid;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem}
.btn-primary{background:#1D3557;color:#fff;border-color:#1D3557}
.btn-outline{background:#fff;color:#1D3557;border-color:#cbd5e1}
.btn:hover{opacity:.88}

/* Grade de etiquetas */
.grade{display:flex;flex-wrap:wrap;gap:1rem;justify-content:flex-start}

/* Etiqueta */
.etiqueta{
  background:#fff;
  border:1.5px solid #e2e8f0;
  border-radius:10px;
  width:220px;
  padding:.85rem .9rem;
  display:flex;
  flex-direction:column;
  align-items:center;
  gap:.5rem;
  page-break-inside:avoid;
}
.etiqueta-topo{width:100%;display:flex;justify-content:space-between;align-items:center}
.etiqueta-logo{font-size:10px;font-weight:700;color:#1D3557;letter-spacing:.05em}
.etiqueta-tipo{font-size:9.5px;color:#64748b;background:#f1f5f9;padding:2px 6px;border-radius:4px;font-weight:600}
.qr-wrap{background:#fff;padding:4px;border:1px solid #e2e8f0;border-radius:6px}
.etiqueta-nome{font-size:12px;font-weight:700;color:#0f172a;text-align:center;line-height:1.3}
.etiqueta-sub{font-size:10.5px;color:#64748b;text-align:center;line-height:1.4}
.etiqueta-pat{font-size:9px;font-family:monospace;color:#94a3b8;margin-top:2px;text-align:center}
.etiqueta-cta{font-size:9px;color:#1D3557;font-weight:600;text-align:center;margin-top:2px;letter-spacing:.03em}

@media print {
  body{background:#fff;padding:.5cm}
  .toolbar{display:none}
  .grade{gap:.6cm}
  .etiqueta{border:1px solid #ccc;border-radius:6px;width:6cm;padding:.4cm}
  @page{size:A4;margin:1cm}
}
</style>
</head>
<body>

<div class="toolbar">
  <div>
    <h1>🏷️ Etiquetas com QR Code — <?= APP_NOME ?></h1>
    <p>Escaneie o QR com o celular para abrir o portal de TI e registrar um chamado para este equipamento.</p>
  </div>
  <div class="toolbar-btns">
    <a href="inventario.php" class="btn btn-outline"><i class="bi bi-arrow-left me-1"></i>Inventário</a>
    <button onclick="window.print()" class="btn btn-primary">🖨 Imprimir etiquetas</button>
  </div>
</div>

<div class="grade" id="grade">
  <?php foreach ($lista as $inv):
    // URL da página pública com patrimônio/modelo pré-identificado
    $desc_pre = 'Chamado referente ao equipamento: ' . trim($inv['marca'] . ' ' . $inv['modelo']);
    if ($inv['patrimonio']) $desc_pre .= ' (PAT: ' . $inv['patrimonio'] . ')';
    elseif ($inv['numero_serie']) $desc_pre .= ' (S/N: ' . $inv['numero_serie'] . ')';

    $url_qr = $portalUrl . '?desc=' . rawurlencode($desc_pre);
    $nome   = trim($inv['marca'] . ' ' . $inv['modelo']) ?: $inv['tipo'];
    $pat    = $inv['patrimonio'] ?: ($inv['numero_serie'] ? 'S/N: '.$inv['numero_serie'] : '#'.$inv['id']);
  ?>
  <div class="etiqueta" id="etq-<?= $inv['id'] ?>">
    <div class="etiqueta-topo">
      <div class="etiqueta-logo"><?= APP_NOME ?></div>
      <div class="etiqueta-tipo"><?= h($inv['tipo']) ?></div>
    </div>
    <div class="qr-wrap">
      <canvas class="qr-canvas" data-url="<?= h($url_qr) ?>" width="120" height="120"></canvas>
    </div>
    <div class="etiqueta-nome"><?= h($nome) ?></div>
    <?php if ($inv['setor']): ?>
    <div class="etiqueta-sub"><?= h($inv['setor']) ?></div>
    <?php endif; ?>
    <div class="etiqueta-pat"><?= h($pat) ?></div>
    <div class="etiqueta-cta">▲ Escaneie para abrir chamado de TI</div>
  </div>
  <?php endforeach; ?>
</div>

<!-- QR Code lib -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
// Gera QR em cada canvas
document.querySelectorAll('.qr-canvas').forEach(canvas => {
  const url = canvas.dataset.url;
  // Usa a lib para criar num div temporário e copia o canvas
  const tmp = document.createElement('div');
  tmp.style.display = 'none';
  document.body.appendChild(tmp);

  new QRCode(tmp, {
    text: url,
    width: 120,
    height: 120,
    colorDark: '#0F172A',
    colorLight: '#FFFFFF',
    correctLevel: QRCode.CorrectLevel.M
  });

  // Quando o QR renderizar, copia para o canvas da etiqueta
  setTimeout(() => {
    const qrCanvas = tmp.querySelector('canvas');
    const qrImg    = tmp.querySelector('img');
    const ctx = canvas.getContext('2d');
    if (qrCanvas) {
      ctx.drawImage(qrCanvas, 0, 0, 120, 120);
    } else if (qrImg) {
      const img = new Image();
      img.onload = () => ctx.drawImage(img, 0, 0, 120, 120);
      img.src = qrImg.src;
    }
    document.body.removeChild(tmp);
  }, 200);
});
</script>
</body>
</html>
