<?php
/**
 * install.php — Aplicador de migrations via browser (fallback sem SSH)
 *
 * USO:
 *   1. Defina INSTALL_TOKEN em config.local.php (gere com: openssl rand -hex 20)
 *   2. Acesse: https://helpti.pageup.net.br/install.php?token=SEU_TOKEN
 *   3. Clique em "Aplicar migrations"
 *   4. APAGUE este arquivo do servidor imediatamente após o uso.
 *
 * SEGURANÇA: sem o token correto o script retorna 404.
 */

define('HELPTI_BOOT', 1);
require __DIR__ . '/config.php';

// ── Proteção por token ──────────────────────────────────────────────────────
if (!defined('INSTALL_TOKEN') || !INSTALL_TOKEN) {
    http_response_code(404); exit;
}
$token = $_GET['token'] ?? $_POST['token'] ?? '';
if (!hash_equals(INSTALL_TOKEN, $token)) {
    http_response_code(404); exit;
}

// ── Conexão ────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>Falha na conexão com o banco: ' . htmlspecialchars($e->getMessage()) . '</pre>'; exit;
}

// ── Garante tabela de controle ─────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    versao      varchar(120) NOT NULL PRIMARY KEY,
    aplicado_em datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$aplicadas = $pdo->query("SELECT versao FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
$arquivos  = glob(__DIR__ . '/database/migrations/*.sql') ?: [];
sort($arquivos);

$ignoraveis = [
    'duplicate column', 'duplicate key name', 'already exists',
    'check that column/key exists', 'multiple primary key',
    'duplicate key on write or update', 'duplicate foreign key constraint name',
    "can't write; duplicate key",   // errno 1022 MariaDB Locaweb
    'errno: 121',                   // duplicate FK constraint name MariaDB
];

// ── Ação: aplicar ──────────────────────────────────────────────────────────
$log = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'aplicar') {
    foreach ($arquivos as $arq) {
        $versao = basename($arq);
        if (in_array($versao, $aplicadas, true)) continue;

        $sql    = file_get_contents($arq);
        $linhas = preg_split('/\r?\n/', $sql);
        $buffer = '';
        $stmts  = [];
        foreach ($linhas as $ln) {
            if (preg_match('/^\s*--/', $ln) || trim($ln) === '') continue;
            $buffer .= $ln . "\n";
            if (preg_match('/;\s*$/', $ln)) {
                $stmts[] = rtrim(trim($buffer), ';');
                $buffer  = '';
            }
        }
        if (trim($buffer) !== '') $stmts[] = rtrim(trim($buffer), ';');

        $erroFatal = null;
        foreach ($stmts as $st) {
            if (trim($st) === '') continue;
            try {
                $pdo->exec($st);
            } catch (PDOException $e) {
                $msg = strtolower($e->getMessage());
                $ignorar = false;
                foreach ($ignoraveis as $ig) {
                    if (str_contains($msg, $ig)) { $ignorar = true; break; }
                }
                if (!$ignorar) { $erroFatal = $e->getMessage(); break; }
            }
        }

        if ($erroFatal) {
            $log[] = ['tipo' => 'erro', 'versao' => $versao, 'msg' => $erroFatal];
            break;
        }

        $pdo->prepare("INSERT INTO schema_migrations (versao) VALUES (?)")->execute([$versao]);
        $log[] = ['tipo' => 'ok', 'versao' => $versao];
        $aplicadas[] = $versao;
    }
}

// ── Estado atual ───────────────────────────────────────────────────────────
$aplicadas = $pdo->query("SELECT versao FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
$pendentes = array_filter($arquivos, fn($a) => !in_array(basename($a), $aplicadas, true));
$totalOk   = count($aplicadas);
$totalPend = count($pendentes);

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HelpTI — Instalação</title>
<style>
  *{box-sizing:border-box}
  body{font:14px/1.6 system-ui,sans-serif;background:#F1FAEE;color:#1D3557;margin:0;padding:24px}
  .wrap{max-width:720px;margin:0 auto}
  h1{font-size:20px;margin:0 0 4px}
  .sub{font-size:13px;color:#457B9D;margin-bottom:24px}
  .card{background:#fff;border:1px solid #E5E9F2;border-radius:12px;padding:20px 24px;margin-bottom:16px}
  .warn{background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;padding:12px 16px;font-size:13px;color:#92400E;margin-bottom:20px}
  .warn strong{display:block;margin-bottom:2px}
  .stat{display:flex;gap:16px;margin-bottom:16px}
  .st{flex:1;background:#F8FAFC;border:1px solid #E5E9F2;border-radius:8px;padding:12px;text-align:center}
  .st-n{font-size:28px;font-weight:700;line-height:1}
  .st-l{font-size:11px;color:#6C757D;margin-top:4px;text-transform:uppercase;letter-spacing:.04em}
  .n-ok{color:#15803D}.n-pend{color:#B45309}.n-total{color:#1D3557}
  table{width:100%;border-collapse:collapse;font-size:13px}
  th{text-align:left;padding:7px 10px;border-bottom:2px solid #E5E9F2;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6C757D}
  td{padding:7px 10px;border-bottom:1px solid #F1F5F9}
  .badge{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px;border:1px solid}
  .b-ok{color:#15803D;background:#DCFCE7;border-color:#86EFAC}
  .b-pend{color:#B45309;background:#FEF3C7;border-color:#FDE68A}
  .btn{display:inline-block;background:#1D3557;color:#fff;border:none;border-radius:8px;padding:10px 22px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none}
  .btn:hover{background:#2A4A73}
  .btn-sm{padding:6px 14px;font-size:12px;background:#457B9D}
  .log-ok{color:#15803D;font-size:13px;padding:4px 0}
  .log-err{color:#B91C1C;font-size:13px;padding:4px 0}
  pre{margin:4px 0 0;font-size:11px;background:#FEE2E2;padding:6px 10px;border-radius:4px;white-space:pre-wrap;word-break:break-all}
  .success-box{background:#DCFCE7;border:1px solid #86EFAC;border-radius:8px;padding:14px 18px;color:#166534;font-size:13px;margin-top:8px}
</style>
</head>
<body>
<div class="wrap">

  <h1>🚀 HelpTI — Instalação / Migrations</h1>
  <div class="sub">Execute este script uma única vez no servidor de produção e <strong>delete-o em seguida</strong>.</div>

  <div class="warn">
    <strong>⚠️ Atenção: script sensível</strong>
    Após concluir todas as migrations, delete este arquivo do servidor:<br>
    <code>public_html/install.php</code>
  </div>

  <!-- Resultado da ação -->
  <?php if ($log): ?>
  <div class="card">
    <strong style="font-size:14px">Resultado da execução</strong>
    <div style="margin-top:10px">
      <?php foreach ($log as $l): ?>
        <?php if ($l['tipo'] === 'ok'): ?>
          <div class="log-ok">✅ <?= htmlspecialchars($l['versao']) ?> — aplicada</div>
        <?php else: ?>
          <div class="log-err">❌ <?= htmlspecialchars($l['versao']) ?> — ERRO<pre><?= htmlspecialchars($l['msg']) ?></pre></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Estatísticas -->
  <div class="card">
    <div class="stat">
      <div class="st"><div class="st-n n-total"><?= count($arquivos) ?></div><div class="st-l">Total</div></div>
      <div class="st"><div class="st-n n-ok"><?= $totalOk ?></div><div class="st-l">Aplicadas</div></div>
      <div class="st"><div class="st-n n-pend"><?= $totalPend ?></div><div class="st-l">Pendentes</div></div>
    </div>

    <?php if ($totalPend === 0): ?>
      <div class="success-box">✅ Todas as migrations estão aplicadas. Banco de dados pronto!</div>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="acao" value="aplicar">
        <button type="submit" class="btn" onclick="return confirm('Aplicar <?= $totalPend ?> migration(s) pendente(s)?')">
          Aplicar <?= $totalPend ?> migration(s) pendente(s)
        </button>
      </form>
    <?php endif; ?>
  </div>

  <!-- Lista de migrations -->
  <div class="card">
    <strong style="font-size:13px;display:block;margin-bottom:10px">Todas as migrations</strong>
    <table>
      <thead><tr><th>Arquivo</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($arquivos as $arq):
            $v = basename($arq);
            $ok = in_array($v, $aplicadas, true);
        ?>
        <tr>
          <td><?= htmlspecialchars($v) ?></td>
          <td><span class="badge <?= $ok ? 'b-ok' : 'b-pend' ?>"><?= $ok ? 'Aplicada' : 'Pendente' ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p style="font-size:12px;color:#6C757D;margin-top:8px">
    Próximo passo: <a href="setup_admin.php?token=<?= htmlspecialchars($token) ?>">criar usuário admin →</a>
  </p>

</div>
</body>
</html>
