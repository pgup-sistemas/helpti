<?php
declare(strict_types=1);

/**
 * src/bootstrap.php — camada de aplicação do HelpTI.
 *
 * Introduzida incrementalmente sobre o monólito procedural: as classes aqui
 * concentram a lógica de domínio; db.php mantém as funções globais como
 * fachada fina que delega para estas classes (compatibilidade com o código
 * existente). Código novo deve usar as classes diretamente.
 */

if (!defined('HELPTI_BOOT')) { http_response_code(403); exit('Acesso negado.'); }

spl_autoload_register(static function (string $class): void {
    // Sem namespace — mapeia NomeDaClasse -> src/NomeDaClasse.php
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class)) return;
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) require $file;
});
