<?php
declare(strict_types=1);
/**
 * HelpTI — Funções compartilhadas entre impressoras.php e relatorio_impressoras.php
 * Evita duplicação e mantém as faixas de toner/status consistentes nas duas telas.
 */
if (!defined('HELPTI_BOOT')) { http_response_code(403); exit('Acesso negado.'); }

/** Normaliza valor numérico vindo do banco (string|int|null) para ?int. */
function _num(int|string|null $v): ?int {
    return ($v === null || $v === '') ? null : (int) $v;
}

// ── Badge de status da impressora (Ativa / Em Manutenção / Inativa) ──
function badgeStatusImpressora(string $s): string {
    $map = [
        'Ativa'          => 'badge-concluido',
        'Em Manutenção'  => 'badge-andamento',
        'Inativa'        => 'badge-pendente',
    ];
    $cls = $map[$s] ?? 'bg-secondary text-white';
    return "<span class=\"badge $cls\">" . h($s) . "</span>";
}

// ── Badge de nível de toner. $compact reduz o tamanho para uso em tabelas densas ──
function tonerBadge(int|string|null $pct, string $label = '', bool $compact = false): string {
    $pct = _num($pct);
    if ($pct === null) return $compact ? '' : '<span class="text-muted">—</span>';
    $cls = $pct <= 15 ? 'bg-danger' : ($pct <= 30 ? 'bg-warning text-dark' : 'bg-success');
    $style = $compact ? " style='font-size:10px'" : '';
    $l = $label ? "<span style='font-size:9px;opacity:.8'>{$label}</span> " : '';
    return "<span class='badge {$cls}'{$style}>{$l}{$pct}%</span>" . ($compact ? ' ' : '');
}

// ── Badge de variação percentual mês a mês ──
function variacaoBadge(int|string|null $atual, int|string|null $ant): string {
    $atual = _num($atual); $ant = _num($ant);
    if ($atual === null || $ant === null || $ant === 0) return '<span class="text-muted">—</span>';
    $delta = $atual - $ant;
    $pct   = round(($delta / $ant) * 100);
    if ($delta > 0) return "<span class='text-danger small fw-semibold'>+{$pct}%</span>";
    if ($delta < 0) return "<span class='text-success small fw-semibold'>{$pct}%</span>";
    return "<span class='text-muted small'>0%</span>";
}

/**
 * Último snapshot SNMP de cada impressora, indexado por impressora_id.
 * Lê da tabela materializada impressoras_ultimo_snapshot (P3-2).
 */
function snmp_ultimo_snapshot(PDO $pdo, array $impressora_ids): array {
    if (!$impressora_ids) return [];
    $ids = implode(',', array_map('intval', $impressora_ids));

    try {
        $snaps = $pdo->query("
            SELECT impressora_id, toner_preto_pct, toner_ciano_pct,
                   toner_magenta_pct, toner_amarelo_pct, paginas_total, coletado_em
            FROM impressoras_ultimo_snapshot
            WHERE impressora_id IN ($ids)
        ")->fetchAll();
    } catch (Throwable) {
        return []; // tabela ainda não migrada
    }

    $map = [];
    foreach ($snaps as $sn) $map[$sn['impressora_id']] = $sn;
    return $map;
}
