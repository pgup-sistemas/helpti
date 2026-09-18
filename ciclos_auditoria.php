<?php
// Painel de gestão de ciclos de auditoria — gestora/admin
require 'db.php';
requireGestora();
require 'layout.php';

$pdo = db();
$u   = usuario();

// ── Ações POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    if ($action === 'iniciar') {
        $nome  = trim($_POST['nome'] ?? '');
        $descr = trim($_POST['descricao'] ?? '');
        $setor = trim($_POST['setor_filtro'] ?? '');
        if (!$nome) { flash('Informe o nome do ciclo.', 'danger'); header('Location: ciclos_auditoria.php'); exit; }

        $where  = "status != 'Descartado' AND deleted_at IS NULL";
        $params = [];
        if ($setor) { $where .= " AND setor = ?"; $params[] = $setor; }
        $st = $pdo->prepare("SELECT COUNT(*) FROM inventario WHERE $where");
        $st->execute($params);
        $total = (int)$st->fetchColumn();

        if ($total === 0) {
            flash('Nenhum ativo encontrado para este filtro.', 'warning');
            header('Location: ciclos_auditoria.php'); exit;
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO ciclos_auditoria (nome, descricao, responsavel_id, status, setor_filtro, total_esperado)
                            VALUES (?,?,?,'Em Andamento',?,?)")
                ->execute([$nome, $descr ?: null, $u['id'], $setor ?: null, $total]);
            $ciclo_id = (int)$pdo->lastInsertId();

            $ativos = $pdo->prepare("SELECT id, status, setor, responsavel_nome FROM inventario WHERE $where");
            $ativos->execute($params);
            $ins = $pdo->prepare("INSERT IGNORE INTO auditoria_itens
                (ciclo_id, inventario_id, status_esperado, setor_esperado, responsavel_esperado)
                VALUES (?,?,?,?,?)");
            foreach ($ativos->fetchAll() as $a) {
                $ins->execute([$ciclo_id, $a['id'], $a['status'], $a['setor'], $a['responsavel_nome']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('Erro ao iniciar ciclo. Tente novamente.', 'danger');
            header('Location: ciclos_auditoria.php'); exit;
        }
        auditLog('ciclo_auditoria_iniciado', 'ciclos_auditoria', $ciclo_id, $nome);
        flash("Ciclo «{$nome}» iniciado com {$total} ativos.");
        header('Location: ciclos_auditoria.php'); exit;
    }

    if ($action === 'encerrar') {
        $ciclo_id = (int)($_POST['ciclo_id'] ?? 0);
        $ciclo_r  = $pdo->prepare("SELECT * FROM ciclos_auditoria WHERE id=?");
        $ciclo_r->execute([$ciclo_id]);
        $ciclo_r  = $ciclo_r->fetch();

        if ($ciclo_r && $ciclo_r['status'] === 'Em Andamento') {
            // Recalcula acurácia final
            $pdo->prepare("UPDATE ciclos_auditoria SET
                total_verificado = (SELECT COUNT(*) FROM auditoria_itens WHERE ciclo_id=? AND verificado=1),
                taxa_acuracia    = ROUND((SELECT COUNT(*) FROM auditoria_itens WHERE ciclo_id=? AND verificado=1)
                                          / GREATEST(total_esperado,1) * 100, 2),
                status           = 'Concluído',
                encerrado_em     = NOW()
                WHERE id=?")->execute([$ciclo_id, $ciclo_id, $ciclo_id]);

            // Registra movimentação nas divergências
            $divs = $pdo->prepare("
                SELECT ai.inventario_id, ai.setor_esperado, ai.setor_encontrado,
                       ai.responsavel_esperado, ai.responsavel_encontrado,
                       ai.status_esperado, ai.status_encontrado
                FROM auditoria_itens ai WHERE ai.ciclo_id=? AND ai.divergencia=1
            ");
            $divs->execute([$ciclo_id]);
            $ins_hm = $pdo->prepare("INSERT INTO historico_movimentacao
                (inventario_id,usuario_id,tipo,setor_anterior,setor_novo,resp_anterior,resp_novo,status_anterior,status_novo,observacao)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
            foreach ($divs->fetchAll() as $d) {
                $ins_hm->execute([$d['inventario_id'], $u['id'], 'Auditoria',
                    $d['setor_esperado'], $d['setor_encontrado'],
                    $d['responsavel_esperado'], $d['responsavel_encontrado'],
                    $d['status_esperado'], $d['status_encontrado'],
                    "Divergência no ciclo #{$ciclo_id}: {$ciclo_r['nome']}"]);
            }
            auditLog('ciclo_auditoria_encerrado', 'ciclos_auditoria', $ciclo_id, $ciclo_r['nome']);
            flash("Ciclo «{$ciclo_r['nome']}» encerrado.");
        }
        header('Location: ciclos_auditoria.php'); exit;
    }

    if ($action === 'cancelar') {
        $ciclo_id = (int)($_POST['ciclo_id'] ?? 0);
        $pdo->prepare("UPDATE ciclos_auditoria SET status='Cancelado' WHERE id=? AND status='Em Andamento'")
            ->execute([$ciclo_id]);
        flash('Ciclo cancelado.', 'warning');
        header('Location: ciclos_auditoria.php'); exit;
    }
}

// ── Dados ─────────────────────────────────────────────────────────────────────
$ciclos = $pdo->query("
    SELECT ca.*, u.nome AS resp_nome,
           (SELECT COUNT(*) FROM auditoria_itens WHERE ciclo_id=ca.id AND divergencia=1) AS divergencias,
           (SELECT COUNT(*) FROM auditoria_itens WHERE ciclo_id=ca.id AND verificado=0)  AS pendentes
    FROM ciclos_auditoria ca
    LEFT JOIN usuarios u ON u.id = ca.responsavel_id
    ORDER BY ca.criado_em DESC
")->fetchAll();

$em_andamento = $pdo->query("SELECT id FROM ciclos_auditoria WHERE status='Em Andamento' LIMIT 1")->fetchColumn();

layoutHeader('Ciclos de Auditoria', 'auditoria');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-list-check text-primary me-2"></i>Ciclos de Auditoria</h1>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoCiclo">
    <i class="bi bi-plus-circle me-1"></i>Novo Ciclo
  </button>
</div>

<?php if (!$ciclos): ?>
<div class="card">
  <div class="card-body text-center py-5">
    <i class="bi bi-clipboard2" style="font-size:40px;color:var(--tx-faint)"></i>
    <p class="mt-3 mb-0 text-muted">Nenhum ciclo de auditoria criado ainda.</p>
  </div>
</div>
<?php else: ?>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>Nome</th>
          <th>Setor</th>
          <th>Responsável</th>
          <th>Progresso</th>
          <th>Acurácia</th>
          <th>Divergências</th>
          <th>Status</th>
          <th>Iniciado</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ciclos as $c): ?>
        <?php
        $statusBg = match($c['status']) {
            'Em Andamento' => 'badge-andamento',
            'Concluído'    => 'badge-concluido',
            'Cancelado'    => 'bg-secondary text-white',
            default        => 'bg-light text-dark',
        };
        $pct = $c['total_esperado'] > 0 ? round($c['total_verificado'] / $c['total_esperado'] * 100) : 0;
        ?>
        <tr>
          <td><code><?= $c['id'] ?></code></td>
          <td>
            <div class="fw-semibold"><?= h($c['nome']) ?></div>
            <?php if ($c['descricao']): ?><div class="small text-muted"><?= h(mb_strimwidth($c['descricao'],0,60,'…')) ?></div><?php endif; ?>
          </td>
          <td><?= h($c['setor_filtro'] ?: 'Todos') ?></td>
          <td><?= h($c['resp_nome'] ?? '—') ?></td>
          <td style="min-width:130px">
            <div class="d-flex align-items-center gap-2">
              <div class="progress flex-fill" style="height:6px">
                <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
              </div>
              <small class="text-muted" style="white-space:nowrap"><?= $c['total_verificado'] ?>/<?= $c['total_esperado'] ?></small>
            </div>
          </td>
          <td>
            <?php if ($c['taxa_acuracia'] !== null): ?>
            <span class="fw-bold <?= $c['taxa_acuracia'] >= 90 ? 'text-success' : ($c['taxa_acuracia'] >= 70 ? 'text-warning' : 'text-danger') ?>">
              <?= $c['taxa_acuracia'] ?>%
            </span>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($c['divergencias'] > 0): ?>
            <span class="badge" style="background:#fee2e2;color:#991b1b"><?= $c['divergencias'] ?></span>
            <?php else: ?>
            <span class="text-muted small">—</span>
            <?php endif; ?>
          </td>
          <td><span class="badge <?= $statusBg ?>"><?= h($c['status']) ?></span></td>
          <td class="text-muted small"><?= date('d/m/Y H:i', strtotime($c['criado_em'])) ?></td>
          <td>
            <div class="d-flex gap-1">
              <?php if ($c['status'] === 'Em Andamento'): ?>
              <a href="auditoria.php?ciclo_id=<?= $c['id'] ?>" class="btn btn-xs btn-outline-primary" title="Ir para campo">
                <i class="bi bi-qr-code-scan"></i>
              </a>
              <form method="post" class="d-inline" onsubmit="return confirm('Encerrar ciclo «<?= h($c['nome']) ?>»?')">
                <?= csrfField() ?>
                <input type="hidden" name="action"   value="encerrar">
                <input type="hidden" name="ciclo_id" value="<?= $c['id'] ?>">
                <button class="btn btn-xs btn-outline-success" title="Encerrar"><i class="bi bi-check-all"></i></button>
              </form>
              <form method="post" class="d-inline" onsubmit="return confirm('Cancelar este ciclo?')">
                <?= csrfField() ?>
                <input type="hidden" name="action"   value="cancelar">
                <input type="hidden" name="ciclo_id" value="<?= $c['id'] ?>">
                <button class="btn btn-xs btn-outline-danger" title="Cancelar"><i class="bi bi-x-lg"></i></button>
              </form>
              <?php else: ?>
              <a href="relatorio_auditoria.php?ciclo_id=<?= $c['id'] ?>" class="btn btn-xs btn-outline-secondary" title="Relatório">
                <i class="bi bi-bar-chart-line"></i>
              </a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Modal novo ciclo -->
<div class="modal fade" id="modalNovoCiclo" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="iniciar">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-plus-circle me-2 text-primary"></i>Novo Ciclo de Auditoria</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if ($em_andamento): ?>
        <div class="alert alert-warning small">
          <i class="bi bi-exclamation-triangle me-2"></i>
          Já existe um <a href="auditoria.php?ciclo_id=<?= $em_andamento ?>">ciclo em andamento</a>. Você pode criar outro para um setor diferente.
        </div>
        <?php endif; ?>
        <div class="mb-3">
          <label class="form-label">Nome do ciclo <span class="text-danger">*</span></label>
          <input type="text" name="nome" class="form-control" placeholder="Ex: Auditoria Q3-2026" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Setor <span class="text-muted small">(deixe vazio para todos)</span></label>
          <select name="setor_filtro" class="form-select">
            <option value="">— Todos os setores —</option>
            <?php foreach ($SETORES as $s): ?>
            <option value="<?= h($s) ?>"><?= h($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Descrição <span class="text-muted small">(opcional)</span></label>
          <textarea name="descricao" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="bi bi-play-circle me-1"></i>Iniciar ciclo
        </button>
      </div>
    </form>
  </div>
</div>

<?php layoutFooter(); ?>
