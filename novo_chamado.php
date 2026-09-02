<?php
require 'db.php';
requireLogin();
require 'layout.php';
require_once 'sync_inventario.php';

$pdo        = db();
$u          = usuario();
$tecnicos   = $pdo->query("SELECT id,nome FROM usuarios WHERE ativo=1 AND perfil IN ('tecnico','admin') ORDER BY nome")->fetchAll();
$categorias = $pdo->query("SELECT id,nome,icone FROM categorias WHERE ativo=1 ORDER BY nome")->fetchAll();
$erros      = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $nome      = trim($_POST['solicitante'] ?? '');
    $setor     = trim($_POST['setor'] ?? '');
    $desc      = trim($_POST['descricao'] ?? '');
    $resp      = $_POST['responsavel_id'] ?: null;
    $nivel     = $_POST['nivel'];
    $status    = $_POST['status'];
    $origem    = $_POST['origem'];
    $cat_id    = (int)($_POST['categoria_id'] ?? 0) ?: null;

    if (!$nome)  $erros[] = 'Informe o solicitante.';
    if (!$setor) $erros[] = 'Selecione o setor.';
    if (!$desc)  $erros[] = 'Descreva o problema.';

    $inv_id = (int)($_POST['inventario_id'] ?? 0) ?: null;

    // Upload de imagens
    $uploaded_paths = [];
    if (!$erros && !empty($_FILES['imagens']['name'][0])) {
        $files = $_FILES['imagens'];
        $count = count($files['name']);
        if ($count > 5) {
            $erros[] = 'Máximo 5 imagens por chamado.';
        } else {
            $allowed_mimes = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            if (!is_dir('uploads')) mkdir('uploads', 0755, true);
            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                if ($files['error'][$i] !== UPLOAD_ERR_OK)      { $erros[] = "Erro ao enviar imagem " . ($i+1) . "."; continue; }
                if ($files['size'][$i] > 5*1024*1024)            { $erros[] = "Imagem ".h($files['name'][$i])." excede 5 MB."; continue; }
                $real_mime = $finfo->file($files['tmp_name'][$i]);
                if (!array_key_exists($real_mime, $allowed_mimes)) { $erros[] = "Tipo inválido: ".h($files['name'][$i])."."; continue; }
                $ext  = $allowed_mimes[$real_mime];
                $dest = 'uploads/img_' . bin2hex(random_bytes(16)) . '.' . $ext;
                if (move_uploaded_file($files['tmp_name'][$i], $dest)) $uploaded_paths[] = $dest;
                else $erros[] = "Não foi possível salvar ".h($files['name'][$i]).".";
            }
            if ($erros) { foreach ($uploaded_paths as $p) if (file_exists($p)) unlink($p); $uploaded_paths = []; }
        }
    }

    if (!$erros) {
        $numero       = gerarNumero();
        $semana       = getSemana(date('Y-m-d'));
        $imagens_json = !empty($uploaded_paths) ? json_encode($uploaded_paths) : null;
        $pdo->prepare("INSERT INTO chamados (numero,descricao,setor,solicitante,responsavel_id,nivel,categoria_id,status,semana,origem,inventario_id,imagens)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$numero,$desc,$setor,$nome,$resp,$nivel,$cat_id,$status,$semana,$origem,$inv_id,$imagens_json]);

        $cid = $pdo->lastInsertId();

        // Notificar técnico atribuído ou toda a equipe se sem responsável
        if ($resp) {
            $tec = $pdo->prepare("SELECT email FROM usuarios WHERE id=?");
            $tec->execute([$resp]);
            $emailTec = $tec->fetchColumn();
            notificarChamado('atribuido', ['id'=>$cid,'numero'=>$numero,'setor'=>$setor,'descricao'=>$desc,'solicitante'=>$nome], $emailTec ?: null);
        } else {
            $emails_ti = $pdo->query("SELECT email FROM usuarios WHERE ativo=1 AND perfil IN ('tecnico','admin','gestora')")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($emails_ti as $eti) {
                notificarChamado('aberto', ['id'=>$cid,'numero'=>$numero,'setor'=>$setor,'descricao'=>$desc,'solicitante'=>$nome], $eti);
            }
        }
        $pdo->prepare("INSERT INTO historico (chamado_id,usuario_id,acao) VALUES (?,?,?)")
            ->execute([$cid,$u['id'],"Chamado aberto manualmente via painel"]);

        // Sincroniza status do equipamento vinculado ao abrir chamado
        if ($inv_id) {
            sync_inventario_status_chamado($cid, $status);
        }

        flash("Chamado $numero criado com sucesso.");
        header("Location: chamado.php?id=$cid"); exit;
    }
}

layoutHeader('Novo Chamado', 'novo');
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-plus-circle-fill me-2 text-primary"></i>Novo Chamado</h1>
</div>

<div class="card">
  <div class="card-header"><i class="bi bi-keyboard me-2 text-primary"></i>Registrar chamado manualmente</div>
  <div class="card-body">
    <?php if ($erros): ?>
      <div class="alert alert-danger py-2" style="font-size:13px"><?= implode('<br>',array_map('h',$erros)) ?></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <?= csrfField() ?>
      <div class="row g-3">

        <!-- Linha 1: Categoria + Solicitante -->
        <div class="col-12">
          <label class="form-label fw-semibold" style="font-size:13px">Categoria</label>
          <select name="categoria_id" class="form-select form-select-sm">
            <option value="">— Selecione —</option>
            <?php foreach($categorias as $cat): ?>
              <option value="<?= $cat['id'] ?>" <?= (($_POST['categoria_id']??'')==$cat['id'])?'selected':'' ?>>
                <?= h($cat['nome']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold" style="font-size:13px">Solicitante</label>
          <input type="text" name="solicitante" class="form-control form-control-sm" value="<?= h($_POST['solicitante']??'') ?>" required>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold" style="font-size:13px">Setor</label>
          <select name="setor" class="form-select form-select-sm" required>
            <option value="">— Selecione —</option>
            <?php foreach($SETORES as $s): ?>
              <option value="<?= h($s) ?>" <?= (($_POST['setor']??'')===$s)?'selected':'' ?>><?= h($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold" style="font-size:13px">Equipamento relacionado <span class="text-muted fw-normal">(opcional)</span></label>
          <select name="inventario_id" id="selectEquipamento" class="form-select form-select-sm">
            <option value="">— Nenhum —</option>
            <?php
            $equips_todos = listar_equipamentos_chamado();
            foreach ($equips_todos as $eq):
                $label = h($eq['tipo']) . ' – ' . h(trim($eq['marca'] . ' ' . $eq['modelo']));
                if ($eq['numero_serie']) $label .= ' [' . h($eq['numero_serie']) . ']';
                $selected = (($_POST['inventario_id'] ?? '') == $eq['id']) ? 'selected' : '';
                $data_setor = h($eq['setor']);
            ?>
              <option value="<?= $eq['id'] ?>" data-setor="<?= $data_setor ?>" <?= $selected ?>><?= $label ?> — <?= h($eq['setor']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Ao selecionar um setor, a lista filtra automaticamente.</div>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold" style="font-size:13px">Descrição do problema</label>
          <textarea name="descricao" class="form-control form-control-sm" rows="5" required><?= h($_POST['descricao']??'') ?></textarea>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold" style="font-size:13px">Imagens / Anexos <span class="text-muted fw-normal">(opcional — até 5 arquivos, máx. 5 MB cada)</span></label>
          <div id="dropZone" class="drop-zone" onclick="document.getElementById('inputImagens').click()" ondragover="event.preventDefault();this.style.borderColor='#0ea5e9'" ondragleave="this.style.borderColor=''" ondrop="handleDrop(event)">
            <i class="bi bi-cloud-upload drop-zone-icon"></i>
            <span class="drop-zone-label">Clique para selecionar ou arraste imagens aqui</span><br>
            <span class="drop-zone-hint">JPG, PNG, GIF, WEBP</span>
          </div>
          <input type="file" id="inputImagens" name="imagens[]" multiple accept="image/*" style="display:none" onchange="previewImagens(this.files)">
          <div id="previewGrid" style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.5rem"></div>
        </div>

        <!-- Linha final: Responsável | Nível | Origem | Status -->
        <div class="col-sm-6 col-md-3">
          <label class="form-label fw-semibold" style="font-size:13px">Responsável</label>
          <select name="responsavel_id" class="form-select form-select-sm">
            <option value="">Sem atribuição</option>
            <?php foreach($tecnicos as $t): ?>
              <option value="<?= $t['id'] ?>" <?= (($_POST['responsavel_id']??'')==$t['id'])?'selected':'' ?>><?= h($t['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6 col-md-3">
          <label class="form-label fw-semibold" style="font-size:13px">Nível</label>
          <select name="nivel" class="form-select form-select-sm">
            <?php foreach(['A Definir','Baixa Complexidade','Média Complexidade','Alta Complexidade'] as $n): ?>
              <option><?= $n ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6 col-md-3">
          <label class="form-label fw-semibold" style="font-size:13px">Origem</label>
          <select name="origem" class="form-select form-select-sm">
            <?php foreach(['Formulário Web','WhatsApp','Telefone','Presencial'] as $o): ?>
              <option><?= $o ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6 col-md-3">
          <label class="form-label fw-semibold" style="font-size:13px">Status inicial</label>
          <select name="status" class="form-select form-select-sm">
            <?php foreach(['Aberto','Em Andamento','Concluído'] as $s): ?>
              <option><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Criar chamado</button>
        <a href="chamados.php" class="btn btn-outline-secondary btn-sm">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<script>
function previewImagens(files) {
  const grid = document.getElementById('previewGrid');
  grid.innerHTML = '';
  const arr = Array.from(files).slice(0, 5);
  arr.forEach(file => {
    const reader = new FileReader();
    reader.onload = e => {
      const wrap = document.createElement('div');
      wrap.style.cssText = 'position:relative;width:80px;height:80px';
      const img = document.createElement('img');
      img.src = e.target.result;
      img.style.cssText = 'width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0';
      const label = document.createElement('div');
      label.textContent = file.name.length > 10 ? file.name.slice(0,10)+'…' : file.name;
      label.style.cssText = 'font-size:9px;color:#64748b;text-align:center;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:80px';
      wrap.appendChild(img);
      const outer = document.createElement('div');
      outer.appendChild(wrap);
      outer.appendChild(label);
      grid.appendChild(outer);
    };
    reader.readAsDataURL(file);
  });
  document.getElementById('dropZone').style.borderColor = arr.length ? '#10b981' : '#cbd5e1';
}

function handleDrop(e) {
  e.preventDefault();
  document.getElementById('dropZone').style.borderColor = '#cbd5e1';
  const input = document.getElementById('inputImagens');
  const dt = new DataTransfer();
  Array.from(e.dataTransfer.files).forEach(f => dt.items.add(f));
  input.files = dt.files;
  previewImagens(input.files);
}

// Filtra dropdown de equipamento pelo setor selecionado
document.querySelector('select[name="setor"]').addEventListener('change', function() {
    const setor = this.value;
    const sel = document.getElementById('selectEquipamento');
    Array.from(sel.options).forEach(opt => {
        if (!opt.value) { opt.style.display = ''; return; }
        opt.style.display = (!setor || opt.dataset.setor === setor) ? '' : 'none';
    });
    // Limpa seleção se o equipamento atual não é do setor
    if (sel.value && sel.options[sel.selectedIndex]?.dataset.setor !== setor) {
        sel.value = '';
    }
});
</script>
<?php layoutFooter(); ?>
