<?php
require 'db.php';
requireLogin();
require 'layout.php';
require_once 'impressoras_helpers.php';

$pdo = db();

$ano_atual = (int)date('Y');
$mes_atual = (int)date('m');

$ano = (int)($_GET['ano'] ?? $ano_atual);
$mes = (int)($_GET['mes'] ?? $mes_atual);
$mes = max(1, min(12, $mes));
$ano = max(2020, min($ano_atual + 1, $ano));
$filtro_setor = trim($_GET['setor'] ?? '');

$meses_nomes = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho',
                'Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

// Mês anterior para calcular variação
$mes_ant = $mes === 1 ? 12 : $mes - 1;
$ano_ant  = $mes === 1 ? $ano - 1 : $ano;

$tem_snap = true; // schema garantido pelas migrations

$impressoras = [];
$totais      = ['pag_mes' => 0, 'pag_ant' => 0];

if ($tem_snap) {
    // Valores "atuais" vêm da tabela materializada (P3-2); só o cálculo de
    // páginas por mês precisa varrer o histórico (subquery com índice composto).
    $stmt = $pdo->prepare("
        SELECT
            i.id, i.nome, i.marca_modelo, i.setor, i.ip, i.status,
            (SELECT MAX(s.paginas_total) - MIN(s.paginas_total)
             FROM impressoras_snapshot s
             WHERE s.impressora_id = i.id
               AND YEAR(s.coletado_em) = :ano AND MONTH(s.coletado_em) = :mes
               AND s.paginas_total IS NOT NULL
            ) AS paginas_mes,
            (SELECT MAX(s.paginas_total) - MIN(s.paginas_total)
             FROM impressoras_snapshot s
             WHERE s.impressora_id = i.id
               AND YEAR(s.coletado_em) = :ano_ant AND MONTH(s.coletado_em) = :mes_ant
               AND s.paginas_total IS NOT NULL
            ) AS paginas_ant,
            u.paginas_total     AS paginas_total,
            u.toner_preto_pct   AS toner_preto,
            u.toner_ciano_pct   AS toner_ciano,
            u.toner_magenta_pct AS toner_magenta,
            u.toner_amarelo_pct AS toner_amarelo,
            u.coletado_em       AS ultima_coleta
        FROM impressoras i
        LEFT JOIN impressoras_ultimo_snapshot u ON u.impressora_id = i.id
        WHERE i.status = 'Ativa'
          " . ($filtro_setor ? "AND i.setor = :setor" : "") . "
        ORDER BY paginas_mes DESC, i.setor, i.nome
    ");
    $params_sql = ['ano' => $ano, 'mes' => $mes, 'ano_ant' => $ano_ant, 'mes_ant' => $mes_ant];
    if ($filtro_setor) $params_sql['setor'] = $filtro_setor;
    $stmt->execute($params_sql);
    $impressoras = $stmt->fetchAll();

    foreach ($impressoras as $r) {
        $totais['pag_mes'] += (int)($r['paginas_mes'] ?? 0);
        $totais['pag_ant'] += (int)($r['paginas_ant'] ?? 0);
    }
} else {
    // Sem snapshots: mostra lista de impressoras sem dados de páginas
    $impressoras = $pdo->query(
        "SELECT id, nome, marca_modelo, setor, ip, status,
                NULL AS paginas_mes, NULL AS paginas_ant,
                NULL AS paginas_total, NULL AS toner_preto,
                NULL AS toner_ciano, NULL AS toner_magenta, NULL AS toner_amarelo,
                NULL AS ultima_coleta
         FROM impressoras WHERE status = 'Ativa' ORDER BY setor, nome"
    )->fetchAll();
}

// Alertas: toner crítico
$alertas_toner = array_filter($impressoras, function($r) {
    foreach (['toner_preto','toner_ciano','toner_magenta','toner_amarelo'] as $col) {
        if ($r[$col] !== null && (int)$r[$col] <= 15) return true;
    }
    return false;
});

layoutHeader('Relatório de Impressoras', 'impressoras');
?>

<style>
@media print {
  .sidebar, .main-wrap > .page-header .btn,
  #filtros-bar, #btn-imprimir, #btn-exportar-csv,
  .no-print { display: none !important; }
  .main-wrap { margin-left: 0 !important; padding: 0 !important; }
  body { background: #fff !important; font-size: 11px; }
  .card { border: 1px solid #dee2e6 !important; box-shadow: none !important; }
  .card-header { background: #f8f9fa !important; color: #000 !important; }
  table { font-size: 10px; }
  .badge { border: 1px solid #aaa; }
  h1.page-title { font-size: 16px; }
}
</style>

<?php breadcrumb([['label'=>'Impressoras','href'=>'impressoras.php'],['label'=>'Relatório de Páginas']]); ?>
<div class="page-header">
  <h1 class="page-title">
    <i class="bi bi-printer-fill me-2 text-primary"></i>
    Relatório de Impressoras — <?= $meses_nomes[$mes] ?> <?= $ano ?>
  </h1>
  <div class="d-flex gap-2 no-print">
    <a id="btn-exportar-csv" href="relatorio_impressoras.php?ano=<?= $ano ?>&mes=<?= $mes ?>&fmt=csv" class="btn btn-outline-success btn-sm">
      <i class="bi bi-file-earmark-spreadsheet me-1"></i>Exportar CSV
    </a>
    <button id="btn-imprimir" onclick="window.print()" class="btn btn-primary btn-sm">
      <i class="bi bi-printer me-1"></i>Imprimir / PDF
    </button>
  </div>
</div>

<!-- Filtro de período -->
<div id="filtros-bar" class="card mb-4 no-print">
  <div class="card-body py-2">
    <form method="GET" class="d-flex align-items-center gap-3 flex-wrap">
      <label class="fw-semibold mb-0" style="font-size:13px">Período:</label>
      <select name="setor" class="form-select form-select-sm" style="width:160px">
        <option value="">Todos os Setores</option>
        <?php foreach ($SETORES as $s): ?>
          <option value="<?= h($s) ?>" <?= $filtro_setor === $s ? 'selected' : '' ?>><?= h($s) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="mes" class="form-select form-select-sm" style="width:130px">
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $m === $mes ? 'selected' : '' ?>><?= $meses_nomes[$m] ?></option>
        <?php endfor; ?>
      </select>
      <select name="ano" class="form-select form-select-sm" style="width:90px">
        <?php for ($a = $ano_atual; $a >= 2024; $a--): ?>
          <option value="<?= $a ?>" <?= $a === $ano ? 'selected' : '' ?>><?= $a ?></option>
        <?php endfor; ?>
      </select>
      <button class="btn btn-primary btn-sm" type="submit">
        <i class="bi bi-funnel me-1"></i>Filtrar
      </button>
    </form>
  </div>
</div>

<?php if ($alertas_toner): ?>
<!-- Alertas de toner crítico -->
<div class="alert alert-danger d-flex align-items-start gap-3 mb-4 no-print">
  <i class="bi bi-exclamation-triangle-fill fs-4 mt-1"></i>
  <div>
    <strong>Toner crítico (≤15%) em <?= count($alertas_toner) ?> impressora(s):</strong>
    <div class="mt-1 d-flex flex-wrap gap-2">
      <?php foreach ($alertas_toner as $a): ?>
        <?php
          $cores_alerta = [];
          $cores_map_rel = ['⬛'=>$a['toner_preto'],'🔵'=>$a['toner_ciano'],'🔴'=>$a['toner_magenta'],'🟡'=>$a['toner_amarelo']];
          foreach ($cores_map_rel as $emoji => $pct) {
              if ($pct !== null && (int)$pct <= 15) $cores_alerta[] = "{$emoji}{$pct}%";
          }
        ?>
        <a href="impressora.php?id=<?= $a['id'] ?>" class="badge bg-danger text-decoration-none" style="font-size:12px">
          <?= h($a['nome']) ?> — <?= implode(' ', $cores_alerta) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Cards de resumo -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card text-center py-3">
      <div class="fw-bold" style="font-size:26px;color:var(--brand)"><?= count($impressoras) ?></div>
      <div class="text-muted" style="font-size:12px">Impressoras ativas</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-3">
      <div class="fw-bold" style="font-size:26px;color:var(--brand)"><?= number_format($totais['pag_mes'], 0, ',', '.') ?></div>
      <div class="text-muted" style="font-size:12px">Páginas em <?= $meses_nomes[$mes] ?></div>
      <?php if ($totais['pag_ant'] > 0): ?>
        <div style="font-size:11px" class="mt-1">
          <?= variacaoBadge($totais['pag_mes'], $totais['pag_ant']) ?>
          vs <?= $meses_nomes[$mes_ant] ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-3">
      <?php $sem_dados = count(array_filter($impressoras, fn($r) => $r['paginas_mes'] === null)); ?>
      <div class="fw-bold" style="font-size:26px;color:<?= $sem_dados > 0 ? '#e63946' : '#22c55e' ?>"><?= $sem_dados ?></div>
      <div class="text-muted" style="font-size:12px">Sem dados SNMP</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-3">
      <div class="fw-bold" style="font-size:26px;color:<?= count($alertas_toner) > 0 ? '#e63946' : '#22c55e' ?>"><?= count($alertas_toner) ?></div>
      <div class="text-muted" style="font-size:12px">Toner crítico</div>
    </div>
  </div>
</div>

<!-- Tabela principal -->
<div class="card">
  <div class="card-header fw-bold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-table me-2 text-primary"></i>Páginas por Impressora — <?= $meses_nomes[$mes] ?>/<?= $ano ?></span>
    <small class="text-muted fw-normal">Comparado com <?= $meses_nomes[$mes_ant] ?>/<?= $ano_ant ?></small>
  </div>
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0" style="font-size:13px">
      <thead class="table-light">
        <tr>
          <th style="min-width:160px">Impressora</th>
          <th>Setor</th>
          <th>Modelo</th>
          <th class="text-end">Páginas/mês</th>
          <th class="text-end"><?= $meses_nomes[$mes_ant] ?></th>
          <th class="text-center">Variação</th>
          <th class="text-end">Total acum.</th>
          <th class="text-center">⬛ Preto</th>
          <th class="text-center">🔵 Ciano</th>
          <th class="text-center">🔴 Magenta</th>
          <th class="text-center">🟡 Amarelo</th>
          <th class="text-center">Última coleta</th>
          <th class="text-center no-print">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($impressoras as $r):
            $pag_mes = $r['paginas_mes'] !== null ? (int)$r['paginas_mes'] : null;
            $pag_ant = $r['paginas_ant'] !== null ? (int)$r['paginas_ant'] : null;
            $sem_snmp = $pag_mes === null;
        ?>
        <tr class="<?= $sem_snmp ? 'table-secondary' : '' ?>">
          <td>
            <div class="fw-semibold"><?= h($r['nome']) ?></div>
            <?php if ($r['ip']): ?>
              <div style="font-size:11px;color:#6b7280;font-family:monospace"><?= h($r['ip']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= h($r['setor']) ?: '<span class="text-muted">—</span>' ?></td>
          <td style="font-size:12px"><?= h($r['marca_modelo']) ?></td>
          <td class="text-end fw-semibold">
            <?= $pag_mes !== null ? number_format($pag_mes, 0, ',', '.') : '<span class="text-muted">—</span>' ?>
          </td>
          <td class="text-end text-muted">
            <?= $pag_ant !== null ? number_format($pag_ant, 0, ',', '.') : '<span class="text-muted">—</span>' ?>
          </td>
          <td class="text-center"><?= variacaoBadge($pag_mes, $pag_ant) ?></td>
          <td class="text-end text-muted" style="font-size:12px">
            <?= $r['paginas_total'] !== null ? number_format((int)$r['paginas_total'], 0, ',', '.') : '—' ?>
          </td>
          <td class="text-center"><?= tonerBadge($r['toner_preto'] !== null ? (int)$r['toner_preto'] : null) ?></td>
          <td class="text-center"><?= $r['toner_ciano'] !== null ? tonerBadge((int)$r['toner_ciano']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-center"><?= $r['toner_magenta'] !== null ? tonerBadge((int)$r['toner_magenta']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-center"><?= $r['toner_amarelo'] !== null ? tonerBadge((int)$r['toner_amarelo']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-center" style="font-size:11px;color:#6b7280;white-space:nowrap">
            <?= $r['ultima_coleta'] ? date('d/m H:i', strtotime($r['ultima_coleta'])) : '<span class="text-muted">—</span>' ?>
          </td>
          <td class="text-center no-print">
            <a href="impressora.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-secondary" title="Ver detalhes">
              <i class="bi bi-eye"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>

        <!-- Linha de totais -->
        <tr class="table-light fw-bold">
          <td colspan="3">TOTAL</td>
          <td class="text-end"><?= number_format($totais['pag_mes'], 0, ',', '.') ?></td>
          <td class="text-end"><?= number_format($totais['pag_ant'], 0, ',', '.') ?></td>
          <td class="text-center"><?= variacaoBadge($totais['pag_mes'], $totais['pag_ant']) ?></td>
          <td colspan="7"></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Rodapé de impressão -->
<div class="mt-4" style="font-size:11px;color:#6b7280">
  Relatório gerado em <?= date('d/m/Y H:i') ?> · HelpTI — <?= CLINICA_NOME ?>
</div>

<?php
// ── Exportação CSV ────────────────────────────────────────────────────────────
if (($_GET['fmt'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="impressoras_' . $ano . sprintf('%02d', $mes) . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Impressora','Setor','Modelo','IP','Páginas/mês','Mês anterior','Variação %','Total acumulado','Toner preto %','Toner ciano %','Toner magenta %','Toner amarelo %','Última coleta'], ';');
    foreach ($impressoras as $r) {
        $pag = $r['paginas_mes'] !== null ? (int)$r['paginas_mes'] : '';
        $ant = $r['paginas_ant'] !== null ? (int)$r['paginas_ant'] : '';
        $var = ($pag !== '' && $ant !== '' && $ant > 0)
            ? round((($pag - $ant) / $ant) * 100, 1) . '%' : '';
        fputcsv($out, [
            $r['nome'], $r['setor'], $r['marca_modelo'], $r['ip'] ?? '',
            $pag, $ant, $var,
            $r['paginas_total'] !== null ? (int)$r['paginas_total'] : '',
            $r['toner_preto']   !== null ? (int)$r['toner_preto']   . '%' : '',
            $r['toner_ciano']   !== null ? (int)$r['toner_ciano']   . '%' : '',
            $r['toner_magenta'] !== null ? (int)$r['toner_magenta'] . '%' : '',
            $r['toner_amarelo'] !== null ? (int)$r['toner_amarelo'] . '%' : '',
            $r['ultima_coleta'] ? date('d/m/Y H:i', strtotime($r['ultima_coleta'])) : '',
        ], ';');
    }
    fputcsv($out, ['TOTAL','','','',
        $totais['pag_mes'] ?: '', $totais['pag_ant'] ?: '', '', '', '', '', '', '', ''], ';');
    fclose($out);
    exit;
}
?>

<?php layoutFooter(); ?>
