<?php
require_once 'db.php';
require_once 'layout.php';
require_once 'estoque_helpers.php';
requireGestora();

$pdo = db();
$u = usuario();

// Download do modelo CSV — deve rodar antes de qualquer output HTML
if (isset($_GET['download_modelo'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="modelo_suprimentos.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['nome','estoque_minimo','estoque_atual','ativo'], ';');
    fputcsv($out, ['Toner Xerox C8030 Black','5','20','sim'], ';');
    fputcsv($out, ['Papel A4 (Resma)','20','80','sim'], ';');
    fputcsv($out, ['Bobina Térmica 80mm','10','0','sim'], ';');
    fclose($out);
    exit;
}

// Limpar sessão (botão Cancelar / Novo arquivo)
if (isset($_GET['limpar'])) {
    unset($_SESSION['import_sup_rows'], $_SESSION['import_sup_header']);
    header('Location: importar_tipos_suprimentos.php');
    exit;
}

$erro    = '';
$preview = [];
$colunas = [];
$linhas_ok  = 0;
$linhas_err = 0;

// ── Upload e preview ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo'])) {
    csrfVerify();
    $file = $_FILES['arquivo'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $erro = 'Erro no upload do arquivo.';
    } elseif (!in_array($file['type'], ['text/csv','text/plain','application/vnd.ms-excel','application/octet-stream'])) {
        $erro = 'Envie um arquivo CSV (.csv).';
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        // Remove BOM se existir
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        // Detecta separador: tenta ';' e ',' e usa o que produz mais colunas
        $linha_header = fgets($handle, 4000);
        if (!$linha_header) { $erro = 'Arquivo vazio ou sem cabeçalho.'; }
        else {
            $linha_header = ltrim($linha_header, "\xEF\xBB\xBF");
            $linha_header = rtrim($linha_header, "\r\n");
            $cols_sc  = str_getcsv($linha_header, ';');
            $cols_vir = str_getcsv($linha_header, ',');
            $sep = count($cols_sc) >= count($cols_vir) ? ';' : ',';
            $header = array_map(fn($h) => mb_strtolower(trim($h)), $sep === ';' ? $cols_sc : $cols_vir);

            if (count($header) < 1 || !in_array('nome', $header)) {
                $erro = 'Cabeçalho inválido. A coluna "nome" é obrigatória.';
            } else {
                // Nomes já cadastrados (case-insensitive) para detectar atualização vs. novo
                $existentes = $pdo->query("SELECT id, nome FROM tipos_suprimentos")->fetchAll(PDO::FETCH_ASSOC);
                $mapa_existentes = [];
                foreach ($existentes as $ex) $mapa_existentes[mb_strtolower(trim($ex['nome']))] = $ex['id'];

                $nomes_csv = [];
                $rows = [];

                while (($row = fgetcsv($handle, 2000, $sep)) !== false) {
                    if (array_filter($row) === []) continue;
                    $dados = [];
                    foreach ($header as $i => $col) {
                        $dados[$col] = trim($row[$i] ?? '');
                    }

                    $erros_linha  = [];
                    $avisos_linha = [];

                    $nome = trim($dados['nome'] ?? '');
                    if (!$nome) $erros_linha[] = 'nome vazio';

                    $nome_chave = mb_strtolower($nome);
                    if ($nome && in_array($nome_chave, $nomes_csv)) {
                        $erros_linha[] = 'nome duplicado no CSV';
                    } elseif ($nome) {
                        $nomes_csv[] = $nome_chave;
                    }

                    $emin_raw = $dados['estoque_minimo'] ?? '';
                    $eatl_raw = $dados['estoque_atual']  ?? '';
                    if ($emin_raw !== '' && !preg_match('/^\d+$/', $emin_raw)) $erros_linha[] = 'estoque_minimo deve ser um número inteiro ≥ 0';
                    if ($eatl_raw !== '' && !preg_match('/^\d+$/', $eatl_raw)) $erros_linha[] = 'estoque_atual deve ser um número inteiro ≥ 0';

                    $dados['estoque_minimo'] = $emin_raw !== '' ? (int)$emin_raw : 0;
                    $dados['estoque_atual']  = $eatl_raw !== '' ? (int)$eatl_raw : 0;

                    $ativo_raw = mb_strtolower(trim($dados['ativo'] ?? ''));
                    $dados['ativo'] = in_array($ativo_raw, ['nao', 'não', '0', 'inativo'], true) ? 0 : 1;

                    // Já existe? → linha vira atualização (entrada de estoque), não duplicata bloqueante
                    $existe_id = $mapa_existentes[$nome_chave] ?? null;
                    $dados['_existe_id'] = $existe_id;
                    if ($existe_id && !$erros_linha) {
                        $avisos_linha[] = $dados['estoque_atual'] > 0
                            ? "já cadastrado — será somada uma entrada de {$dados['estoque_atual']} unidade(s)"
                            : 'já cadastrado — apenas o estoque mínimo será atualizado';
                    }

                    $dados['_erros']  = $erros_linha;
                    $dados['_avisos'] = $avisos_linha;
                    $dados['_ok']     = empty($erros_linha);
                    $rows[] = $dados;
                }
                fclose($handle);

                $_SESSION['import_sup_rows']   = $rows;
                $_SESSION['import_sup_header'] = $header;
                $preview = $rows;
                $colunas = $header;
                $linhas_ok  = count(array_filter($rows, fn($r) => $r['_ok']));
                $linhas_err = count($rows) - $linhas_ok;
            }
        }
    }
}

// ── Confirmação e inserção ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirmar') {
    csrfVerify();
    $rows = $_SESSION['import_sup_rows'] ?? [];
    $criados     = 0;
    $atualizados = 0;
    $pulados     = 0;

    foreach ($rows as $r) {
        if (!$r['_ok']) { $pulados++; continue; }

        if (!empty($r['_existe_id'])) {
            // Já existe: atualiza mínimo e dá entrada no estoque informado
            $id = (int)$r['_existe_id'];
            $pdo->prepare("UPDATE tipos_suprimentos SET estoque_minimo = ? WHERE id = ?")
                ->execute([$r['estoque_minimo'], $id]);
            if ($r['estoque_atual'] > 0) {
                estoque_movimentar($pdo, $id, 'entrada', $r['estoque_atual'], 'Importação em massa (CSV)', null, $u['id'] ?? null);
            }
            $atualizados++;
        } else {
            // Novo insumo
            $pdo->prepare("INSERT INTO tipos_suprimentos (nome, estoque_minimo, estoque_atual, ativo) VALUES (?, ?, 0, ?)")
                ->execute([$r['nome'], $r['estoque_minimo'], $r['ativo']]);
            $novo_id = (int)$pdo->lastInsertId();
            if ($r['estoque_atual'] > 0) {
                estoque_movimentar($pdo, $novo_id, 'entrada', $r['estoque_atual'], 'Importação em massa (CSV) — cadastro inicial', null, $u['id'] ?? null);
            }
            $criados++;
        }
    }

    unset($_SESSION['import_sup_rows'], $_SESSION['import_sup_header']);
    flash("Importação concluída: {$criados} insumo(s) criado(s), {$atualizados} atualizado(s)" . ($pulados ? ", {$pulados} linha(s) ignorada(s) por erros." : '.'), 'success');
    header('Location: tipos_suprimentos.php');
    exit;
}

// Restaura preview da sessão se vier de volta
if (empty($preview) && !empty($_SESSION['import_sup_rows'])) {
    $preview = $_SESSION['import_sup_rows'];
    $colunas = $_SESSION['import_sup_header'];
    $linhas_ok  = count(array_filter($preview, fn($r) => $r['_ok']));
    $linhas_err = count($preview) - $linhas_ok;
}

layoutHeader('Importar Suprimentos', 'tipos_suprimentos');
?>

<?php breadcrumb([['label'=>'Tipos de Suprimentos','href'=>'tipos_suprimentos.php'],['label'=>'Importar em Massa']]); ?>
<div class="page-header">
  <h1 class="page-title"><i class="bi bi-upload me-2 text-primary"></i>Importar Suprimentos em Massa</h1>
</div>

<?php if ($erro): ?>
<div class="alert alert-danger"><?= h($erro) ?></div>
<?php endif; ?>

<!-- Instruções -->
<?php if (empty($preview)): ?>
<div class="row g-3 mb-4">
  <div class="col-md-7">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-file-earmark-arrow-up me-2"></i>Upload do arquivo CSV</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <?= csrfField() ?>
          <div class="mb-3">
            <label class="form-label fw-semibold">Arquivo CSV</label>
            <input type="file" name="arquivo" class="form-control" accept=".csv,.txt" required>
            <div class="form-text">Separador: <code>;</code> (ponto-e-vírgula) · Encoding: UTF-8 · Primeira linha = cabeçalho</div>
          </div>
          <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Analisar arquivo</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-info-circle me-2"></i>Colunas aceitas no CSV</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Coluna</th><th>Obrig.</th><th>Exemplo</th></tr></thead>
          <tbody style="font-size:12px">
            <tr><td><code>nome</code></td><td><span class="text-danger fw-bold">✓</span></td><td>Toner Xerox C8030 Black</td></tr>
            <tr><td><code>estoque_minimo</code></td><td></td><td>5</td></tr>
            <tr><td><code>estoque_atual</code></td><td></td><td>20</td></tr>
            <tr><td><code>ativo</code></td><td></td><td>sim / não (padrão: sim)</td></tr>
          </tbody>
        </table>
      </div>
      <div class="card-footer text-muted" style="font-size:11px">
        Se o <strong>nome já existir</strong> no cadastro (comparação sem diferenciar maiúsculas), a linha vira uma <strong>entrada de estoque</strong> somada ao insumo existente — não cria duplicado.
      </div>
    </div>
  </div>
</div>

<!-- Modelo para download -->
<div class="card">
  <div class="card-body d-flex align-items-center gap-3">
    <i class="bi bi-file-earmark-spreadsheet text-success" style="font-size:28px"></i>
    <div>
      <div class="fw-semibold">Baixar modelo CSV</div>
      <div class="text-muted" style="font-size:12px">Use como base para preencher seus insumos</div>
    </div>
    <a href="?download_modelo=1" class="btn btn-outline-success btn-sm ms-auto"><i class="bi bi-download me-1"></i>Baixar modelo.csv</a>
  </div>
</div>

<?php else: ?>

<!-- Preview da importação -->
<div class="card mb-3">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span><i class="bi bi-table me-2"></i>Pré-visualização — <?= count($preview) ?> linha(s)</span>
    <div class="d-flex gap-2">
      <?php
        $linhas_atualiza = count(array_filter($preview, fn($r) => $r['_ok'] && !empty($r['_existe_id'])));
        $linhas_novas    = $linhas_ok - $linhas_atualiza;
      ?>
      <?php if ($linhas_novas > 0): ?><span class="badge bg-success fs-6"><?= $linhas_novas ?> novo(s)</span><?php endif; ?>
      <?php if ($linhas_atualiza > 0): ?><span class="badge bg-info text-dark fs-6"><?= $linhas_atualiza ?> atualização(ões)</span><?php endif; ?>
      <?php if ($linhas_err > 0): ?><span class="badge bg-danger fs-6"><?= $linhas_err ?> com erros</span><?php endif; ?>
    </div>
  </div>
  <div class="table-responsive" style="max-height:420px;overflow-y:auto">
    <table class="table table-sm table-hover mb-0" style="font-size:12px">
      <thead style="position:sticky;top:0;z-index:1">
        <tr>
          <th style="width:36px">#</th>
          <th>Status</th>
          <th>Nome</th>
          <th>Estoque mínimo</th>
          <th>Entrada (estoque_atual)</th>
          <th>Ativo</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($preview as $i => $r): ?>
        <tr class="<?= $r['_ok'] ? (!empty($r['_existe_id']) ? 'table-info' : '') : 'table-danger' ?>">
          <td class="text-muted"><?= $i+1 ?></td>
          <td>
            <?php if ($r['_ok'] && empty($r['_existe_id'])): ?>
              <i class="bi bi-check-circle-fill text-success" title="Novo insumo"></i>
            <?php elseif ($r['_ok']): ?>
              <span class="text-info-emphasis" title="<?= h(implode(', ', $r['_avisos'])) ?>">
                <i class="bi bi-arrow-repeat"></i> Atualização
              </span>
            <?php else: ?>
              <span class="text-danger" title="<?= h(implode(', ', $r['_erros'])) ?>">
                <i class="bi bi-exclamation-circle-fill"></i> <?= h(implode(', ', $r['_erros'])) ?>
              </span>
            <?php endif; ?>
          </td>
          <td><?= h($r['nome']) ?></td>
          <td><?= (int)$r['estoque_minimo'] ?></td>
          <td><?= (int)$r['estoque_atual'] ?></td>
          <td><?= $r['ativo'] ? 'Sim' : 'Não' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="d-flex gap-2">
  <?php if ($linhas_ok > 0): ?>
  <form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="confirmar">
    <button class="btn btn-success fw-semibold" onclick="return confirm('Processar <?= $linhas_ok ?> linha(s) — criando novos insumos e somando entradas de estoque nos existentes?')">
      <i class="bi bi-check-lg me-1"></i>Confirmar e importar <?= $linhas_ok ?> item(ns)
      <?php if ($linhas_err > 0): ?>
        <small class="opacity-75">(<?= $linhas_err ?> serão ignorados por erro)</small>
      <?php endif; ?>
    </button>
  </form>
  <?php endif; ?>
  <a href="importar_tipos_suprimentos.php?limpar=1" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1"></i>Cancelar / Novo arquivo</a>
</div>

<?php endif; ?>

<?php layoutFooter(); ?>
