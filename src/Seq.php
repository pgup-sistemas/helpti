<?php
declare(strict_types=1);

/** Numeração sequencial atômica por conexão (LAST_INSERT_ID). */
final class Seq
{
    /** Ex.: Seq::next('chamados', 'CHM') -> "CHM-2026-00042" */
    public static function next(string $sequencia, string $prefixo): string
    {
        $pdo = db();
        $n = $pdo->prepare("UPDATE sequences SET value = LAST_INSERT_ID(value + 1) WHERE name = ?");
        $n->execute([$sequencia]);
        if ($n->rowCount() === 0) {
            $pdo->prepare("INSERT IGNORE INTO sequences (name, value) VALUES (?, 0)")->execute([$sequencia]);
            $pdo->prepare("UPDATE sequences SET value = LAST_INSERT_ID(value + 1) WHERE name = ?")->execute([$sequencia]);
        }
        $seq = (int) $pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();
        return $prefixo . '-' . date('Y') . '-' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
