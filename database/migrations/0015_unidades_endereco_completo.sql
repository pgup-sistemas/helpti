-- ============================================================
-- 0015_unidades_endereco_completo.sql
-- Adiciona CEP, bairro e UF à tabela unidades
-- ============================================================

ALTER TABLE `unidades`
  ADD COLUMN `cep`    VARCHAR(9)  DEFAULT NULL AFTER `codigo`,
  ADD COLUMN `bairro` VARCHAR(100) DEFAULT NULL AFTER `cidade`,
  ADD COLUMN `uf`     CHAR(2)     DEFAULT NULL AFTER `bairro`;
