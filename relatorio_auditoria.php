<?php
require 'db.php';
requireGestora();
require 'layout.php';

$pdo      = db();
$ciclo_id = (int)($_GET['ciclo_id'] ?? 0);

// Lista todos os ciclos para o seletor
$ciclos_lista = $pdo->query("SELECT id, nome, status, taxa_acuracia, criado_em FROM ciclos_auditoria ORDER BY criado_em DESC")->fetchAll();

$ciclo = $itens = $divs = null;
if ($ciclo_id) {
    $st = $pdo->prepare("SELECT ca.*, u.nome AS resp_nome FROM ciclos_auditoria ca
        LEFT JOIN usuarios u ON u.id = ca.responsavel_id WHERE ca.id=?");
    $st->execute([$ciclo_id]);
    $ciclo = $st->fetch();

    if ($ciclo) {
        $st2 = $pdo->prepare("
            SELECT ai.*, i.tipo, i.marca, i.modelo, i.numero_serie, i.patrimonio,
                   i.setor AS setor_atual, u.nome AS tecnico_nome
            FROM auditoria_itens ai
            JOIN inventario i ON i.id = ai.inventario_id
            LEFT JOIN usuarios u ON u.id = ai.tecnico_id
            WHERE ai.ciclo_id = ?
            ORDER BY ai.divergencia DESC, ai.verificado ASC, i.setor, i.tipo
        ");
        $st2->execute([$ciclo_id]);
        $itens = $st2->fetchAll();

        $st3 = $pdo->prepare("SELECT
            SUM(divergencia=1) AS total_divg,
            SUM(divergencia=1 AND setor_encontrado != setor_esperado AND setor_encontrado IS NOT NULL) AS setor_errado,
            SUM(divergencia=1 AND responsavel_encontrado != responsavel_esperado AND responsavel_encontrado IS NOT NULL) AS resp_trocado,
            SUM(divergencia=1 AND status_encontrado != status_esperado AND status_encontrado IS NOT NULL) AS status_diferente,
            SUM(verificado=0) AS nao_localizado
            FROM auditoria_itens WHERE ciclo_id=?");
        $st3->execute([$ciclo_id]);
        $divs = $st3->fetch();

        // Exportação XLSX
        $fmt = $_GET['fmt'] ?? '';
        if ($fmt === 'xlsx') {
            require_once 'SimpleXLSXGen.php';
            $cab = ['Patrimônio','Tipo','Marca/Modelo','S/N','Setor Esperado','Setor Encontrado',
                    'Resp. Esperado','Resp. Encontrado','Status Esperado','Status Encontrado',
                    'Verificado','Divergência','Técnico','Data Verificação','Observação'];
            $rows = [$cab];
            foreach ($itens as $r) {
                $rows[] = [
                    $r['patrimonio'] ?: '—',
                    $r['tipo'],
                    $r['marca'].' '.$r['modelo'],
                    $r['numero_serie'] ?: '—',
                    $r['setor_esperado'] ?: '—',
                    $r['setor_encontrado'] ?: '—',
                    $r['responsavel_esperado'] ?: '—',
                    $r['responsavel_encontrado'] ?: '—',
                    $r['status_esperado'],
                    $r['status_encontrado'] ?: '—',
                    $r['verificado'] ? 'Sim' : 'Não',
                    $r['divergencia'] ? 'Sim' : 'Não',
                    $r['tecnico_nome'] ?: '—',
                    $r['verificado_em'] ? date('d/m/Y H:i', strtotime($r['verificado_em'])) : '—',
                    $r['observacao'] ?: '',
                ];
            }
            SimpleXLSXGen::fromArray($rows)->downloadAs("Auditoria_{$ciclo_id}_".date('Y-m-d').".xlsx");
            exit;
        }
    }
}

layoutHeader('Relatório de Auditoria', 'relatorios');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-bar-chart-line-fill text-primary me-2"></i>Relatório de Auditoria ITAM</h1>
</div>

<!-- Seletor de ciclo -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="d-flex gap-2 align-items-center flex-wrap">
      <label class="fw-semibold small mb-0" style="white-space:nowrap">Ciclo:</label>
      <select name="ciclo_id" class="form-select form-select-sm" style="max-width:340px" onchange="this.form.submit()">
        <option value="">— Selecione um ciclo —</option>
        <?php foreach ($ciclos_lista as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $c['id'] == $ciclo_id ? 'selected' : '' ?>>
          <?= h($c['nome']) ?> (<?= h($c['status']) ?>)
          <?= $c['taxa_acuracia'] !== null ? ' — '.$c['taxa_acuracia'].'%' : '' ?>
        </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
</div>

<?php if (!$ciclo_id || !$ciclo): ?>
<div class="card"><div class="card-body text-center py-5 text-muted">Selecione um ciclo para ver o relatório.</div></div>
<?php else: ?>

<!-- Cards de resumo -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-num <?= $ciclo['taxa_acuracia'] >= 90 ? 'text-success' : ($ciclo['taxa_acuracia'] >= 70 ? 'text-warning' : 'text-danger') ?>">
        <?= $ciclo['taxa_acuracia'] !== null ? $ciclo['taxa_acuracia'].'%' : '—' ?>
      </div>
      <div class="stat-label">Acurácia final</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-num"><?= $ciclo['total_verificado'] ?>/<?= $ciclo['total_esperado'] ?></div>
      <div class="stat-label">Ativos verificados</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-num text-danger"><?= (int)($divs['total_divg'] ?? 0) ?></div>
      <div class="stat-label">Divergências</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-num text-warning"><?= (int)($divs['nao_localizado'] ?? 0) ?></div>
      <div class="stat-label">Não localizados</div>
    </div>
  </div>
</div>

<!-- Detalhes das divergências -->
<?php if ((int)($divs['total_divg'] ?? 0) > 0): ?>
<div class="card mb-3">
  <div class="card-header card-header-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Tipos de divergência</div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <div class="border rounded p-3 text-center">
          <div class="fs-4 fw-bold text-danger"><?= (int)($divs['setor_errado'] ?? 0) ?></div>
          <div class="small text-muted">Setor incorreto</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="border rounded p-3 text-center">
          <div class="fs-4 fw-bold text-warning"><?= (int)($divs['resp_trocado'] ?? 0) ?></div>
          <div class="small text-muted">Responsável trocado</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="border rounded p-3 text-center">
          <div class="fs-4 fw-bold text-info"><?= (int)($divs['status_diferente'] ?? 0) ?></div>
          <div class="small text-muted">Status diferente</div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Cabeçalho do ciclo + exportar -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span>
      <i class="bi bi-table me-2 text-primary"></i>
      <?= h($ciclo['nome']) ?>
      <span class="badge <?= $ciclo['status'] === 'Concluído' ? 'badge-concluido' : 'badge-andamento' ?> ms-2"><?= h($ciclo['status']) ?></span>
    </span>
    <div class="d-flex gap-2 align-items-center">
      <small class="text-muted">Responsável: <strong><?= h($ciclo['resp_nome'] ?? '—') ?></strong></small>
      <a href="?ciclo_id=<?= $ciclo_id ?>&fmt=xlsx" class="btn btn-sm btn-outline-success">
        <i class="bi bi-file-earmark-excel me-1"></i>Exportar XLSX
      </a>
    </div>
  </div>

  <!-- Filtros de tabela -->
  <div class="card-body py-2 border-bottom">
    <div class="d-flex gap-2 flex-wrap">
      <button class="btn btn-xs btn-outline-secondary active" onclick="filtrar('')" id="f-todos">Todos (<?= count($itens) ?>)</button>
      <button class="btn btn-xs btn-outline-danger"    onclick="filtrar('divg')"  id="f-divg">Divergências (<?= (int)($divs['total_divg']??0) ?>)</button>
      <button class="btn btn-xs btn-outline-warning"   onclick="filtrar('pend')"  id="f-pend">Não localizados (<?= (int)($divs['nao_localizado']??0) ?>)</button>
      <button class="btn btn-xs btn-outline-success"   onclick="filtrar('ok')"    id="f-ok">OK (<?= count(array_filter($itens, fn($i) => $i['verificado'] && !$i['divergencia'])) ?>)</button>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-hover mb-0" id="tabelaItens">
      <thead>
        <tr>
          <th>Patrimônio</th>
          <th>Tipo / Modelo</th>
          <th>Setor esperado</th>
          <th>Setor encontrado</th>
          <th>Responsável esperado</th>
          <th>Responsável encontrado</th>
          <th>Status</th>
          <th>Técnico</th>
          <th>Verificado em</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($itens as $i): ?>
        <?php
        $divg  = $i['divergencia'];
        $verif = $i['verificado'];
        $trClass = $divg ? 'linha-alerta' : '';
        ?>
        <tr class="<?= $trClass ?>"
            data-tipo="<?= $divg ? 'divg' : ($verif ? 'ok' : 'pend') ?>">
          <td><code><?= h($i['patrimonio'] ?: '—') ?></code></td>
          <td>
            <div class="fw-semibold" style="font-size:13px"><?= h($i['tipo']) ?></div>
            <div class="small text-muted"><?= h($i['marca'].' '.$i['modelo']) ?></div>
          </td>
          <td><?= h($i['setor_esperado'] ?: '—') ?></td>
          <td>
            <?php if ($i['setor_encontrado'] && $i['setor_encontrado'] !== $i['setor_esperado']): ?>
            <span class="text-danger fw-semibold"><?= h($i['setor_encontrado']) ?></span>
            <?php else: ?>
            <?= h($i['setor_encontrado'] ?: ($verif ? $i['setor_esperado'] : '—')) ?>
            <?php endif; ?>
          </td>
          <td><?= h($i['responsavel_esperado'] ?: '—') ?></td>
          <td>
            <?php if ($i['responsavel_encontrado'] && $i['responsavel_encontrado'] !== $i['responsavel_esperado']): ?>
            <span class="text-danger fw-semibold"><?= h($i['responsavel_encontrado']) ?></span>
            <?php else: ?>
            <?= h($i['responsavel_encontrado'] ?: ($verif ? $i['responsavel_esperado'] : '—')) ?>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($divg): ?>
            <span class="badge" style="background:#fee2e2;color:#991b1b">Divergência</span>
            <?php elseif ($verif): ?>
            <span class="badge" style="background:#dcfce7;color:#166534">OK</span>
            <?php else: ?>
            <span class="badge" style="background:#fef3c7;color:#92400e">Não localizado</span>
            <?php endif; ?>
          </td>
          <td class="small text-muted"><?= h($i['tecnico_nome'] ?: '—') ?></td>
          <td class="small text-muted">
            <?= $i['verificado_em'] ? date('d/m/y H:i', strtotime($i['verificado_em'])) : '—' ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function filtrar(tipo) {
    document.querySelectorAll('#tabelaItens tbody tr').forEach(tr => {
        tr.style.display = (!tipo || tr.dataset.tipo === tipo) ? '' : 'none';
    });
    document.querySelectorAll('[id^="f-"]').forEach(b => b.classList.remove('active'));
    document.getElementById('f-'+(tipo||'todos'))?.classList.add('active');
}
</script>

<?php endif; ?>

<?php layoutFooter(); ?>
