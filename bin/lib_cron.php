<?php
/**
 * bin/lib_cron.php — utilitarios compartilhados pelos jobs (cron)
 *
 *   cron_guard('nome')  → garante CLI + lock exclusivo (sai silencioso se ja rodando)
 *   cron_finish('nome', $ok, $msg) → registra heartbeat em cron_runs
 *
 * O lock e liberado automaticamente no fim do processo.
 */

function cron_guard(string $nome): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        exit("Este script so pode ser executado via linha de comando.\n");
    }

    $lockFile = sys_get_temp_dir() . '/helpti_' . preg_replace('/[^a-z0-9_]/i', '_', $nome) . '.lock';
    $fp = fopen($lockFile, 'c');
    if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "[$nome] ja existe uma execucao em andamento — abortando.\n");
        exit(0);
    }
    // mantem o handle vivo ate o fim do processo
    $GLOBALS['__cron_lock_' . $nome] = $fp;

    $GLOBALS['__cron_inicio_' . $nome] = microtime(true);
    try {
        $pdo = db();
        $pdo->prepare("INSERT INTO cron_runs (nome, iniciado_em, ok, mensagem)
                       VALUES (?, NOW(), NULL, 'em execucao')
                       ON DUPLICATE KEY UPDATE iniciado_em = NOW(), ok = NULL, mensagem = 'em execucao'")
            ->execute([$nome]);
    } catch (Throwable) { /* cron_runs pode nao existir em bancos muito antigos */ }
}

function cron_finish(string $nome, bool $ok = true, string $msg = ''): void
{
    $ini = $GLOBALS['__cron_inicio_' . $nome] ?? microtime(true);
    $dur = (int) round(microtime(true) - $ini);
    try {
        db()->prepare("UPDATE cron_runs
                       SET terminado_em = NOW(), ok = ?, mensagem = ?, duracao_seg = ?
                       WHERE nome = ?")
            ->execute([$ok ? 1 : 0, mb_substr($msg, 0, 255), $dur, $nome]);
    } catch (Throwable) { /* idem */ }
}
