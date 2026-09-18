<?php
declare(strict_types=1);

/** Log estruturado em JSON-lines, um arquivo por dia, fora do webroot. */
final class Log
{
    public static function write(string $nivel, string $evento, array $ctx = []): void
    {
        $dir = dirname(__DIR__) . '/logs';
        if (!is_dir($dir)) @mkdir($dir, 0770, true);

        $linha = json_encode([
            'ts'     => date('c'),
            'nivel'  => $nivel,
            'evento' => $evento,
            'ip'     => $_SERVER['REMOTE_ADDR'] ?? 'cli',
            'uri'    => $_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''),
            'ctx'    => $ctx,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $linha . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function error(string $evento, array $ctx = []): void { self::write('error', $evento, $ctx); }
    public static function warn(string $evento, array $ctx = []): void  { self::write('warn',  $evento, $ctx); }
    public static function info(string $evento, array $ctx = []): void  { self::write('info',  $evento, $ctx); }
}
