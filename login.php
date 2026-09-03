<?php
require 'db.php';
session();
if (usuario()) { header('Location: dashboard.php'); exit; }

$erro = '';
$ip   = $_SERVER['REMOTE_ADDR'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [SECURITY] CSRF — trata falha como erro de formulário em vez de die()
    $token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $erro = 'Sessão expirada. Tente novamente.';
    // [SECURITY] Rate limiting — bloqueia após 5 tentativas em 5 min
    } elseif (isLoginBlocked($ip)) {
        $erro = 'Muitas tentativas incorretas. Aguarde 5 minutos antes de tentar novamente.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';
        $pdo   = db();
        $st    = $pdo->prepare("SELECT * FROM usuarios WHERE email=? AND ativo=1");
        $st->execute([$email]);
        $u = $st->fetch();

        if ($u && password_verify($senha, $u['senha'])) {
            // [SECURITY] Previne session fixation
            session_regenerate_id(true);
            clearFailedLogins($ip);
            $_SESSION['usuario'] = [
                'id'     => $u['id'],
                'nome'   => $u['nome'],
                'email'  => $u['email'],
                'perfil' => $u['perfil'],
            ];
            header('Location: dashboard.php');
            exit;
        }

        recordFailedLogin($ip);
        $erro = 'E-mail ou senha incorretos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — <?= CLINICA_NOME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body{background:#F1FAEE;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:'Segoe UI',system-ui,sans-serif}
.login-card{background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.08);width:100%;max-width:360px;padding:2rem}
.login-icon{font-size:40px;color:#1D3557;text-align:center;margin-bottom:.5rem}
h1{font-size:17px;font-weight:700;text-align:center;margin-bottom:1.5rem;color:#1D3557}
.form-control{border-radius:8px;font-size:14px;padding:.65rem .9rem}
.form-control:focus{border-color:#457B9D;box-shadow:0 0 0 .2rem rgba(29,53,87,.15)}
.btn-login{width:100%;background:#1D3557;border:none;border-radius:8px;padding:.75rem;font-weight:700;font-size:14px;color:#fff}
.btn-login:hover{background:#457B9D}
.link-publico{display:block;text-align:center;margin-top:1.25rem;font-size:12.5px;color:#6b7280}
.link-publico a{color:#1D3557;text-decoration:none}
.link-publico a:hover{color:#457B9D}
.input-group .form-control{border-right:none}
.input-group .form-control:focus{box-shadow:none;border-color:#457B9D}
.input-group:focus-within .btn-toggle-senha{border-color:#457B9D}
.btn-toggle-senha{border-left:none;border-color:#ced4da;background:#fff;color:#6b7280;border-top-right-radius:8px!important;border-bottom-right-radius:8px!important}
.btn-toggle-senha:hover{background:#f8f9fa;color:#1D3557}
</style>
</head>
<body>
<div class="login-card">
  <div class="login-icon"><i class="bi bi-pc-display-horizontal"></i></div>
  <h1><?= APP_NOME ?></h1>
  <p style="text-align:center;font-size:11px;color:#999;margin-top:-12px;margin-bottom:16px">by <?= APP_VENDOR ?></p>
  <?php if ($erro): ?>
    <div class="alert alert-danger py-2 px-3 mb-3" style="font-size:13px"><?= h($erro) ?></div>
  <?php endif; ?>
  <form method="post">
    <?= csrfField() ?>
    <div class="mb-3">
      <label class="form-label fw-semibold" style="font-size:13px">E-mail</label>
      <input type="email" name="email" class="form-control" placeholder="seu@email.com" required autofocus autocomplete="email">
    </div>
    <div class="mb-3">
      <label class="form-label fw-semibold" style="font-size:13px">Senha</label>
      <div class="input-group">
        <input type="password" name="senha" id="senhaInput" class="form-control" placeholder="••••••••" required autocomplete="current-password">
        <button type="button" class="btn btn-outline-secondary btn-toggle-senha" id="btnToggleSenha" tabindex="-1" title="Mostrar senha">
          <i class="bi bi-eye" id="iconToggleSenha"></i>
        </button>
      </div>
    </div>
    <button type="submit" class="btn btn-login">Entrar</button>
  </form>
  <p class="link-publico">
    <a href="esqueci_senha.php">Esqueceu sua senha?</a>
    &nbsp;·&nbsp;
    <a href="abrir.php">Abrir chamado →</a>
  </p>
</div>
<script>
  document.getElementById('btnToggleSenha').addEventListener('click', function() {
    const input = document.getElementById('senhaInput');
    const icon  = document.getElementById('iconToggleSenha');
    const mostrando = input.type === 'text';
    input.type = mostrando ? 'password' : 'text';
    icon.className = mostrando ? 'bi bi-eye' : 'bi bi-eye-slash';
    this.title = mostrando ? 'Mostrar senha' : 'Ocultar senha';
  });
</script>
</body>
</html>
