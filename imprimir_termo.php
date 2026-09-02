<?php
require 'db.php';
requireLogin();

$pdo = db();
$id      = (int)($_GET['id'] ?? 0);
$preview = isset($_GET['preview']);

// Carrega configuração do modelo
$cfg_rows = $pdo->query("SELECT chave, valor FROM config_termos")->fetchAll(PDO::FETCH_KEY_PAIR);
$cfg = [
    'titulo'        => $cfg_rows['titulo']       ?? 'Termo de Responsabilidade pelo Uso e Guarda de Equipamento',
    'subtitulo'     => $cfg_rows['subtitulo']    ?? 'Documento de controle de ativo de TI',
    'clausulas'     => $cfg_rows['clausulas']    ?? '',
    'assinatura_ti' => $cfg_rows['assinatura_ti'] ?? 'Setor de Tecnologia da Informação',
    'rodape'        => $cfg_rows['rodape']        ?? '',
];

// Modo prévia: usa dados fictícios
if ($preview) {
    $termo = [
        'id' => 0,
        'responsavel_nome' => 'João da Silva (prévia)',
        'responsavel_cpf'  => '000.000.000-00',
        'responsavel_matricula' => '001',
        'setor'            => 'Tecnologia da Informação',
        'data_entrega'     => date('Y-m-d'),
        'data_prevista_devolucao' => null,
        'tipo'             => 'Notebook',
        'marca'            => 'Dell',
        'modelo'           => 'Latitude 5520',
        'numero_serie'     => 'SN-PREVIEW-001',
        'patrimonio'       => 'PAT-001',
        'imei'             => '',
        'valor'            => 4500.00,
        'condicao_entrega' => 'Bom estado geral',
        'observacoes'      => 'Acompanha carregador original',
    ];
} else {
    $st = $pdo->prepare("
        SELECT t.*, i.tipo, i.marca, i.modelo, i.numero_serie, i.patrimonio, i.imei, i.valor, i.data_aquisicao
        FROM termos_uso t JOIN inventario i ON i.id=t.inventario_id WHERE t.id=?
    ");
    $st->execute([$id]);
    $termo = $st->fetch();
    if (!$termo) { die('Termo não encontrado.'); }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Termo de Guarda — <?= h("{$termo['tipo']} · {$termo['responsavel_nome']}") ?></title>
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
.clausulas{font-size:12px;line-height:1.8;color:#374151;margin-bottom:24px}
.clausulas p{margin-bottom:8px}
.clausulas strong{color:#1D3557}
.assinaturas{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:40px}
.ass-box{text-align:center}
.ass-linha{border-top:1px solid #111;padding-top:8px;margin-top:50px;font-size:12px;color:#374151}
.ass-box .cargo{font-size:11px;color:#64748b;margin-top:2px}
.rodape{margin-top:32px;font-size:10px;color:#94a3b8;text-align:center;border-top:1px solid #e2e8f0;padding-top:12px}
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
  <a href="termos.php" style="background:#f1f5f9;color:#1D3557;border:1px solid #e2e8f0;padding:8px 16px;border-radius:6px;font-size:13px;text-decoration:none">← Voltar</a>
  <span id="pdfStatus" style="font-size:12px;color:#64748b;display:none">Gerando PDF…</span>
</div>

<div class="header">
  <div class="produto"><?= APP_NOME ?></div>
  <div class="vendor"><?= APP_VENDOR ?></div>
</div>

<div class="titulo"><?= h($cfg['titulo']) ?></div>
<div class="subtitulo"><?= h($cfg['subtitulo']) ?> — emitido em <?= date('d/m/Y \à\s H:i') ?></div>

<div class="secao">
  <h3>Dados do Colaborador</h3>
  <div class="grid grid-3">
    <div class="campo"><label>Nome completo</label><span><?= h($termo['responsavel_nome']) ?></span></div>
    <div class="campo"><label>CPF</label><span><?= h($termo['responsavel_cpf'] ?: '—') ?></span></div>
    <div class="campo"><label>Matrícula</label><span><?= h($termo['responsavel_matricula'] ?: '—') ?></span></div>
    <div class="campo"><label>Setor / Departamento</label><span><?= h($termo['setor'] ?: '—') ?></span></div>
    <div class="campo"><label>Data de entrega</label><span><?= date('d/m/Y', strtotime($termo['data_entrega'])) ?></span></div>
    <?php if ($termo['data_prevista_devolucao']): ?>
    <div class="campo"><label>Devolução prevista</label><span><?= date('d/m/Y', strtotime($termo['data_prevista_devolucao'])) ?></span></div>
    <?php endif; ?>
  </div>
</div>

<div class="secao">
  <h3>Identificação do Equipamento</h3>
  <div class="grid grid-3">
    <div class="campo"><label>Tipo</label><span><?= h($termo['tipo']) ?></span></div>
    <div class="campo"><label>Marca / Modelo</label><span><?= h("{$termo['marca']} {$termo['modelo']}") ?></span></div>
    <div class="campo"><label>Número de Série</label><span><?= h($termo['numero_serie'] ?: '—') ?></span></div>
    <div class="campo"><label>Patrimônio</label><span><?= h($termo['patrimonio'] ?: '—') ?></span></div>
    <?php if ($termo['imei']): ?>
    <div class="campo"><label>IMEI / MEID</label><span><?= h($termo['imei']) ?></span></div>
    <?php endif; ?>
    <?php if ($termo['valor']): ?>
    <div class="campo"><label>Valor Aprox.</label><span>R$ <?= number_format($termo['valor'],2,',','.') ?></span></div>
    <?php endif; ?>
  </div>
  <?php if ($termo['condicao_entrega']): ?>
  <div class="campo" style="margin-top:8px"><label>Condição na entrega</label><span><?= h($termo['condicao_entrega']) ?></span></div>
  <?php endif; ?>
  <?php if ($termo['observacoes']): ?>
  <div class="campo" style="margin-top:4px"><label>Observações / Acessórios</label><span><?= h($termo['observacoes']) ?></span></div>
  <?php endif; ?>
</div>

<div class="secao">
  <h3>Cláusulas e Condições de Uso</h3>
  <div class="clausulas">
    <?php
    // Cada parágrafo separado por linha em branco; primeira palavra em negrito se terminar com ponto
    $paragrafos = preg_split('/\n{2,}/', trim($cfg['clausulas']));
    foreach ($paragrafos as $p):
        $p = trim($p);
        if (!$p) continue;
        // Destaca "N. Título." no início do parágrafo em negrito
        $p_fmt = preg_replace('/^(\d+\.\s[\w\s]+\.)/', '<strong>$1</strong>', h($p));
    ?>
    <p><?= $p_fmt ?></p>
    <?php endforeach; ?>
  </div>
</div>

<div class="assinaturas">
  <div class="ass-box">
    <div class="ass-linha"><?= h($termo['responsavel_nome']) ?></div>
    <div class="cargo">Colaborador — <?= h($termo['setor'] ?: 'Setor') ?></div>
    <?php if ($termo['responsavel_cpf']): ?><div class="cargo">CPF: <?= h($termo['responsavel_cpf']) ?></div><?php endif; ?>
  </div>
  <div class="ass-box">
    <div class="ass-linha"><?= h($cfg['assinatura_ti']) ?></div>
    <div class="cargo"><?= APP_VENDOR ?></div>
    <div class="cargo">Data: <?= date('d/m/Y') ?></div>
  </div>
</div>

<div class="rodape">
  <?= APP_NOME ?> · <?= APP_VENDOR ?> · <?= APP_URL ?> · Documento gerado em <?= date('d/m/Y H:i') ?> · Termo #<?= $termo['id'] ?>
  <?php if ($cfg['rodape']): ?> · <?= h($cfg['rodape']) ?><?php endif; ?>
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

  // Esconde barra de ações temporariamente
  const barra = document.querySelector('.no-print');
  barra.style.display = 'none';

  try {
    const canvas = await html2canvas(document.body, {
      scale: 2,
      useCORS: true,
      backgroundColor: '#ffffff',
      windowWidth: 794  // A4 width em px a 96dpi
    });

    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

    const pageW  = pdf.internal.pageSize.getWidth();
    const pageH  = pdf.internal.pageSize.getHeight();
    const imgW   = pageW;
    const imgH   = (canvas.height * pageW) / canvas.width;

    let posY = 0;
    let remaining = imgH;

    // Quebra em páginas se o conteúdo ultrapassar uma A4
    const imgData = canvas.toDataURL('image/jpeg', 0.95);
    while (remaining > 0) {
      if (posY > 0) pdf.addPage();
      pdf.addImage(imgData, 'JPEG', 0, -posY, imgW, imgH, '', 'FAST');
      posY      += pageH;
      remaining -= pageH;
    }

    const nome = <?= json_encode("Termo_{$termo['id']}_{$termo['responsavel_nome']}") ?>;
    pdf.save(nome.replace(/\s+/g, '_') + '.pdf');
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
