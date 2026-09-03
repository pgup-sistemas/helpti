<?php
require 'db.php';
requireLogin();
require 'layout.php';

$pdo = db();

$filtros = [
    'status'  => $_GET['status']  ?? '',
    'resp'    => $_GET['resp']    ?? '',
    'setor'   => $_GET['setor']   ?? '',
    'busca'   => $_GET['busca']   ?? '',
    'mes'     => $_GET['mes']     ?? date('m'),
    'ano'     => $_GET['ano']     ?? date('Y'),
];

$where = ["YEAR(c.criado_em)=:ano","MONTH(c.criado_em)=:mes","c.deleted_at IS NULL"];
$params = ['ano'=>$filtros['ano'],'mes'=>$filtros['mes']];

if ($filtros['status'])          { $where[] = "c.status=:status";       $params['status'] = $filtros['status']; }
if ($filtros['resp'] === '0')    { $where[] = "c.responsavel_id IS NULL"; }
elseif ($filtros['resp'])        { $where[] = "c.responsavel_id=:resp"; $params['resp'] = $filtros['resp']; }
if ($filtros['setor'])           { $where[] = "c.setor=:setor";         $params['setor'] = $filtros['setor']; }
if ($filtros['busca'])           { $b='%'.$filtros['busca'].'%'; $where[] = "(c.descricao LIKE :b1 OR c.solicitante LIKE :b2 OR c.numero LIKE :b3 OR c.setor LIKE :b4)"; $params['b1']=$b; $params['b2']=$b; $params['b3']=$b; $params['b4']=$b; }

$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$limite = 50;
$offset = ($pagina - 1) * $limite;

$sql_count = "SELECT COUNT(*) FROM chamados c
              LEFT JOIN usuarios u ON u.id=c.responsavel_id
              WHERE ".implode(' AND ',$where);
$st_count = $pdo->prepare($sql_count);
$st_count->execute($params);
$total_registros = $st_count->fetchColumn();
$total_paginas = ceil($total_registros / $limite);

$sql = "SELECT c.*, u.nome AS resp_nome FROM chamados c
        LEFT JOIN usuarios u ON u.id=c.responsavel_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY c.criado_em DESC LIMIT $limite OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);
$chamados = $st->fetchAll();

$tecnicos = $pdo->query("SELECT id,nome FROM usuarios WHERE ativo=1 AND perfil='tecnico' ORDER BY nome")->fetchAll();

layoutHeader('Chamados', 'chamados');

function bs(string $s): string {
    $map=['Aberto'=>'badge-aberto','Em Andamento'=>'badge-andamento','Pendente'=>'badge-pendente','Concluído'=>'badge-concluido'];
    return "<span class=\"badge ".($map[$s]??'bg-secondary text-white')."\">$s</span>";
}
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-ticket-detailed-fill me-2 text-primary"></i>Chamados</h1>
  <a href="novo_chamado.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Novo</a>
</div>

<!-- Filtros -->
<div class="card mb-3">
  <div class="card-body py-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-2">
        <label class="form-label fw-semibold" style="font-size:12px">Mês</label>
        <select name="mes" class="form-select form-select-sm">
          <?php for($m=1;$m<=12;$m++): ?>
            <option value="<?= $m ?>" <?= $m==(int)$filtros['mes']?'selected':'' ?>><?= date('F',mktime(0,0,0,$m,1)) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-1">
        <label class="form-label fw-semibold" style="font-size:12px">Ano</label>
        <select name="ano" class="form-select form-select-sm">
          <?php for($a=2024;$a<=2027;$a++): ?>
            <option value="<?= $a ?>" <?= $a==(int)$filtros['ano']?'selected':'' ?>><?= $a ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold" style="font-size:12px">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">Todos</option>
          <?php foreach(['Aberto','Em Andamento','Pendente','Concluído'] as $s): ?>
            <option <?= $filtros['status']===$s?'selected':'' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold" style="font-size:12px">Responsável</label>
        <select name="resp" class="form-select form-select-sm">
          <option value="">Todos</option>
          <option value="0" <?= $filtros['resp']==='0'?'selected':'' ?>>Sem atribuição</option>
          <?php foreach($tecnicos as $t): ?>
            <option value="<?= $t['id'] ?>" <?= $filtros['resp']==$t['id']?'selected':'' ?>><?= h($t['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold" style="font-size:12px">Busca</label>
        <input type="text" name="busca" class="form-control form-control-sm" placeholder="Nº, descrição ou solicitante..." value="<?= h($filtros['busca']) ?>">
      </div>
      <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm flex-fill">Filtrar</button>
        <a href="chamados.php" class="btn btn-outline-secondary btn-sm">✕</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><?= $total_registros ?> chamado(s) encontrado(s)</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 table-sortable">
      <thead>
        <tr>
          <th data-sort>Nº</th><th data-sort>Descrição</th><th data-sort>Setor</th><th data-sort>Solicitante</th>
          <th data-sort>Resp.</th><th>Nível / SLA</th><th data-sort>Status</th><th data-sort data-sort-type="number">Data</th><th class="text-end">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($chamados as $c): ?>
        <tr>
          <td><code style="font-size:12px"><?= h($c['numero']) ?></code></td>
          <td style="max-width:220px"><div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($c['descricao']) ?>"><?= h($c['descricao']) ?></div></td>
          <td style="font-size:12px"><?= h($c['setor']) ?></td>
          <td style="font-size:13px"><?= h($c['solicitante']) ?></td>
          <td style="font-size:13px">
            <?php if ($c['resp_nome']): ?>
              <?= h($c['resp_nome']) ?>
            <?php else: ?>
              <button class="btn btn-xs btn-outline-warning" title="Atribuir técnico"
                onclick="abrirAtribuicao(<?= $c['id'] ?>, '<?= h($c['numero']) ?>')">
                <i class="bi bi-person-plus"></i> Atribuir
              </button>
            <?php endif; ?>
          </td>
          <td>
            <?php
              $n = $c['nivel'];
              if (str_contains($n,'Baixa'))      echo '<span class="badge badge-nivel-baixa">Baixa</span>';
              elseif (str_contains($n,'Média'))   echo '<span class="badge badge-nivel-media">Média</span>';
              elseif (str_contains($n,'Alta'))    echo '<span class="badge badge-nivel-alta">Alta</span>';
              else echo '<span class="badge bg-light text-muted">—</span>';
              echo slaBadge($c['nivel'], $c['criado_em'], $c['status']);
            ?>
          </td>
          <td><?= bs($c['status']) ?></td>
          <td style="font-size:12px;white-space:nowrap" data-sort-value="<?= strtotime($c['criado_em']) ?>"><?= date('d/m/y H:i', strtotime($c['criado_em'])) ?></td>
          <td class="text-end">
            <div class="dropdown">
              <button class="btn btn-light btn-xs border dropdown-toggle" type="button" data-bs-toggle="dropdown">Ações</button>
              <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size:13.5px">
                <li><a class="dropdown-item" href="chamado.php?id=<?= $c['id'] ?>"><i class="bi bi-eye text-primary me-2"></i>Visualizar / Atender</a></li>
                <li><a class="dropdown-item" href="editar_chamado.php?id=<?= $c['id'] ?>"><i class="bi bi-pencil-square text-secondary me-2"></i>Editar Dados</a></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                  <form method="post" action="excluir_chamado.php" onsubmit="return confirm('ATENÇÃO: Deseja EXCLUIR este chamado permanentemente?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                    <button type="submit" class="dropdown-item text-danger fw-semibold"><i class="bi bi-trash text-danger me-2"></i>Excluir Chamado</button>
                  </form>
                </li>
              </ul>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$chamados): ?>
        <tr>
          <td colspan="9" class="text-center py-5">
            <i class="bi bi-inbox" style="font-size:36px;color:#d1d5db;display:block;margin-bottom:10px"></i>
            <div class="text-muted">Nenhum chamado encontrado para os filtros aplicados.</div>
            <a href="chamados.php" class="btn btn-outline-secondary btn-sm mt-2">Limpar filtros</a>
          </td>
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
          // Limitar a exibição de páginas para não estourar a tela se houver muitas
          $inicio = max(1, $pagina - 2);
          $fim = min($total_paginas, $pagina + 2);
          
          if ($inicio > 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
          
          for($i = $inicio; $i <= $fim; $i++): 
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
  </div>
  <?php endif; ?>
  
</div>

<!-- Modal Atribuição Rápida -->
<div class="modal fade" id="modalAtribuir" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="bi bi-person-check me-2 text-primary"></i>Atribuir Técnico</h6>
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" action="chamado.php" id="formAtribuir">
        <div class="modal-body">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="atualizar">
          <input type="hidden" name="nivel" value="A Definir" id="hidNivel">
          <input type="hidden" name="status" value="Em Andamento">
          <input type="hidden" name="resolucao" value="">
          <p class="text-muted small mb-2">Chamado: <code id="lblNumero"></code></p>
          <label class="form-label fw-semibold" style="font-size:13px">Técnico responsável</label>
          <select name="responsavel_id" class="form-select form-select-sm" required>
            <option value="">— selecione —</option>
            <?php foreach ($tecnicos as $t): ?>
              <option value="<?= $t['id'] ?>"><?= h($t['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm">Atribuir e Iniciar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function abrirAtribuicao(id, numero) {
  document.getElementById('formAtribuir').action = 'chamado.php?id=' + id;
  document.getElementById('lblNumero').textContent = numero;
  new bootstrap.Modal(document.getElementById('modalAtribuir')).show();
}
</script>

<?php layoutFooter(); ?>
