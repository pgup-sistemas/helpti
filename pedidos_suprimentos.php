<?php
require 'db.php';
requireLogin();
require 'layout.php';
require_once 'estoque_helpers.php';

$pdo = db();
$u = usuario();

// Processar Ações (via POST para segurança)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    $pedido_id = (int)($_POST['pedido_id'] ?? 0);

    if ($pedido_id > 0) {
        // Transições idempotentes: só mudam se o pedido está no estado esperado. (P1-2)
        if ($action === 'aprovar') {
            $stmt = $pdo->prepare("UPDATE pedidos_suprimentos SET status = 'Aprovado' WHERE id = ? AND status = 'Pendente'");
            $stmt->execute([$pedido_id]);
            flash($stmt->rowCount() === 1 ? "Pedido aprovado e enviado para separação!" : "Este pedido não está mais pendente.", $stmt->rowCount() === 1 ? 'success' : 'danger');
            header("Location: pedidos_suprimentos.php");
            exit;
        }

        if ($action === 'entregar') {
            $obs_entrega = trim($_POST['observacoes_entrega'] ?? '');
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE pedidos_suprimentos
                                       SET status = 'Entregue', observacoes_entrega = ?
                                       WHERE id = ? AND status IN ('Pendente','Aprovado')");
                $stmt->execute([$obs_entrega ?: 'Entregue com sucesso.', $pedido_id]);
                if ($stmt->rowCount() === 1) {
                    // Debita o estoque de cada item DENTRO da mesma transação.
                    estoque_debitar_pedido($pdo, $pedido_id, $u['id'] ?? null);
                    $pdo->commit();
                    auditLog('pedido_entregue', 'pedidos_suprimentos', $pedido_id);
                    flash("Suprimento marcado como entregue! Estoque atualizado.");
                } else {
                    $pdo->rollBack();
                    flash("Este pedido já foi entregue ou cancelado.", "danger");
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                logApp('error', 'entrega_pedido_falhou', ['pedido' => $pedido_id, 'msg' => $e->getMessage()]);
                flash("Erro ao registrar entrega: " . $e->getMessage(), "danger");
            }
            header("Location: pedidos_suprimentos.php");
            exit;
        }

        if ($action === 'cancelar') {
            $stmt = $pdo->prepare("UPDATE pedidos_suprimentos SET status = 'Cancelado' WHERE id = ? AND status IN ('Pendente','Aprovado')");
            $stmt->execute([$pedido_id]);
            flash($stmt->rowCount() === 1 ? "Pedido cancelado." : "Não é possível cancelar este pedido.", "danger");
            header("Location: pedidos_suprimentos.php");
            exit;
        }
    }
}

// Filtros
$filtro_setor = $_GET['setor'] ?? '';
$filtro_status = $_GET['status'] ?? '';
$busca_texto = trim($_GET['busca'] ?? '');

$where = [];
$params = [];

if ($filtro_setor) {
    $where[] = "s.setor = :setor";
    $params['setor'] = $filtro_setor;
}

if ($filtro_status) {
    $where[] = "s.status = :status";
    $params['status'] = $filtro_status;
}

if ($busca_texto) {
    $b = '%' . $busca_texto . '%';
    $where[] = "(s.solicitante LIKE :b1 OR s.numero LIKE :b2 OR s.observacoes LIKE :b3 OR s.observacoes_entrega LIKE :b4)";
    $params['b1'] = $b; $params['b2'] = $b; $params['b3'] = $b; $params['b4'] = $b;
}

// Stats
$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(status='Pendente') AS pendentes,
        SUM(status='Aprovado') AS aprovados,
        SUM(status='Entregue') AS entregues,
        SUM(status='Cancelado') AS cancelados
    FROM pedidos_suprimentos
")->fetch();

// Paginação
$pagina  = max(1, (int)($_GET['pagina'] ?? 1));
$limite  = 20;
$offset  = ($pagina - 1) * $limite;
$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$st_count = $pdo->prepare("SELECT COUNT(*) FROM pedidos_suprimentos s LEFT JOIN impressoras i ON i.id = s.impressora_id $where_sql");
$st_count->execute($params);
$total_registros = (int)$st_count->fetchColumn();
$total_paginas   = max(1, ceil($total_registros / $limite));

// Consulta principal
$sql = "
    SELECT s.*, i.nome AS impressora_nome
    FROM pedidos_suprimentos s
    LEFT JOIN impressoras i ON i.id = s.impressora_id
    $where_sql ORDER BY s.criado_em DESC LIMIT $limite OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pedidos = $stmt->fetchAll();

// Buscar itens para os pedidos listados
$itens_por_pedido = [];
if ($pedidos) {
    $ids = array_column($pedidos, 'id');
    $in = str_repeat('?,', count($ids) - 1) . '?';
    $st_itens = $pdo->prepare("
        SELECT pi.*, ts.nome AS tipo_nome 
        FROM pedidos_suprimentos_itens pi
        LEFT JOIN tipos_suprimentos ts ON ts.id = pi.tipo_suprimento_id
        WHERE pi.pedido_id IN ($in)
    ");
    $st_itens->execute($ids);
    foreach ($st_itens->fetchAll() as $item) {
        $itens_por_pedido[$item['pedido_id']][] = $item;
    }
}

layoutHeader('Pedidos de Suprimentos', 'suprimentos');

function badgeStatusS(string $s): string {
    $map = [
        'Pendente' => 'badge-pendente',
        'Aprovado' => 'badge-andamento',
        'Entregue' => 'badge-concluido',
        'Cancelado' => 'bg-secondary text-white'
    ];
    $cls = $map[$s] ?? 'bg-secondary text-white';
    return "<span class=\"badge $cls\">$s</span>";
}
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-box-seam-fill me-2 text-primary"></i>Pedidos de Suprimentos</h1>
  <a href="pedir_suprimento.php" target="_blank" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Novo Pedido (Público)</a>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md col-lg">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:var(--brand)"><?= (int)$stats['total'] ?></div>
          <div class="stat-label">Total de Pedidos</div>
        </div>
        <i class="bi bi-box-seam" style="font-size:22px;color:var(--brand);opacity:.35"></i>
      </div>
    </div>
  </div>
  <div class="col-6 col-md col-lg">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:#ef4444"><?= (int)$stats['pendentes'] ?></div>
          <div class="stat-label">Pendentes</div>
        </div>
        <i class="bi bi-exclamation-circle-fill" style="font-size:22px;color:#ef4444;opacity:.35"></i>
      </div>
    </div>
  </div>
  <div class="col-6 col-md col-lg">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:#f59e0b"><?= (int)$stats['aprovados'] ?></div>
          <div class="stat-label">Aprovados (Separação)</div>
        </div>
        <i class="bi bi-hourglass-split" style="font-size:22px;color:#f59e0b;opacity:.35"></i>
      </div>
    </div>
  </div>
  <div class="col-6 col-md col-lg">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:#22c55e"><?= (int)$stats['entregues'] ?></div>
          <div class="stat-label">Entregues</div>
        </div>
        <i class="bi bi-check-circle-fill" style="font-size:22px;color:#22c55e;opacity:.35"></i>
      </div>
    </div>
  </div>
  <div class="col-6 col-md col-lg">
    <div class="stat-card">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-num" style="color:#6c757d"><?= (int)$stats['cancelados'] ?></div>
          <div class="stat-label">Cancelados</div>
        </div>
        <i class="bi bi-x-circle-fill" style="font-size:22px;color:#6c757d;opacity:.35"></i>
      </div>
    </div>
  </div>
</div>

<!-- Filtros -->
<div class="card mb-4">
  <div class="card-header"><i class="bi bi-funnel me-2"></i>Filtrar Pedidos</div>
  <div class="card-body py-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label fw-semibold mb-1" style="font-size:12px">Setor</label>
        <select name="setor" class="form-select form-select-sm">
          <option value="">Todos os Setores</option>
          <?php foreach ($SETORES as $s): ?>
            <option value="<?= h($s) ?>" <?= $filtro_setor === $s ? 'selected' : '' ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold mb-1" style="font-size:12px">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">Todos os Status</option>
          <?php foreach (['Pendente', 'Aprovado', 'Entregue', 'Cancelado'] as $st_opt): ?>
            <option value="<?= $st_opt ?>" <?= $filtro_status === $st_opt ? 'selected' : '' ?>><?= $st_opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold mb-1" style="font-size:12px">Buscar texto</label>
        <input type="text" name="busca" class="form-control form-control-sm" placeholder="Código, solicitante, notas..." value="<?= h($busca_texto) ?>">
      </div>
      <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold"><i class="bi bi-search me-1"></i>Filtrar</button>
        <a href="pedidos_suprimentos.php" class="btn btn-outline-secondary btn-sm" title="Limpar Filtros">✕</a>
      </div>
    </form>
  </div>
</div>

<!-- Tabela -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-task me-2 text-primary"></i>Lista de Solicitações de Insumos</span>
    <?php if ($busca_texto || $filtro_setor || $filtro_status): ?>
      <span class="badge bg-primary"><?= $total_registros ?> resultado(s)</span>
    <?php else: ?>
      <span class="text-muted" style="font-size:12px"><?= $total_registros ?> registros</span>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Código</th>
          <th>Solicitante</th>
          <th>Setor</th>
          <th>Impressora</th>
          <th>Itens Solicitados</th>
          <th>Status</th>
          <th>Data Pedido</th>
          <th>Notas / Entrega</th>
          <th style="min-width: 150px;">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pedidos as $p): ?>
          <?php 
            $itens = $itens_por_pedido[$p['id']] ?? []; 
            // Preparar JSON para o modal
            $itens_json = json_encode(array_map(function($i) {
                return [
                    'nome' => $i['tipo_nome'] ?? 'Outros',
                    'desc' => $i['descricao_livre'],
                    'qtd' => $i['quantidade']
                ];
            }, $itens));
          ?>
          <tr>
            <td>
              <code><a href="acompanhar_suprimentos.php?numero=<?= urlencode($p['numero']) ?>" target="_blank" title="Rastrear publicamente"><?= h($p['numero']) ?></a></code>
            </td>
            <td><strong><?= h($p['solicitante']) ?></strong></td>
            <td style="font-size:12px"><?= h($p['setor']) ?></td>
            <td>
              <?php if ($p['impressora_id']): ?>
                <strong><a href="impressora.php?id=<?= $p['impressora_id'] ?>" class="text-decoration-none text-dark"><?= h($p['impressora_nome'] ?? 'Equipamento') ?></a></strong>
              <?php else: ?>
                <span class="text-muted italic small"><i class="bi bi-info-circle me-1"></i>Uso Geral Setor</span>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;">
              <ul class="mb-0 ps-3">
                <?php foreach ($itens as $item): ?>
                  <li>
                    <span class="text-primaryfw-semibold"><?= h($item['tipo_nome'] ?? 'Outros') ?></span>
                    <?php if ($item['descricao_livre']): ?>
                      <span class="text-muted">(<?= h($item['descricao_livre']) ?>)</span>
                    <?php endif; ?>
                    — <span class="badge bg-light text-dark border"><?= (int)$item['quantidade'] ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            </td>
            <td><?= badgeStatusS($p['status']) ?></td>
            <td style="font-size:12px;white-space:nowrap"><?= date('d/m H:i', strtotime($p['criado_em'])) ?></td>
            <td style="max-width: 180px; font-size:11.5px">
              <?php if ($p['status'] === 'Entregue'): ?>
                <span class="text-success" title="<?= h($p['observacoes_entrega'] ?? '') ?>"><i class="bi bi-check2-circle me-1"></i><?= h($p['observacoes_entrega'] ?? '') ?></span>
              <?php else: ?>
                <span class="text-muted" title="<?= h($p['observacoes'] ?? '') ?>"><?= h($p['observacoes'] ?? '') ?></span>
              <?php endif; ?>
            </td>
            <td>
              <div class="d-flex gap-1">
                <?php if ($p['status'] === 'Pendente'): ?>
                  <!-- Aprovar -->
                  <form method="post" onsubmit="return confirm('Deseja aprovar este pedido e iniciar a separacao?');" style="display:inline;">
      <?= csrfField() ?>
                    <input type="hidden" name="action" value="aprovar">
                    <input type="hidden" name="pedido_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-outline-success btn-xs" title="Aprovar Pedido">
                      <i class="bi bi-check2"></i> Aprovar
                    </button>
                  </form>
                  <!-- Cancelar -->
                  <form method="post" onsubmit="return confirm('Deseja CANCELAR este pedido?');" style="display:inline;">
      <?= csrfField() ?>
                    <input type="hidden" name="action" value="cancelar">
                    <input type="hidden" name="pedido_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-outline-danger btn-xs" title="Cancelar Pedido">
                      <i class="bi bi-x-lg"></i>
                    </button>
                  </form>
                <?php elseif ($p['status'] === 'Aprovado'): ?>
                  <!-- Entregar (Chama Modal) -->
                  <button type="button" class="btn btn-success btn-xs fw-semibold text-white btn-entregar" 
                          data-id="<?= $p['id'] ?>" 
                          data-codigo="<?= h($p['numero']) ?>" 
                          data-itens="<?= htmlspecialchars($itens_json) ?>" 
                          data-setor="<?= h($p['setor']) ?>" 
                          data-bs-toggle="modal" data-bs-target="#entregaModal">
                    <i class="bi bi-box-seam"></i> Entregar
                  </button>
                  <!-- Cancelar -->
                  <form method="post" onsubmit="return confirm('Deseja CANCELAR este pedido aprovado?');" style="display:inline;">
      <?= csrfField() ?>
                    <input type="hidden" name="action" value="cancelar">
                    <input type="hidden" name="pedido_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-outline-danger btn-xs" title="Cancelar Pedido">
                      <i class="bi bi-x-lg"></i>
                    </button>
                  </form>
                <?php else: ?>
                  <span class="text-muted font-monospace small" style="font-size:11px;">Finalizado</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$pedidos): ?>
          <tr>
            <td colspan="10" class="text-center text-muted py-4">Nenhum pedido de suprimento encontrado.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($total_paginas > 1): ?>
  <div class="card-footer bg-white py-3 border-top">
    <nav>
      <ul class="pagination pagination-sm justify-content-center mb-0">
        <?php
          $queryParams = $_GET;
          $queryParams['pagina'] = max(1, $pagina - 1);
        ?>
        <li class="page-item <?= ($pagina <= 1) ? 'disabled' : '' ?>">
          <a class="page-link" href="?<?= http_build_query($queryParams) ?>">Anterior</a>
        </li>
        <?php
          $inicio = max(1, $pagina - 2);
          $fim    = min($total_paginas, $pagina + 2);
          if ($inicio > 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
          for ($i = $inicio; $i <= $fim; $i++):
            $queryParams['pagina'] = $i;
        ?>
          <li class="page-item <?= ($pagina == $i) ? 'active' : '' ?>">
            <a class="page-link" href="?<?= http_build_query($queryParams) ?>"><?= $i ?></a>
          </li>
        <?php endfor;
          if ($fim < $total_paginas) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
          $queryParams['pagina'] = min($total_paginas, $pagina + 1);
        ?>
        <li class="page-item <?= ($pagina >= $total_paginas) ? 'disabled' : '' ?>">
          <a class="page-link" href="?<?= http_build_query($queryParams) ?>">Próxima</a>
        </li>
      </ul>
    </nav>
    <div class="text-center text-muted mt-2" style="font-size:12px">
      Página <?= $pagina ?> de <?= $total_paginas ?> — <?= $total_registros ?> registro(s)
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Modal de Confirmação de Entrega -->
<div class="modal fade" id="entregaModal" tabindex="-1" aria-labelledby="entregaModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content card">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title fw-bold" id="entregaModalLabel"><i class="bi bi-check-circle me-2"></i>Registrar Entrega de Suprimento</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post">
      <?= csrfField() ?>
        <div class="modal-body p-4">
          <input type="hidden" name="action" value="entregar">
          <input type="hidden" name="pedido_id" id="modal_pedido_id" value="">
          
          <div class="mb-3 p-3 bg-light rounded" style="font-size: 13px;">
            <div class="mb-2"><strong>Pedido:</strong> <span id="modal_pedido_codigo" class="font-monospace text-primary fw-bold"></span></div>
            <div><strong>Setor de Entrega:</strong> <span id="modal_pedido_setor" class="fw-semibold"></span></div>
            <div class="mt-2 pt-2 border-top">
                <strong>Itens para Entregar:</strong>
                <ul id="modal_pedido_itens" class="mb-0 ps-3 text-dark mt-1"></ul>
            </div>
          </div>
          
          <div class="mb-3">
            <label for="observacoes_entrega" class="form-label fw-semibold">Observações / Quem Recebeu</label>
            <textarea class="form-control" name="observacoes_entrega" id="observacoes_entrega" rows="3" 
                      placeholder="Ex: Entregue para recepcionista Luciana às 15:30. Coletado na TI."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-shadow="none" data-bs-dismiss="modal">Fechar</button>
          <button type="submit" class="btn btn-success btn-sm text-white"><i class="bi bi-box-seam me-1"></i>Confirmar e Entregar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const entregarButtons = document.querySelectorAll('.btn-entregar');
    const modalPedidoId = document.getElementById('modal_pedido_id');
    const modalPedidoCodigo = document.getElementById('modal_pedido_codigo');
    const modalPedidoItens = document.getElementById('modal_pedido_itens');
    const modalPedidoSetor = document.getElementById('modal_pedido_setor');
    const obsEntregaInput = document.getElementById('observacoes_entrega');

    entregarButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const codigo = this.getAttribute('data-codigo');
            const setor = this.getAttribute('data-setor');
            const itensJson = this.getAttribute('data-itens');

            modalPedidoId.value = id;
            modalPedidoCodigo.textContent = codigo;
            modalPedidoSetor.textContent = setor;
            obsEntregaInput.value = ''; // limpa notas anteriores
            
            // Popula os itens
            modalPedidoItens.innerHTML = '';
            try {
                const itens = JSON.parse(itensJson);
                itens.forEach(item => {
                    const li = document.createElement('li');
                    let desc = item.desc ? ` <span class="text-muted">(${item.desc})</span>` : '';
                    li.innerHTML = `<strong>${item.nome}</strong>${desc} — <span class="badge bg-secondary">${item.qtd}x</span>`;
                    modalPedidoItens.appendChild(li);
                });
            } catch (e) {
                console.error("Erro ao fazer parse dos itens");
            }
        });
    });
});
</script>

<?php layoutFooter(); ?>
