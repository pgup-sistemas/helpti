<?php
// ============================================================
//  resetar_senha.php — Redefinição de senha via token
// ============================================================
require 'db.php';
session();
if (usuario()) { header('Location: dashboard.php'); exit; }

$pdo   = db();
$token = trim($_GET['token'] ?? '');
$erro  = '';
$ok    = false;

// Valida token
$st = $pdo->prepare("SELECT * FROM password_resets WHERE token=? AND usado=0 AND criado_em >= NOW() - INTERVAL 1 HOUR");
$st->execute([$token]);
$reset = $st->fetch();

if (!$token || !$reset) {
    $erro = 'Link inválido ou expirado. Solicite um novo link de recuperação.';
}

if (!$erro && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $nova  = $_POST['senha']     ?? '';
    $conf  = $_POST['confirmar'] ?? '';

    if (strlen($nova) < 8) {
        $erro = 'A senha deve ter pelo menos 8 caracteres.';
    } elseif ($nova !== $conf) {
        $erro = 'As senhas não coincidem.';
    } else {
        $hash = password_hash($nova, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE usuarios SET senha=? WHERE email=?")->execute([$hash, $reset['email']]);
        $pdo->prepare("UPDATE password_resets SET usado=1 WHERE token=?")->execute([$token]);
        $ok = true;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nova Senha — <?= CLINICA_NOME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body{background:#F1FAEE;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:'Segoe UI',system-ui,sans-serif}
.card-box{background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.08);width:100%;max-width:360px;padding:2rem}
.icon{font-size:40px;color:#1D3557;text-align:center;margin-bottom:.5rem}
h1{font-size:17px;font-weight:700;text-align:center;margin-bottom:1.5rem;color:#1D3557}
.form-control{border-radius:8px;font-size:14px;padding:.65rem .9rem}
.form-control:focus{border-color:#457B9D;box-shadow:0 0 0 .2rem rgba(29,53,87,.15)}
.btn-main{width:100%;background:#1D3557;border:none;border-radius:8px;padding:.75rem;font-weight:700;font-size:14px;color:#fff}
.btn-main:hover{background:#457B9D}
.back{display:block;text-align:center;margin-top:1rem;font-size:13px;color:#1D3557;text-decoration:none}
.back:hover{color:#457B9D}
</style>
</head>
<body>
<div class="card-box">
  <div class="icon"><i class="bi bi-shield-lock-fill"></i></div>
  <h1>Definir Nova Senha</h1>

  <?php if ($ok): ?>
    <div class="alert alert-success text-center py-3">
      <i class="bi bi-check-circle-fill me-2"></i>Senha redefinida com sucesso!
    </div>
    <a href="login.php" class="btn-main d-block text-center text-decoration-none mt-3">Ir para o login</a>

  <?php elseif ($erro && !$reset): ?>
    <div class="alert alert-danger py-2 px-3" style="font-size:13px"><?= h($erro) ?></div>
    <a href="esqueci_senha.php" class="back">Solicitar novo link</a>

  <?php else: ?>
    <?php if ($erro): ?>
      <div class="alert alert-danger py-2 px-3 mb-3" style="font-size:13px"><?= h($erro) ?></div>
    <?php endif; ?>
    <form method="post">
      <?= csrfField() ?>
      <div class="mb-3">
        <label class="form-label fw-semibold" style="font-size:13px">Nova senha</label>
        <input type="password" name="senha" class="form-control" placeholder="Mínimo 8 caracteres" required minlength="8" autocomplete="new-password">
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold" style="font-size:13px">Confirmar senha</label>
        <input type="password" name="confirmar" class="form-control" placeholder="Repita a nova senha" required minlength="8" autocomplete="new-password">
      </div>
      <button type="submit" class="btn-main">Salvar nova senha</button>
    </form>
    <a href="login.php" class="back"><i class="bi bi-arrow-left me-1"></i>Voltar ao login</a>
  <?php endif; ?>
</div>
</body>
</html>
