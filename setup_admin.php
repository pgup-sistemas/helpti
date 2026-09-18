<?php
/**
 * setup_admin.php — Cria o primeiro usuário administrador via browser
 *
 * USO:
 *   1. Certifique-se de que as migrations já foram aplicadas (install.php).
 *   2. Acesse: https://helpti.pageup.net.br/setup_admin.php?token=SEU_TOKEN
 *   3. Preencha o formulário e clique em "Criar administrador".
 *   4. APAGUE este arquivo do servidor imediatamente após o uso.
 *
 * SEGURANÇA:
 *   - Só funciona se não existir nenhum usuário com perfil 'admin' no banco.
 *   - Requer INSTALL_TOKEN definido em config.local.php.
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

// ── Verifica se já existe admin ────────────────────────────────────────────
$adminExiste = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil='admin' AND ativo=1")->fetchColumn();

$erro    = '';
$sucesso = false;

// ── Processar formulário ───────────────────────────────────────────────────
if (!$adminExiste && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar') {
    $nome  = trim($_POST['nome']  ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $conf  = $_POST['conf']  ?? '';

    if (!$nome || !$email || !$senha) {
        $erro = 'Preencha todos os campos.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'E-mail inválido.';
    } elseif (strlen($senha) < 8) {
        $erro = 'A senha deve ter pelo menos 8 caracteres.';
    } elseif ($senha !== $conf) {
        $erro = 'As senhas não coincidem.';
    } else {
        $existe = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $existe->execute([$email]);
        if ($existe->fetch()) {
            $erro = 'Já existe um usuário com este e-mail.';
        } else {
            $hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare("INSERT INTO usuarios (nome, email, senha, perfil, ativo, criado_em)
                           VALUES (?, ?, ?, 'admin', 1, NOW())")
                ->execute([$nome, $email, $hash]);
            $sucesso = true;
        }
    }
}

// Reler flag após possível INSERT
if ($sucesso) {
    $adminExiste = 1;
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HelpTI — Setup Admin</title>
<style>
  *{box-sizing:border-box}
  body{font:14px/1.6 system-ui,sans-serif;background:#F1FAEE;color:#1D3557;margin:0;padding:24px}
  .wrap{max-width:480px;margin:0 auto}
  h1{font-size:20px;margin:0 0 4px}
  .sub{font-size:13px;color:#457B9D;margin-bottom:24px}
  .warn{background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;padding:12px 16px;font-size:13px;color:#92400E;margin-bottom:20px}
  .warn strong{display:block;margin-bottom:2px}
  .card{background:#fff;border:1px solid #E5E9F2;border-radius:12px;padding:24px;margin-bottom:16px}
  .form-group{margin-bottom:16px}
  label{display:block;font-size:13px;font-weight:600;color:#5A6472;margin-bottom:5px}
  input[type=text],input[type=email],input[type=password]{
    width:100%;padding:9px 12px;border:1px solid #CBD5E1;border-radius:7px;
    font-size:13.5px;color:#1D3557;outline:none;transition:.15s
  }
  input:focus{border-color:#457B9D;box-shadow:0 0 0 3px rgba(69,123,157,.15)}
  .btn{display:block;width:100%;background:#1D3557;color:#fff;border:none;border-radius:8px;
       padding:11px;font-size:14px;font-weight:600;cursor:pointer;margin-top:8px}
  .btn:hover{background:#2A4A73}
  .erro{background:#FEE2E2;border:1px solid #FCA5A5;border-radius:8px;padding:10px 14px;
        color:#B91C1C;font-size:13px;margin-bottom:14px}
  .success-box{background:#DCFCE7;border:1px solid #86EFAC;border-radius:8px;
               padding:16px 20px;color:#166534;font-size:13px}
  .success-box strong{display:block;font-size:15px;margin-bottom:6px}
  .hint{font-size:11px;color:#94A3B8;margin-top:3px}
  .already{background:#DBEAFE;border:1px solid #BFDBFE;border-radius:8px;
            padding:14px 18px;color:#1E40AF;font-size:13px}
  code{background:#F1F5F9;border:1px solid #E5E9F2;border-radius:4px;padding:1px 5px;font-size:12px}
</style>
</head>
<body>
<div class="wrap">

  <h1>👤 HelpTI — Criar Administrador</h1>
  <div class="sub">Crie o primeiro acesso ao painel de gestão.</div>

  <div class="warn">
    <strong>⚠️ Atenção: script sensível</strong>
    Após criar o administrador, delete este arquivo do servidor:<br>
    <code>public_html/setup_admin.php</code>
  </div>

  <?php if ($sucesso): ?>
  <div class="card">
    <div class="success-box">
      <strong>✅ Administrador criado com sucesso!</strong>
      Agora você pode acessar o painel:<br><br>
      <a href="login.php" style="color:#166534;font-weight:600">→ Ir para login.php</a><br><br>
      <strong>⚠️ Delete este arquivo agora:</strong><br>
      <code>public_html/setup_admin.php</code><br>
      <code>public_html/install.php</code>
    </div>
  </div>

  <?php elseif ($adminExiste): ?>
  <div class="card">
    <div class="already">
      ✅ Já existe um administrador no banco de dados.<br><br>
      Este script está desabilitado por segurança.<br><br>
      <a href="login.php" style="color:#1E40AF;font-weight:600">→ Ir para login.php</a><br><br>
      <strong>Delete este arquivo:</strong> <code>public_html/setup_admin.php</code>
    </div>
  </div>

  <?php else: ?>
  <div class="card">
    <?php if ($erro): ?>
    <div class="erro">⚠️ <?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <input type="hidden" name="acao" value="criar">

      <div class="form-group">
        <label for="nome">Nome completo</label>
        <input type="text" id="nome" name="nome" required placeholder="Ex: João da Silva"
               value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" required placeholder="admin@pageup.net.br"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        <div class="hint">Será o login de acesso ao painel.</div>
      </div>

      <div class="form-group">
        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" required placeholder="Mínimo 8 caracteres" autocomplete="new-password">
      </div>

      <div class="form-group">
        <label for="conf">Confirmar senha</label>
        <input type="password" id="conf" name="conf" required placeholder="Repita a senha" autocomplete="new-password">
      </div>

      <button type="submit" class="btn">Criar administrador</button>
    </form>
  </div>
  <?php endif; ?>

  <p style="font-size:11px;color:#94A3B8;text-align:center">
    Após usar este script, delete <code>install.php</code> e <code>setup_admin.php</code> do servidor.
  </p>

</div>
</body>
</html>
