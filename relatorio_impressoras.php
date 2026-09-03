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

// Colapsa "Modelo X Modelo X" (duplicado no SNMP) para "Modelo X"
function modeloLimpo(?string $s): string {
    $s = trim((string)$s);
    if ($s === '') return '';
    $meio = intdiv(strlen($s), 2);
    if (strlen($s) % 2 === 1 && $s[$meio] === ' '
        && substr($s, 0, $meio) === substr($s, $meio + 1)) {
        return substr($s, 0, $meio);
    }
    return $s;
}

// ── Exportação CSV — antes de qualquer saída HTML ────────────────────────────
if (($_GET['fmt'] ?? '') === 'csv') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="impressoras_' . $ano . sprintf('%02d', $mes) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Impressora','Setor','Modelo','IP','Páginas/mês','Mês anterior','Variação %','Total acumulado','Toner preto %','Toner ciano %','Toner magenta %','Toner amarelo %','Última coleta'], ';');
    foreach ($impressoras as $r) {
        $pag = $r['paginas_mes'] !== null ? (int)$r['paginas_mes'] : '';
        $ant = $r['paginas_ant'] !== null ? (int)$r['paginas_ant'] : '';
        $var = ($pag !== '' && $ant !== '' && $ant > 0)
            ? round((($pag - $ant) / $ant) * 100, 1) . '%' : '';
        fputcsv($out, [
            $r['nome'], $r['setor'], modeloLimpo($r['marca_modelo']), $r['ip'] ?? '',
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

layoutHeader('Relatório de Impressoras', 'impressoras');

$sem_dados = count(array_filter($impressoras, fn($r) => $r['paginas_mes'] === null));
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
.rel-kpis{display:flex;border-top:1px solid var(--border);font-size:12px}
.rel-kpis > div{flex:1 1 0;text-align:center;padding:.55rem .5rem}
.rel-kpis > div + div{border-left:1px solid var(--border)}
.rel-kpis .k-num{font-size:19px;font-weight:700;line-height:1.1}
.rel-kpis .k-lbl{font-size:11px;color:var(--tx-muted)}
</style>

<?php breadcrumb([['label'=>'Impressoras','href'=>'impressoras.php'],['label'=>'Relatório de Páginas']]); ?>
<div class="page-header">
  <h1 class="page-title">
    <i class="bi bi-printer-fill me-2 text-primary"></i>
    Relatório de Impressoras — <?= $meses_nomes[$mes] ?> <?= $ano ?>
  </h1>
  <div class="d-flex gap-2 no-print">
    <a id="btn-exportar-csv" href="relatorio_impressoras.php?ano=<?= $ano ?>&mes=<?= $mes ?>&setor=<?= urlencode($filtro_setor) ?>&fmt=csv" class="btn btn-outline-success btn-sm">
      <i class="bi bi-file-earmark-spreadsheet me-1"></i>Exportar CSV
    </a>
    <button id="btn-imprimir" onclick="window.print()" class="btn btn-primary btn-sm">
      <i class="bi bi-printer me-1"></i>Imprimir / PDF
    </button>
  </div>
</div>

<!-- Filtro + resumo numa faixa só, logo acima da tabela -->
<div id="filtros-bar" class="card mb-3">
  <div class="card-body py-2 no-print">
    <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
      <label class="fw-semibold mb-0 me-1" style="font-size:13px"><i class="bi bi-funnel me-1"></i>Período</label>
      <select name="setor" class="form-select form-select-sm" style="width:170px">
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
      <button class="btn btn-primary btn-sm" type="submit">Aplicar</button>
    </form>
  </div>
  <div class="rel-kpis">
    <div>
      <div class="k-num" style="color:var(--brand)"><?= count($impressoras) ?></div>
      <div class="k-lbl">Impressoras ativas</div>
    </div>
    <div>
      <div class="k-num" style="color:var(--brand)"><?= number_format($totais['pag_mes'], 0, ',', '.') ?></div>
      <div class="k-lbl">Páginas em <?= $meses_nomes[$mes] ?><?php if ($totais['pag_ant'] > 0): ?> · <?= variacaoBadge($totais['pag_mes'], $totais['pag_ant']) ?><?php endif; ?></div>
    </div>
    <div>
      <div class="k-num" style="color:<?= $sem_dados > 0 ? '#e63946' : '#22c55e' ?>"><?= $sem_dados ?></div>
      <div class="k-lbl">Sem dados SNMP</div>
    </div>
    <div>
      <div class="k-num" style="color:<?= count($alertas_toner) > 0 ? '#e63946' : '#22c55e' ?>"><?= count($alertas_toner) ?></div>
      <div class="k-lbl">Toner crítico</div>
    </div>
  </div>
</div>

<?php if ($alertas_toner): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 py-2 px-3 mb-3 no-print" style="font-size:13px">
  <i class="bi bi-exclamation-triangle-fill"></i>
  <span><strong><?= count($alertas_toner) ?> impressora(s)</strong> com toner crítico (≤15%) — destacadas na tabela abaixo.</span>
</div>
<?php endif; ?>

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
            $toner_baixo = false;
            foreach (['toner_preto','toner_ciano','toner_magenta','toner_amarelo'] as $tc) {
                if ($r[$tc] !== null && (int)$r[$tc] <= 15) { $toner_baixo = true; break; }
            }
            $modelo = modeloLimpo($r['marca_modelo']);
        ?>
        <tr class="<?= $sem_snmp ? 'table-secondary' : ($toner_baixo ? 'table-warning' : '') ?>">
          <td>
            <div class="fw-semibold"><?= h($r['nome']) ?></div>
            <?php if ($r['ip']): ?>
              <div style="font-size:11px;color:#6b7280;font-family:monospace"><?= h($r['ip']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= h($r['setor']) ?: '<span class="text-muted">—</span>' ?></td>
          <td style="font-size:12px"><?= $modelo !== '' && $modelo !== $r['nome'] ? h($modelo) : '<span class="text-muted">—</span>' ?></td>
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

<?php layoutFooter(); ?>
