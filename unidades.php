<?php
// ============================================================
//  unidades.php — Gestão de Unidades / Filiais
// ============================================================
require 'db.php';
requireAdmin();
require 'layout.php';

$pdo = db();
$u   = usuario();

// ── Listar gestores para o campo responsável ──
$gestoras = $pdo->query("SELECT id, nome FROM usuarios WHERE perfil IN ('admin','gestora') AND ativo=1 AND deleted_at IS NULL ORDER BY nome")->fetchAll();

// ── POST: salvar (novo ou editar) / toggle ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action  = $_POST['_action'] ?? '';
    $id_edit = (int)($_POST['id'] ?? 0);

    if ($action === 'salvar') {
        $nome   = trim($_POST['nome'] ?? '');
        $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
        $cep    = preg_replace('/\D/', '', $_POST['cep'] ?? '');
        $cidade = trim($_POST['cidade'] ?? '');
        $bairro = trim($_POST['bairro'] ?? '');
        $uf     = strtoupper(trim($_POST['uf'] ?? ''));
        $end    = trim($_POST['endereco'] ?? '');
        $tel    = trim($_POST['telefone'] ?? '');
        $email  = trim($_POST['email_suporte'] ?? '');
        $equipe = (int)($_POST['equipe_responsavel_id'] ?? 0) ?: null;
        $sla    = str_replace(',', '.', trim($_POST['sla_multiplicador'] ?? '1'));
        $ativo  = isset($_POST['ativo']) ? 1 : 0;

        // Formata CEP para armazenar como 00000-000
        $cepFmt = ($cep && strlen($cep) === 8) ? substr($cep, 0, 5) . '-' . substr($cep, 5) : ($cep ?: null);

        $erros = [];
        if (!$nome)   $erros[] = 'Nome é obrigatório.';
        if (!$codigo) $erros[] = 'Código é obrigatório.';
        if (!preg_match('/^\d+(\.\d+)?$/', $sla) || (float)$sla <= 0 || (float)$sla > 10)
            $erros[] = 'Multiplicador SLA inválido (entre 0.01 e 10.00).';
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL))
            $erros[] = 'E-mail de suporte inválido.';
        if ($cep && strlen($cep) !== 8)
            $erros[] = 'CEP deve ter 8 dígitos.';
        if ($uf && !preg_match('/^[A-Z]{2}$/', $uf))
            $erros[] = 'UF inválida (2 letras).';

        if (!$erros) {
            $ck = $pdo->prepare("SELECT id FROM unidades WHERE codigo=?" . ($id_edit ? " AND id!=?" : ""));
            $id_edit ? $ck->execute([$codigo, $id_edit]) : $ck->execute([$codigo]);
            if ($ck->fetch()) $erros[] = "Código '$codigo' já em uso por outra unidade.";
        }

        if (!$erros) {
            if ($id_edit) {
                $old = $pdo->prepare("SELECT nome FROM unidades WHERE id=?");
                $old->execute([$id_edit]);
                $old = $old->fetchColumn();
                $pdo->prepare("UPDATE unidades SET nome=?,codigo=?,cep=?,cidade=?,bairro=?,uf=?,endereco=?,telefone=?,email_suporte=?,equipe_responsavel_id=?,sla_multiplicador=?,ativo=? WHERE id=?")
                    ->execute([$nome,$codigo,$cepFmt,$cidade?:null,$bairro?:null,$uf?:null,$end?:null,$tel?:null,$email?:null,$equipe,(float)$sla,$ativo,$id_edit]);
                auditLog('unidade_editada','unidades',$id_edit,"$old → $nome");
                flash("Unidade \"$nome\" atualizada.");
            } else {
                $pdo->prepare("INSERT INTO unidades (nome,codigo,cep,cidade,bairro,uf,endereco,telefone,email_suporte,equipe_responsavel_id,sla_multiplicador,ativo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$nome,$codigo,$cepFmt,$cidade?:null,$bairro?:null,$uf?:null,$end?:null,$tel?:null,$email?:null,$equipe,(float)$sla,$ativo]);
                auditLog('unidade_criada','unidades',(int)$pdo->lastInsertId(),$nome);
                flash("Unidade \"$nome\" criada.");
            }
            header('Location: unidades.php'); exit;
        }
        // se chegou aqui tem erros — vai mostrar o form preenchido abaixo
    }

    if ($action === 'toggle_ativo' && $id_edit) {
        $row = $pdo->prepare("SELECT nome, ativo FROM unidades WHERE id=?");
        $row->execute([$id_edit]);
        $row = $row->fetch();
        if ($row) {
            $novo = $row['ativo'] ? 0 : 1;
            $pdo->prepare("UPDATE unidades SET ativo=? WHERE id=?")->execute([$novo, $id_edit]);
            auditLog($novo ? 'unidade_ativada' : 'unidade_desativada', 'unidades', $id_edit, $row['nome']);
            flash("Unidade \"{$row['nome']}\" " . ($novo ? 'ativada' : 'desativada') . '.');
        }
        header('Location: unidades.php'); exit;
    }
}

// ── Edição: carregar dados ──
$edit    = null;
$erros   = $erros ?? [];
$edit_id = (int)($_GET['editar'] ?? 0);
if ($edit_id && empty($erros)) {
    $st = $pdo->prepare("SELECT * FROM unidades WHERE id=?");
    $st->execute([$edit_id]);
    $edit = $st->fetch() ?: null;
}

// ── Listagem ──
$unidades = $pdo->query("SELECT u.*, g.nome AS gestor_nome
    FROM unidades u
    LEFT JOIN usuarios g ON g.id = u.equipe_responsavel_id
    ORDER BY u.ativo DESC, u.nome")->fetchAll();

layoutHeader('Unidades / Filiais', 'unidades');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-geo-alt-fill me-2 text-primary"></i>Unidades / Filiais</h1>
</div>

<?php $flash = getFlash(); if ($flash): ?>
  <div class="alert alert-<?= $flash['tipo'] === 'danger' ? 'danger' : 'success' ?> alert-dismissible fade show mb-3">
    <?= h($flash['msg']) ?><button class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<?php if (!empty($erros)): ?>
  <div class="alert alert-danger mb-3"><ul class="mb-0 ps-3"><?php foreach ($erros as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<!-- ── Formulário ── -->
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-<?= $edit ? 'pencil-square' : 'plus-circle' ?> text-primary"></i>
    <?= $edit ? 'Editar — ' . h($edit['nome']) : 'Nova Unidade / Filial' ?>
    <?php if ($edit): ?>
      <a href="unidades.php" class="btn btn-sm btn-outline-secondary ms-auto">Cancelar</a>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <form method="post" novalidate>
      <?= csrfField() ?>
      <input type="hidden" name="_action" value="salvar">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= $edit['id'] ?>"><?php endif; ?>
      <div class="row g-3">
        <div class="col-md-5">
          <label class="form-label fw-semibold" style="font-size:13px">Nome <span class="text-danger">*</span></label>
          <input type="text" name="nome" class="form-control form-control-sm" value="<?= h($edit['nome'] ?? ($_POST['nome'] ?? '')) ?>" required maxlength="120" placeholder="Ex: Filial Porto Velho">
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold" style="font-size:13px">Código <span class="text-danger">*</span></label>
          <input type="text" name="codigo" class="form-control form-control-sm" style="text-transform:uppercase" value="<?= h($edit['codigo'] ?? ($_POST['codigo'] ?? '')) ?>" required maxlength="20" placeholder="PVH">
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold" style="font-size:13px">CEP</label>
          <input type="text" name="cep" id="cep_unidade"
                 class="form-control form-control-sm"
                 value="<?= h($edit['cep'] ?? ($_POST['cep'] ?? '')) ?>"
                 maxlength="9" placeholder="00000-000"
                 data-cep
                 data-cep-logradouro="[name='endereco']"
                 data-cep-bairro="[name='bairro']"
                 data-cep-cidade="[name='cidade']"
                 data-cep-uf="[name='uf']">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold" style="font-size:13px">Cidade</label>
          <input type="text" name="cidade" class="form-control form-control-sm"
                 value="<?= h($edit['cidade'] ?? ($_POST['cidade'] ?? '')) ?>"
                 maxlength="80" placeholder="Porto Velho"
                 data-cidade-uf="[name='uf']">
        </div>
        <div class="col-md-1">
          <label class="form-label fw-semibold" style="font-size:13px">UF</label>
          <input type="text" name="uf" class="form-control form-control-sm"
                 value="<?= h($edit['uf'] ?? ($_POST['uf'] ?? '')) ?>"
                 maxlength="2" placeholder="RO"
                 style="text-transform:uppercase">
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold" style="font-size:13px">Multiplicador SLA</label>
          <input type="text" name="sla_multiplicador" class="form-control form-control-sm" value="<?= h($edit['sla_multiplicador'] ?? ($_POST['sla_multiplicador'] ?? '1.00')) ?>" maxlength="6" placeholder="1.00">
          <div class="form-text" style="font-size:11px">1.00 = sem ajuste de prazo</div>
        </div>
        <div class="col-md-5">
          <label class="form-label fw-semibold" style="font-size:13px">Endereço (logradouro e número)</label>
          <input type="text" name="endereco" class="form-control form-control-sm" value="<?= h($edit['endereco'] ?? ($_POST['endereco'] ?? '')) ?>" maxlength="200" placeholder="Rua, número...">
        </div>
        <div class="col-md-5">
          <label class="form-label fw-semibold" style="font-size:13px">Bairro</label>
          <input type="text" name="bairro" class="form-control form-control-sm" value="<?= h($edit['bairro'] ?? ($_POST['bairro'] ?? '')) ?>" maxlength="100" placeholder="Centro">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold" style="font-size:13px">Telefone</label>
          <input type="text" name="telefone" class="form-control form-control-sm" value="<?= h($edit['telefone'] ?? ($_POST['telefone'] ?? '')) ?>" maxlength="30" placeholder="(92) 3000-0000">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold" style="font-size:13px">E-mail de Suporte</label>
          <input type="email" name="email_suporte" class="form-control form-control-sm" value="<?= h($edit['email_suporte'] ?? ($_POST['email_suporte'] ?? '')) ?>" maxlength="120" placeholder="ti@filial.com">
        </div>
        <div class="col-md-5">
          <label class="form-label fw-semibold" style="font-size:13px">Responsável / Gestor</label>
          <select name="equipe_responsavel_id" class="form-select form-select-sm">
            <option value="">— Nenhum —</option>
            <?php foreach ($gestoras as $g): ?>
              <option value="<?= $g['id'] ?>" <?= (($edit['equipe_responsavel_id'] ?? $_POST['equipe_responsavel_id'] ?? '') == $g['id']) ? 'selected' : '' ?>><?= h($g['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 d-flex align-items-end pb-1">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="ativo" id="ativo" value="1"
              <?= ($edit ? $edit['ativo'] : 1) ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="ativo" style="font-size:13px">Ativa</label>
          </div>
        </div>
        <div class="col-12 d-flex justify-content-end">
          <button type="submit" class="btn btn-primary btn-sm px-4">
            <i class="bi bi-check-lg me-1"></i><?= $edit ? 'Salvar alterações' : 'Cadastrar unidade' ?>
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Listagem ── -->
<div class="card shadow-sm">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-list-ul text-primary"></i> Unidades cadastradas
    <span class="badge bg-light text-dark border ms-1"><?= count($unidades) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" style="font-size:13px">
      <thead class="table-light">
        <tr>
          <th>Nome</th>
          <th>Código</th>
          <th>Cidade</th>
          <th class="text-center">SLA ×</th>
          <th>Responsável</th>
          <th class="text-center">Status</th>
          <th class="text-end">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$unidades): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma unidade cadastrada.</td></tr>
        <?php endif; ?>
        <?php foreach ($unidades as $un): ?>
        <tr class="<?= $un['ativo'] ? '' : 'opacity-50' ?>">
          <td class="fw-semibold">
            <i class="bi bi-geo-alt-fill text-primary me-1"></i><?= h($un['nome']) ?>
          </td>
          <td><span class="badge bg-light text-dark border"><?= h($un['codigo']) ?></span></td>
          <td class="text-muted"><?= h($un['cidade'] ?: '—') ?></td>
          <td class="text-center">
            <?php $sla = (float)$un['sla_multiplicador']; ?>
            <span class="badge <?= $sla == 1.0 ? 'bg-light text-dark border' : 'bg-warning text-dark' ?>">
              <?= number_format($sla, 2) ?>
            </span>
          </td>
          <td><?= h($un['gestor_nome'] ?: '—') ?></td>
          <td class="text-center">
            <span class="badge <?= $un['ativo'] ? 'bg-success' : 'bg-secondary' ?>">
              <?= $un['ativo'] ? 'Ativa' : 'Inativa' ?>
            </span>
          </td>
          <td class="text-end">
            <a href="unidades.php?editar=<?= $un['id'] ?>" class="btn btn-xs btn-outline-primary me-1" title="Editar">
              <i class="bi bi-pencil"></i>
            </a>
            <form method="post" class="d-inline" onsubmit="return confirm('<?= $un['ativo'] ? 'Desativar' : 'Ativar' ?> esta unidade?')">
              <?= csrfField() ?>
              <input type="hidden" name="_action" value="toggle_ativo">
              <input type="hidden" name="id" value="<?= $un['id'] ?>">
              <button type="submit" class="btn btn-xs <?= $un['ativo'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $un['ativo'] ? 'Desativar' : 'Ativar' ?>">
                <i class="bi bi-<?= $un['ativo'] ? 'toggle-on' : 'toggle-off' ?>"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layoutFooter(); ?>
