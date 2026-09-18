-- ============================================================
-- 0007_snapshot_materializado_e_fulltext.sql
--  P3-2: tabela materializada com o ÚLTIMO snapshot de cada impressora
--        (elimina subquery correlacionada em notificacoes/impressora/relatorio)
--  P3-3: índices FULLTEXT para a busca global
-- ============================================================

CREATE TABLE IF NOT EXISTS `impressoras_ultimo_snapshot` (
  `impressora_id`     int NOT NULL,
  `coletado_em`       datetime NOT NULL,
  `paginas_total`     int DEFAULT NULL,
  `toner_preto_pct`   tinyint DEFAULT NULL,
  `toner_ciano_pct`   tinyint DEFAULT NULL,
  `toner_magenta_pct` tinyint DEFAULT NULL,
  `toner_amarelo_pct` tinyint DEFAULT NULL,
  PRIMARY KEY (`impressora_id`),
  KEY `idx_coletado` (`coletado_em`),
  CONSTRAINT `fk_ultsnap_impressora` FOREIGN KEY (`impressora_id`)
    REFERENCES `impressoras` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill a partir do histórico existente (linha mais recente por impressora)
INSERT INTO impressoras_ultimo_snapshot
    (impressora_id, coletado_em, paginas_total,
     toner_preto_pct, toner_ciano_pct, toner_magenta_pct, toner_amarelo_pct)
SELECT s.impressora_id, s.coletado_em, s.paginas_total,
       s.toner_preto_pct, s.toner_ciano_pct, s.toner_magenta_pct, s.toner_amarelo_pct
FROM impressoras_snapshot s
JOIN (
    SELECT impressora_id, MAX(coletado_em) AS mx
    FROM impressoras_snapshot GROUP BY impressora_id
) m ON m.impressora_id = s.impressora_id AND m.mx = s.coletado_em
ON DUPLICATE KEY UPDATE
    coletado_em = VALUES(coletado_em),
    paginas_total = VALUES(paginas_total),
    toner_preto_pct = VALUES(toner_preto_pct),
    toner_ciano_pct = VALUES(toner_ciano_pct),
    toner_magenta_pct = VALUES(toner_magenta_pct),
    toner_amarelo_pct = VALUES(toner_amarelo_pct);

-- FULLTEXT
ALTER TABLE `chamados`   ADD FULLTEXT KEY `ft_chamados` (`descricao`, `solicitante`);
ALTER TABLE `inventario` ADD FULLTEXT KEY `ft_inventario` (`tipo`, `marca`, `modelo`, `numero_serie`, `patrimonio`, `setor`);
ALTER TABLE `contratos`  ADD FULLTEXT KEY `ft_contratos` (`nome`, `fornecedor`);
