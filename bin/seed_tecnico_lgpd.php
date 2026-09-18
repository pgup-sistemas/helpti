<?php
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
define('HELPTI_BOOT', 1);
require_once __DIR__ . '/../config.php';
$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
$pdo->prepare("INSERT IGNORE INTO usuarios (nome,email,senha,perfil,ativo) VALUES (?,?,?,?,1)")
    ->execute(['Tecnico LGPD','tecnico_lgpd@helpti.local', password_hash('tecnico123',PASSWORD_DEFAULT),'tecnico']);
echo "Usuário tecnico_lgpd@helpti.local criado (senha: tecnico123)\n";
