-- ============================================================
-- 0004_avaliacoes_unique_e_reconciliacao.sql
--  - Garante UNIQUE(chamado_id) em avaliacoes (P1-7)
--  - Reconcilia estoque_atual x estoque_movimentos com 1 linha rotulada. (P2-9)
-- ============================================================

DELETE a FROM avaliacoes a
JOIN (
    SELECT MIN(id) AS keep_id, chamado_id
    FROM avaliacoes GROUP BY chamado_id HAVING COUNT(*) > 1
) d ON d.chamado_id = a.chamado_id AND a.id <> d.keep_id;

ALTER TABLE `avaliacoes` ADD UNIQUE KEY `uk_chamado` (`chamado_id`);

-- delta = estoque_atual - saldo_reconstruido ; tipo dado pelo sinal do delta
INSERT INTO estoque_movimentos (tipo_suprimento_id, tipo, quantidade, motivo, usuario_id, criado_em)
SELECT t.id,
       IF(t.estoque_atual - saldo.v >= 0, 'entrada', 'saida'),
       ABS(t.estoque_atual - saldo.v),
       'Ajuste de reconciliacao inicial (migration 0004)',
       NULL, NOW()
FROM tipos_suprimentos t
JOIN LATERAL (
    SELECT COALESCE(SUM(CASE tipo WHEN 'entrada' THEN quantidade
                                  WHEN 'saida'   THEN -quantidade
                                  WHEN 'ajuste'  THEN quantidade END), 0) AS v
    FROM estoque_movimentos m WHERE m.tipo_suprimento_id = t.id
) saldo ON 1=1
WHERE t.estoque_atual <> saldo.v;
