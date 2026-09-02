<?php
/**
 * HelpTI — Funções compartilhadas entre impressoras.php e relatorio_impressoras.php
 * Evita duplicação e mantém as faixas de toner/status consistentes nas duas telas.
 */

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
function tonerBadge(?int $pct, string $label = '', bool $compact = false): string {
    if ($pct === null) return $compact ? '' : '<span class="text-muted">—</span>';
    $cls = $pct <= 15 ? 'bg-danger' : ($pct <= 30 ? 'bg-warning text-dark' : 'bg-success');
    $style = $compact ? " style='font-size:10px'" : '';
    $l = $label ? "<span style='font-size:9px;opacity:.8'>{$label}</span> " : '';
    return "<span class='badge {$cls}'{$style}>{$l}{$pct}%</span>" . ($compact ? ' ' : '');
}

// ── Badge de variação percentual mês a mês ──
function variacaoBadge(?int $atual, ?int $ant): string {
    if ($atual === null || $ant === null || $ant === 0) return '<span class="text-muted">—</span>';
    $delta = $atual - $ant;
    $pct   = round(($delta / $ant) * 100);
    if ($delta > 0) return "<span class='text-danger small fw-semibold'>+{$pct}%</span>";
    if ($delta < 0) return "<span class='text-success small fw-semibold'>{$pct}%</span>";
    return "<span class='text-muted small'>0%</span>";
}

/**
 * Busca o snapshot SNMP mais recente de cada impressora informada.
 * Retorna array indexado por impressora_id.
 */
function snmp_ultimo_snapshot(PDO $pdo, array $impressora_ids): array {
    if (!$impressora_ids) return [];
    $tem_snap = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'impressoras_snapshot'"
    )->fetchColumn();
    if (!$tem_snap) return [];

    $ids = implode(',', array_map('intval', $impressora_ids));
    $snaps = $pdo->query("
        SELECT s.impressora_id,
               s.toner_preto_pct, s.toner_ciano_pct,
               s.toner_magenta_pct, s.toner_amarelo_pct,
               s.paginas_total, s.coletado_em
        FROM impressoras_snapshot s
        INNER JOIN (
            SELECT impressora_id, MAX(coletado_em) AS max_dt
            FROM impressoras_snapshot
            WHERE impressora_id IN ($ids)
            GROUP BY impressora_id
        ) m ON m.impressora_id = s.impressora_id AND m.max_dt = s.coletado_em
    ")->fetchAll();

    $map = [];
    foreach ($snaps as $sn) $map[$sn['impressora_id']] = $sn;
    return $map;
}
