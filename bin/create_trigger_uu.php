<?php
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
define('HELPTI_BOOT', 1);
require_once __DIR__ . '/../config.php';
$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->exec("DROP TRIGGER IF EXISTS `trg_uu_unico_padrao`");
$pdo->exec("
CREATE TRIGGER `trg_uu_unico_padrao`
BEFORE INSERT ON `usuarios_unidades`
FOR EACH ROW
BEGIN
  IF NEW.eh_padrao = 1 THEN
    UPDATE `usuarios_unidades`
    SET `eh_padrao` = 0
    WHERE `usuario_id` = NEW.usuario_id AND `eh_padrao` = 1;
  END IF;
END
");
echo "Trigger trg_uu_unico_padrao criada com sucesso.\n";
