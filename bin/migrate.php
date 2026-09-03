<?php
/**
 * bin/migrate.php — Aplicador de migrations do HelpTI
 *
 * Uso:
 *   php bin/migrate.php            # aplica pendentes
 *   php bin/migrate.php --status   # lista estado
 *   php bin/migrate.php --dry-run  # mostra o que faria
 *
 * Cada arquivo database/migrations/NNNN_*.sql roda uma unica vez e fica
 * registrado em schema_migrations. Statements sao separados por ';' no fim da linha.
 * Erros idempotentes ("Duplicate column", "Duplicate key name", "already exists")
 * sao tratados como aviso, nao como falha.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require __DIR__ . '/../config.php';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    versao     varchar(120) NOT NULL PRIMARY KEY,
    aplicado_em datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$aplicadas = $pdo->query("SELECT versao FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
$arquivos  = glob(__DIR__ . '/../database/migrations/*.sql');
sort($arquivos);

$status  = in_array('--status', $argv, true);
$dryRun  = in_array('--dry-run', $argv, true);

$ignoraveis = ['duplicate column', 'duplicate key name', 'already exists',
               "check that column/key exists", 'multiple primary key'];

$pendentes = 0;
foreach ($arquivos as $arq) {
    $versao = basename($arq);
    $ja = in_array($versao, $aplicadas, true);

    if ($status) {
        printf("[%s] %s\n", $ja ? 'x' : ' ', $versao);
        continue;
    }
    if ($ja) continue;
    $pendentes++;

    echo ">> $versao\n";
    if ($dryRun) continue;

    $sql = file_get_contents($arq);
    // Remove comentarios de linha inteira e divide em statements
    $linhas = preg_split('/\r?\n/', $sql);
    $buffer = '';
    $statements = [];
    foreach ($linhas as $ln) {
        if (preg_match('/^\s*--/', $ln) || trim($ln) === '') continue;
        $buffer .= $ln . "\n";
        if (preg_match('/;\s*$/', $ln)) {
            $statements[] = rtrim(trim($buffer), ';');
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') $statements[] = rtrim(trim($buffer), ';');

    foreach ($statements as $st) {
        if ($st === '') continue;
        try {
            $pdo->exec($st);
        } catch (PDOException $e) {
            $msg = strtolower($e->getMessage());
            $ok = false;
            foreach ($ignoraveis as $ig) if (str_contains($msg, $ig)) { $ok = true; break; }
            if ($ok) {
                echo "   (aviso, ignorado) " . substr($e->getMessage(), 0, 120) . "\n";
            } else {
                fwrite(STDERR, "   ERRO em $versao:\n   " . $e->getMessage() . "\n   SQL: " . substr($st, 0, 200) . "\n");
                exit(1);
            }
        }
    }

    $pdo->prepare("INSERT INTO schema_migrations (versao) VALUES (?)")->execute([$versao]);
    echo "   aplicada.\n";
}

if (!$status) {
    echo $pendentes === 0 ? "Nada pendente.\n" : "$pendentes migration(s) aplicada(s).\n";
}
