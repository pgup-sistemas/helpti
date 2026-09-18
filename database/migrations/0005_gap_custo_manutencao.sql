-- ============================================================
-- 0005_gap_custo_manutencao.sql
-- Coluna 'custo' faltava em manutencoes_impressoras (constava no setup.php
-- original mas nao no banco real). Usada por exportar_relatorio.php e
-- pelo formulario de manutencao.
-- ============================================================
ALTER TABLE `manutencoes_impressoras` ADD COLUMN `custo` decimal(10,2) DEFAULT NULL;
