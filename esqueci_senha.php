<?php
// ============================================================
//  esqueci_senha.php — Solicitação de recuperação de senha
// ============================================================
require 'db.php';
session();
if (usuario()) { header('Location: dashboard.php'); exit; }

$msg   = '';
$erro  = '';
$pdo   = db();

// Cria tabela de resets se não existir
$pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    email     VARCHAR(100) NOT NULL,
    token     VARCHAR(64)  NOT NULL UNIQUE,
    criado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usado     TINYINT      NOT NULL DEFAULT 0,
    INDEX idx_token (token),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $email = strtolower(trim($_POST['email'] ?? ''));

    $st = $pdo->prepare("SELECT id, nome FROM usuarios WHERE email=? AND ativo=1");
    $st->execute([$email]);
    $u = $st->fetch();

    if ($u) {
        // Invalida tokens antigos
        $pdo->prepare("DELETE FROM password_resets WHERE email=?")->execute([$email]);

        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO password_resets (email, token) VALUES (?,?)")
            ->execute([$email, $token]);

        $link  = APP_URL . '/resetar_senha.php?token=' . $token;
        $nome  = $u['nome'];
        $corpo = "<html><body style='font-family:Segoe UI,sans-serif;font-size:14px;color:#222'>"
               . "<div style='max-width:480px;margin:0 auto;border:1px solid #e5e9f2;border-radius:10px;overflow:hidden'>"
               . "<div style='background:#1D3557;padding:18px 24px'><span style='color:#fff;font-weight:700;font-size:16px'>" . APP_NOME . "</span><span style='color:#A8DADC;font-size:12px;margin-left:8px'>by " . APP_VENDOR . "</span></div>"
               . "<div style='padding:24px'>"
               . "<h3 style='color:#1D3557;margin-top:0'>Redefinição de senha</h3>"
               . "<p>Olá, <strong>{$nome}</strong>!</p>"
               . "<p>Clique no botão abaixo para criar uma nova senha. O link é válido por <strong>1 hora</strong>.</p>"
               . "<p><a href='{$link}' style='background:#1D3557;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600'>Redefinir minha senha</a></p>"
               . "<p style='font-size:12px;color:#999;margin-top:20px'>Se você não solicitou isso, ignore este e-mail.</p>"
               . "</div></div></body></html>";

        queueEmail($email, 'Redefinição de senha — ' . APP_NOME, $corpo);
    }

    // Mesma mensagem para não revelar se e-mail existe
    $msg = 'Se esse e-mail estiver cadastrado, você receberá um link em instantes.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recuperar Senha — <?= CLINICA_NOME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body{background:#F1FAEE;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:'Segoe UI',system-ui,sans-serif}
.card-box{background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.08);width:100%;max-width:360px;padding:2rem}
.icon{font-size:40px;color:#1D3557;text-align:center;margin-bottom:.5rem}
h1{font-size:17px;font-weight:700;text-align:center;margin-bottom:.35rem;color:#1D3557}
.sub{text-align:center;font-size:13px;color:#6b7280;margin-bottom:1.5rem}
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
  <div class="icon"><i class="bi bi-key-fill"></i></div>
  <h1>Recuperar Senha</h1>
  <p class="sub">Informe seu e-mail para receber o link de redefinição.</p>
  <?php if ($msg): ?>
    <div class="alert alert-success py-2 px-3" style="font-size:13px"><?= h($msg) ?></div>
  <?php endif; ?>
  <?php if (!$msg): ?>
  <form method="post">
    <?= csrfField() ?>
    <div class="mb-3">
      <label class="form-label fw-semibold" style="font-size:13px">E-mail</label>
      <input type="email" name="email" class="form-control" placeholder="seu@email.com" required autofocus autocomplete="email">
    </div>
    <button type="submit" class="btn-main">Enviar link</button>
  </form>
  <?php endif; ?>
  <a href="login.php" class="back"><i class="bi bi-arrow-left me-1"></i>Voltar ao login</a>
</div>
</body>
</html>
