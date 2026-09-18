-- ============================================================
-- 0008_fk_chamados_setor.sql  (P3-5)
-- FK chamados.setor -> setores.nome, com ON UPDATE CASCADE
-- (renomear um setor propaga para o histórico de chamados).
--
-- ATENÇÃO: se algum chamado tiver setor que não existe em `setores`,
-- o backfill abaixo cria a linha. Se ainda assim o ALTER falhar,
-- há dado inconsistente que precisa de revisão manual — migrate.php aborta.
-- ============================================================

-- 1) Garante que todo setor referenciado existe no catálogo
INSERT IGNORE INTO setores (nome, ativo)
SELECT DISTINCT c.setor, 1
FROM chamados c
LEFT JOIN setores s ON s.nome = c.setor
WHERE c.setor IS NOT NULL AND c.setor <> '' AND s.nome IS NULL;

-- 2) Normaliza colunas para o mesmo tipo/collation (pré-requisito de FK)
ALTER TABLE `chamados` MODIFY `setor` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL;
ALTER TABLE `setores`  MODIFY `nome`  varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL;

-- 3) FK
ALTER TABLE `chamados`
  ADD CONSTRAINT `fk_chamado_setor`
  FOREIGN KEY (`setor`) REFERENCES `setores` (`nome`)
  ON UPDATE CASCADE ON DELETE RESTRICT;
