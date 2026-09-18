<?php
declare(strict_types=1);

/** Rate limiting persistido em banco (tabela rate_limits). Fail-open. */
final class RateLimiter
{
    /** true = ação permitida; false = limite estourado na janela. */
    public static function allow(string $bucket, int $max, int $windowSeconds): bool
    {
        try {
            $pdo = db();
            $pdo->prepare("
                INSERT INTO rate_limits (bucket, tentativas, janela_inicio)
                VALUES (?, 1, NOW())
                ON DUPLICATE KEY UPDATE
                    tentativas    = IF(TIMESTAMPDIFF(SECOND, janela_inicio, NOW()) > ?, 1, tentativas + 1),
                    janela_inicio = IF(TIMESTAMPDIFF(SECOND, janela_inicio, NOW()) > ?, NOW(), janela_inicio)
            ")->execute([$bucket, $windowSeconds, $windowSeconds]);

            $r = $pdo->prepare("SELECT tentativas FROM rate_limits WHERE bucket = ?");
            $r->execute([$bucket]);
            return (int) $r->fetchColumn() <= $max;
        } catch (Throwable $e) {
            Log::warn('rate_limit_falhou', ['bucket' => $bucket, 'msg' => $e->getMessage()]);
            return true;
        }
    }
}
