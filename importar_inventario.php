<?php
require_once 'db.php';
require_once 'layout.php';
require_once 'sync_inventario.php';
requireGestora();

// Download do modelo CSV — deve rodar antes de qualquer output HTML
if (isset($_GET['download_modelo'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="modelo_inventario.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['tipo','marca','modelo','numero_serie','patrimonio','setor','responsavel_nome','status','data_aquisicao','valor','garantia_ate','imei','observacoes'], ';');
    fputcsv($out, ['Notebook','Dell','Latitude 5520','SN123456','PAT-001','Financeiro','João Silva','Em Uso','2024-01-15','4500,00','2027-01-15','',''], ';');
    fputcsv($out, ['Desktop','HP','ProDesk 400','SN789012','PAT-002','RH','Maria Costa','Disponível','2023-06-10','2800,00','2026-06-10','',''], ';');
    fclose($out);
    exit;
}

// Limpar sessão (botão Cancelar / Novo arquivo)
if (isset($_GET['limpar'])) {
    unset($_SESSION['import_rows'], $_SESSION['import_header']);
    header('Location: importar_inventario.php');
    exit;
}

$erro    = '';
$preview = [];
$colunas = [];
$linhas_ok  = 0;
$linhas_err = 0;

// Colunas aceitas no CSV (mapeamento)
$colunas_inventario = ['tipo','marca','modelo','numero_serie','patrimonio','setor',
    'responsavel_nome','status','data_aquisicao','valor','garantia_ate','imei','observacoes'];
$status_validos = ['Disponível','Em Uso','Manutenção','Descartado','Emprestado'];

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
            $linha_header = ltrim($linha_header, "\xEF\xBB\xBF"); // remove BOM
            $linha_header = rtrim($linha_header, "\r\n");
            $cols_sc  = str_getcsv($linha_header, ';');
            $cols_vir = str_getcsv($linha_header, ',');
            $sep = count($cols_sc) >= count($cols_vir) ? ';' : ',';
            $header = array_map(fn($h) => mb_strtolower(trim($h)), $sep === ';' ? $cols_sc : $cols_vir);
        if (count($header) < 2) { $erro = 'Cabeçalho inválido. Verifique o arquivo CSV.'; }
        else {

            // Carrega séries e patrimônios já cadastrados para detectar duplicatas
            $series_db = db()->query("SELECT numero_serie FROM inventario WHERE numero_serie != '' AND numero_serie IS NOT NULL")
                             ->fetchAll(PDO::FETCH_COLUMN);
            $patrim_db = db()->query("SELECT patrimonio FROM inventario WHERE patrimonio != '' AND patrimonio IS NOT NULL")
                             ->fetchAll(PDO::FETCH_COLUMN);
            $series_db = array_map('strtolower', $series_db);
            $patrim_db = array_map('strtolower', $patrim_db);

            // Carrega MACs já cadastrados (extraídos das observações ou coluna dedicada futura)
            $obs_db = db()->query("SELECT observacoes FROM inventario WHERE observacoes IS NOT NULL AND observacoes != ''")->fetchAll(PDO::FETCH_COLUMN);
            $macs_db = [];
            foreach ($obs_db as $obs) {
                if (preg_match('/MAC:\s*([0-9A-Fa-f:]{17})/', $obs, $m)) {
                    $macs_db[] = strtoupper($m[1]);
                }
            }

            // Detecta duplicatas dentro do próprio CSV
            $series_csv = [];
            $patrim_csv = [];
            $macs_csv   = [];

            $rows = [];
            while (($row = fgetcsv($handle, 2000, $sep)) !== false) {
                if (array_filter($row) === []) continue;
                $dados = [];
                foreach ($header as $i => $col) {
                    $dados[$col] = trim($row[$i] ?? '');
                }
                // Fallbacks do scanner
                if (empty($dados['marca']) && !empty($dados['fabricante'])) $dados['marca'] = $dados['fabricante'];
                if (empty($dados['tipo']))  $dados['tipo']  = 'Computador';
                if (empty($dados['marca'])) $dados['marca'] = 'Desconhecido';

                // Verificação de duplicatas
                $erros_linha = [];
                $avisos_linha = [];

                $serie  = strtolower(trim($dados['numero_serie'] ?? ''));
                $patrim = strtolower(trim($dados['patrimonio']   ?? ''));

                if ($serie) {
                    if (in_array($serie, $series_db)) {
                        $avisos_linha[] = 'série já cadastrada no sistema';
                        $erros_linha[]  = 'série já cadastrada no sistema';
                    } elseif (in_array($serie, $series_csv)) {
                        $avisos_linha[] = 'série duplicada no CSV';
                        $erros_linha[]  = 'série duplicada no CSV';
                    } else {
                        $series_csv[] = $serie;
                    }
                }
                if ($patrim) {
                    if (in_array($patrim, $patrim_db)) {
                        $avisos_linha[] = 'patrimônio já cadastrado no sistema';
                        $erros_linha[]  = 'patrimônio já cadastrado no sistema';
                    } elseif (in_array($patrim, $patrim_csv)) {
                        $avisos_linha[] = 'patrimônio duplicado no CSV';
                        $erros_linha[]  = 'patrimônio duplicado no CSV';
                    } else {
                        $patrim_csv[] = $patrim;
                    }
                }

                // Deduplicação por MAC (coluna 'mac' do scanner)
                $mac = strtoupper(trim($dados['mac'] ?? ''));
                if ($mac && $mac !== '') {
                    if (in_array($mac, $macs_db)) {
                        $avisos_linha[] = 'MAC já cadastrado no inventário';
                        $erros_linha[]  = 'MAC já cadastrado no inventário';
                    } elseif (in_array($mac, $macs_csv)) {
                        $avisos_linha[] = 'MAC duplicado no CSV';
                        $erros_linha[]  = 'MAC duplicado no CSV';
                    } else {
                        $macs_csv[] = $mac;
                    }
                }

                if (!empty($dados['status']) && !in_array($dados['status'], $status_validos)) {
                    $erros_linha[] = 'status inválido';
                }
                if (!empty($dados['data_aquisicao']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dados['data_aquisicao'])) {
                    $erros_linha[] = 'data_aquisicao deve ser AAAA-MM-DD';
                }
                if (!empty($dados['garantia_ate']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dados['garantia_ate'])) {
                    $erros_linha[] = 'garantia_ate deve ser AAAA-MM-DD';
                }

                $dados['_erros']  = $erros_linha;
                $dados['_avisos'] = $avisos_linha;
                $dados['_ok']     = empty($erros_linha);
                $rows[] = $dados;
            }
            fclose($handle);

            // Salva na sessão para confirmação
            $_SESSION['import_rows']   = $rows;
            $_SESSION['import_header'] = $header;
            $preview = $rows;
            $colunas = $header;
            $linhas_ok  = count(array_filter($rows, fn($r) => $r['_ok']));
            $linhas_err = count($rows) - $linhas_ok;
        }} // fecha: if count($header) < 2 else + if !$linha_header else
    }
}

// ── Confirmação e inserção ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirmar') {
    csrfVerify();
    $rows   = $_SESSION['import_rows']   ?? [];
    $header = $_SESSION['import_header'] ?? [];
    $inseridos = 0;
    $pulados   = 0;

    foreach ($rows as $r) {
        if (!$r['_ok']) { $pulados++; continue; }

        $status = $r['status'] ?: 'Disponível';
        $data_aq  = $r['data_aquisicao'] ?: null;
        $gar_ate  = $r['garantia_ate']   ?: null;
        $valor    = !empty($r['valor']) ? (float)str_replace(['.',',' ],['','.'], $r['valor']) : null;
        $obs = $r['observacoes'] ?? '';
        // Appenda dados de rede se houver colunas extras
        if (!empty($r['ip_detectado']))  $obs .= ($obs?' | ':'').'IP: '.$r['ip_detectado'];
        if (!empty($r['mac']))           $obs .= ' MAC: '.$r['mac'];
        if (!empty($r['hostname']))      $obs .= ' Host: '.$r['hostname'];

        db()->prepare("
            INSERT INTO inventario (tipo,marca,modelo,numero_serie,patrimonio,setor,responsavel_nome,status,data_aquisicao,valor,garantia_ate,imei,observacoes,criado_em)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ")->execute([
            $r['tipo'],
            $r['marca'],
            $r['modelo']           ?? '',
            $r['numero_serie']     ?? '',
            $r['patrimonio']       ?? '',
            $r['setor']            ?? '',
            $r['responsavel_nome'] ?? '',
            $status,
            $data_aq,
            $valor,
            $gar_ate,
            $r['imei']             ?? '',
            $obs,
        ]);
        $inseridos++;
    }

    // Sincroniza impressoras automaticamente após importação
    $sync = sync_impressoras_from_inventario();
    $sync_msg = '';
    if ($sync['criadas'] > 0 || $sync['atualizadas'] > 0) {
        $sync_msg = " | Impressoras: {$sync['criadas']} criada(s), {$sync['atualizadas']} atualizada(s).";
    }

    unset($_SESSION['import_rows'], $_SESSION['import_header']);
    flash("Importação concluída: $inseridos equipamento(s) inserido(s)" . ($pulados ? ", $pulados linha(s) ignorada(s) por erros." : '.') . $sync_msg, 'success');
    header('Location: inventario.php');
    exit;
}

// Restaura preview da sessão se vier de volta
if (empty($preview) && !empty($_SESSION['import_rows'])) {
    $preview = $_SESSION['import_rows'];
    $colunas = $_SESSION['import_header'];
    $linhas_ok  = count(array_filter($preview, fn($r) => $r['_ok']));
    $linhas_err = count($preview) - $linhas_ok;
}

layoutHeader('Importar Inventário', 'inventario');
?>

<?php breadcrumb([['label'=>'Inventário','href'=>'inventario.php'],['label'=>'Importar via CSV']]); ?>
<div class="page-header">
  <h1 class="page-title"><i class="bi bi-upload me-2 text-primary"></i>Importar Inventário via CSV</h1>
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
            <tr><td><code>tipo</code></td><td><span class="text-danger fw-bold">✓</span></td><td>Notebook</td></tr>
            <tr><td><code>marca</code></td><td><span class="text-danger fw-bold">✓</span></td><td>Dell</td></tr>
            <tr><td><code>modelo</code></td><td></td><td>Latitude 5520</td></tr>
            <tr><td><code>numero_serie</code></td><td></td><td>SN123456</td></tr>
            <tr><td><code>patrimonio</code></td><td></td><td>PAT-001</td></tr>
            <tr><td><code>setor</code></td><td></td><td>Financeiro</td></tr>
            <tr><td><code>responsavel_nome</code></td><td></td><td>João Silva</td></tr>
            <tr><td><code>status</code></td><td></td><td>Disponível / Em Uso / Manutenção / Descartado / Emprestado</td></tr>
            <tr><td><code>data_aquisicao</code></td><td></td><td>2024-03-15</td></tr>
            <tr><td><code>valor</code></td><td></td><td>4500,00</td></tr>
            <tr><td><code>garantia_ate</code></td><td></td><td>2027-03-15</td></tr>
            <tr><td><code>imei</code></td><td></td><td>(para celulares)</td></tr>
            <tr><td><code>observacoes</code></td><td></td><td>Texto livre</td></tr>
          </tbody>
        </table>
      </div>
      <div class="card-footer text-muted" style="font-size:11px">
        Colunas extras do scanner (<code>ip_detectado</code>, <code>mac</code>, <code>hostname</code>) são adicionadas automaticamente às observações.
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
      <div class="text-muted" style="font-size:12px">Use como base para preencher seus equipamentos</div>
    </div>
    <a href="?download_modelo=1" class="btn btn-outline-success btn-sm ms-auto"><i class="bi bi-download me-1"></i>Baixar modelo.csv</a>
  </div>
</div>

<?php else: ?>

<!-- Preview da importação -->
<div class="card mb-3">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span><i class="bi bi-table me-2"></i>Pré-visualização — <?= count($preview) ?> linha(s)</span>
    <?php
      $linhas_dup = count(array_filter($preview, function($r) {
          if ($r['_ok']) return false;
          foreach ($r['_erros'] as $e) {
              if (!str_contains($e,'duplicad') && !str_contains($e,'já cadastrad')) return false;
          }
          return !empty($r['_erros']);
      }));
      $linhas_erro_real = $linhas_err - $linhas_dup;
    ?>
    <div class="d-flex gap-2">
      <?php if ($linhas_ok > 0): ?>
      <span class="badge bg-success fs-6"><?= $linhas_ok ?> OK</span>
      <?php endif; ?>
      <?php if ($linhas_dup > 0): ?>
      <span class="badge bg-warning text-dark fs-6"><?= $linhas_dup ?> duplicado(s)</span>
      <?php endif; ?>
      <?php if ($linhas_erro_real > 0): ?>
      <span class="badge bg-danger fs-6"><?= $linhas_erro_real ?> com erros</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="table-responsive" style="max-height:420px;overflow-y:auto">
    <table class="table table-sm table-hover mb-0" style="font-size:12px">
      <thead style="position:sticky;top:0;z-index:1">
        <tr>
          <th style="width:36px">#</th>
          <th>Status</th>
          <?php foreach (array_intersect($colunas, array_merge($colunas_inventario,['ip_detectado','hostname','mac'])) as $c): ?>
          <th><?= h($c) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($preview as $i => $r):
          $is_dup = !$r['_ok'] && !empty($r['_erros']) && (function() use ($r) {
            foreach ($r['_erros'] as $e) {
              if (!str_contains($e,'duplicad') && !str_contains($e,'já cadastrad')) return false;
            }
            return true;
          })();
        ?>
        <tr class="<?= $r['_ok'] ? '' : ($is_dup ? 'table-warning' : 'table-danger') ?>">
          <td class="text-muted"><?= $i+1 ?></td>
          <td>
            <?php if ($r['_ok']): ?>
              <i class="bi bi-check-circle-fill text-success" title="OK"></i>
            <?php elseif ($is_dup): ?>
              <span class="text-warning-emphasis" title="<?= h(implode(', ', $r['_erros'])) ?>">
                <i class="bi bi-exclamation-triangle-fill"></i> <?= h(implode(', ', $r['_erros'])) ?>
              </span>
            <?php else: ?>
              <span class="text-danger" title="<?= h(implode(', ', $r['_erros'])) ?>">
                <i class="bi bi-exclamation-circle-fill"></i> <?= h(implode(', ', $r['_erros'])) ?>
              </span>
            <?php endif; ?>
          </td>
          <?php foreach (array_intersect($colunas, array_merge($colunas_inventario,['ip_detectado','hostname','mac'])) as $c): ?>
          <td><?= h($r[$c] ?? '') ?></td>
          <?php endforeach; ?>
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
    <button class="btn btn-success fw-semibold" onclick="return confirm('Inserir <?= $linhas_ok ?> equipamento(s) no inventário?')">
      <i class="bi bi-check-lg me-1"></i>Confirmar e importar <?= $linhas_ok ?> item(ns)
      <?php if ($linhas_dup > 0 || $linhas_erro_real > 0): ?>
        <small class="opacity-75">(<?= $linhas_err ?> serão ignorados<?= $linhas_dup > 0 ? ": $linhas_dup duplicado(s)" : '' ?>)</small>
      <?php endif; ?>
    </button>
  </form>
  <?php endif; ?>
  <a href="importar_inventario.php?limpar=1" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1"></i>Cancelar / Novo arquivo</a>
</div>

<?php endif; ?>

<?php layoutFooter(); ?>
