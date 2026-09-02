<?php
require 'db.php';
requireGestora();
require 'layout.php';

$pdo = db();
$mes = (int)($_GET['mes'] ?? date('m'));
$ano = (int)($_GET['ano'] ?? date('Y'));
$params = ['mes'=>$mes,'ano'=>$ano];
$meses_labels = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];

// KPIs resumidos de cada domínio (leves — sem gráficos, só números)
$chamados = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status='Concluído') AS concluidos,
    SUM(status IN ('Aberto','Pendente','Em Andamento')) AS abertos
    FROM chamados WHERE MONTH(criado_em)=:mes AND YEAR(criado_em)=:ano AND deleted_at IS NULL");
$chamados->execute($params);
$chamados = $chamados->fetch();

$suprimentos = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status='Entregue') AS entregues,
    SUM(status IN ('Pendente','Aprovado')) AS pendentes
    FROM pedidos_suprimentos WHERE MONTH(criado_em)=:mes AND YEAR(criado_em)=:ano");
$suprimentos->execute($params);
$suprimentos = $suprimentos->fetch();

$manutencoes = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status='Concluída') AS concluidas,
    SUM(status IN ('Pendente','Em Realização')) AS pendentes
    FROM manutencoes_impressoras WHERE MONTH(data_manutencao)=:mes AND YEAR(data_manutencao)=:ano");
$manutencoes->execute($params);
$manutencoes = $manutencoes->fetch();

$contratos = $pdo->query("SELECT COUNT(*) AS total, SUM(status='Ativo') AS ativos,
    SUM(data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)) AS vencendo
    FROM contratos")->fetch();

$inventario = $pdo->query("SELECT COUNT(*) AS total, SUM(status='Em Uso') AS em_uso,
    SUM(status='Disponível') AS disponivel
    FROM inventario WHERE status != 'Descartado'")->fetch();

$impressoras_ativas = (int)$pdo->query("SELECT COUNT(*) FROM impressoras WHERE status='Ativa'")->fetchColumn();

layoutHeader('Relatórios', 'relatorios');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-bar-chart-fill me-2 text-primary"></i>Relatórios</h1>
  <div class="d-flex gap-3 align-items-center">
    <form method="get" class="d-flex gap-2 align-items-center">
      <select name="mes" class="form-select form-select-sm" style="width:130px">
        <?php for($m=1;$m<=12;$m++): ?>
          <option value="<?= $m ?>" <?= $m===$mes?'selected':'' ?>><?= $meses_labels[$m-1] ?></option>
        <?php endfor; ?>
      </select>
      <select name="ano" class="form-select form-select-sm" style="width:85px">
        <?php for($a=2024;$a<=2027;$a++): ?>
          <option value="<?= $a ?>" <?= $a===$ano?'selected':'' ?>><?= $a ?></option>
        <?php endfor; ?>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Atualizar</button>
    </form>
    <a href="exportar_relatorio.php?mes=<?= $mes ?>&ano=<?= $ano ?>&fmt=xlsx" class="btn btn-outline-success btn-sm fw-semibold" title="Uma única planilha com todos os módulos">
      <i class="bi bi-file-earmark-spreadsheet me-1"></i>Baixar tudo (XLSX)
    </a>
  </div>
</div>

<p class="text-muted mb-4" style="font-size:13px">Visão resumida de <?= $meses_labels[$mes-1] ?>/<?= $ano ?> — clique em cada card para o relatório completo com gráficos e exportação própria.</p>

<div class="row g-3 mb-3">

  <!-- Chamados -->
  <div class="col-md-6 col-lg-4">
    <a href="relatorio_chamados.php?mes=<?= $mes ?>&ano=<?= $ano ?>" class="text-decoration-none">
      <div class="card h-100 hub-card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="hub-ico" style="background:#dbeafe;color:#1d4ed8"><i class="bi bi-ticket-detailed-fill"></i></div>
            <i class="bi bi-arrow-up-right hub-arrow"></i>
          </div>
          <div class="fw-bold" style="font-size:15px;color:var(--tx-primary)">Chamados</div>
          <div class="d-flex gap-3 mt-2">
            <div><div class="hub-num"><?= (int)$chamados['total'] ?></div><div class="hub-lbl">Total</div></div>
            <div><div class="hub-num" style="color:#22c55e"><?= (int)$chamados['concluidos'] ?></div><div class="hub-lbl">Concluídos</div></div>
            <div><div class="hub-num" style="color:#ef4444"><?= (int)$chamados['abertos'] ?></div><div class="hub-lbl">Abertos</div></div>
          </div>
        </div>
      </div>
    </a>
  </div>

  <!-- Suprimentos -->
  <div class="col-md-6 col-lg-4">
    <a href="relatorio_suprimentos.php?mes=<?= $mes ?>&ano=<?= $ano ?>" class="text-decoration-none">
      <div class="card h-100 hub-card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="hub-ico" style="background:#fef3c7;color:#92400e"><i class="bi bi-box-seam-fill"></i></div>
            <i class="bi bi-arrow-up-right hub-arrow"></i>
          </div>
          <div class="fw-bold" style="font-size:15px;color:var(--tx-primary)">Suprimentos</div>
          <div class="d-flex gap-3 mt-2">
            <div><div class="hub-num"><?= (int)$suprimentos['total'] ?></div><div class="hub-lbl">Total</div></div>
            <div><div class="hub-num" style="color:#22c55e"><?= (int)$suprimentos['entregues'] ?></div><div class="hub-lbl">Entregues</div></div>
            <div><div class="hub-num" style="color:#f59e0b"><?= (int)$suprimentos['pendentes'] ?></div><div class="hub-lbl">Pendentes</div></div>
          </div>
        </div>
      </div>
    </a>
  </div>

  <!-- Manutenções -->
  <div class="col-md-6 col-lg-4">
    <a href="relatorio_manutencoes.php?mes=<?= $mes ?>&ano=<?= $ano ?>" class="text-decoration-none">
      <div class="card h-100 hub-card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="hub-ico" style="background:#fee2e2;color:#991b1b"><i class="bi bi-wrench-adjustable-fill"></i></div>
            <i class="bi bi-arrow-up-right hub-arrow"></i>
          </div>
          <div class="fw-bold" style="font-size:15px;color:var(--tx-primary)">Manutenções</div>
          <div class="d-flex gap-3 mt-2">
            <div><div class="hub-num"><?= (int)$manutencoes['total'] ?></div><div class="hub-lbl">Total</div></div>
            <div><div class="hub-num" style="color:#22c55e"><?= (int)$manutencoes['concluidas'] ?></div><div class="hub-lbl">Concluídas</div></div>
            <div><div class="hub-num" style="color:#f59e0b"><?= (int)$manutencoes['pendentes'] ?></div><div class="hub-lbl">Pendentes</div></div>
          </div>
        </div>
      </div>
    </a>
  </div>

  <!-- Contratos -->
  <div class="col-md-6 col-lg-4">
    <a href="relatorio_contratos.php" class="text-decoration-none">
      <div class="card h-100 hub-card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="hub-ico" style="background:#dcfce7;color:#166534"><i class="bi bi-file-earmark-check-fill"></i></div>
            <i class="bi bi-arrow-up-right hub-arrow"></i>
          </div>
          <div class="fw-bold" style="font-size:15px;color:var(--tx-primary)">Contratos & Licenças</div>
          <div class="d-flex gap-3 mt-2">
            <div><div class="hub-num"><?= (int)$contratos['total'] ?></div><div class="hub-lbl">Total</div></div>
            <div><div class="hub-num" style="color:#22c55e"><?= (int)$contratos['ativos'] ?></div><div class="hub-lbl">Ativos</div></div>
            <div><div class="hub-num" style="color:#f59e0b"><?= (int)$contratos['vencendo'] ?></div><div class="hub-lbl">Vencendo 60d</div></div>
          </div>
        </div>
      </div>
    </a>
  </div>

  <!-- Inventário -->
  <div class="col-md-6 col-lg-4">
    <a href="relatorio_inventario.php" class="text-decoration-none">
      <div class="card h-100 hub-card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="hub-ico" style="background:#e0e7ff;color:#4338ca"><i class="bi bi-pc-display"></i></div>
            <i class="bi bi-arrow-up-right hub-arrow"></i>
          </div>
          <div class="fw-bold" style="font-size:15px;color:var(--tx-primary)">Inventário</div>
          <div class="d-flex gap-3 mt-2">
            <div><div class="hub-num"><?= (int)$inventario['total'] ?></div><div class="hub-lbl">Ativos</div></div>
            <div><div class="hub-num" style="color:#22c55e"><?= (int)$inventario['em_uso'] ?></div><div class="hub-lbl">Em Uso</div></div>
            <div><div class="hub-num" style="color:#0ea5e9"><?= (int)$inventario['disponivel'] ?></div><div class="hub-lbl">Disponível</div></div>
          </div>
        </div>
      </div>
    </a>
  </div>

  <!-- Impressoras (já existente) -->
  <div class="col-md-6 col-lg-4">
    <a href="relatorio_impressoras.php?mes=<?= $mes ?>&ano=<?= $ano ?>" class="text-decoration-none">
      <div class="card h-100 hub-card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="hub-ico" style="background:#cffafe;color:#155e75"><i class="bi bi-printer-fill"></i></div>
            <i class="bi bi-arrow-up-right hub-arrow"></i>
          </div>
          <div class="fw-bold" style="font-size:15px;color:var(--tx-primary)">Impressoras</div>
          <div class="d-flex gap-3 mt-2">
            <div><div class="hub-num"><?= $impressoras_ativas ?></div><div class="hub-lbl">Ativas</div></div>
            <div class="text-muted" style="font-size:11px;align-self:center">Páginas, toner, custo por setor</div>
          </div>
        </div>
      </div>
    </a>
  </div>

</div>

<style>
.hub-card{transition:.15s;border:1px solid var(--border)}
.hub-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);transform:translateY(-2px);border-color:var(--brand)}
.hub-ico{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px}
.hub-arrow{color:var(--tx-faint);font-size:16px}
.hub-num{font-size:20px;font-weight:700;color:var(--tx-primary);line-height:1}
.hub-lbl{font-size:10.5px;color:var(--tx-muted);margin-top:2px}
</style>

<?php layoutFooter(); ?>
