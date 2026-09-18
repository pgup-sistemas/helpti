-- ============================================================
-- 0011b_fk_setor_fix.sql — Corrige FK inventario.setor
-- Parte que falhou em 0011 por strings vazias != NULL
-- ============================================================

-- Normaliza string vazia para NULL (empty string nao pode ter FK para setores.nome)
UPDATE `inventario` SET `setor` = NULL WHERE `setor` = '';

-- Garante que todos os setores nao-nulos existam no catalogo
INSERT IGNORE INTO setores (nome, ativo)
SELECT DISTINCT i.setor, 1
FROM inventario i
LEFT JOIN setores s ON s.nome = i.setor
WHERE i.setor IS NOT NULL AND i.setor != '' AND s.nome IS NULL;

-- FK com CASCADE para propagacao de rename de setor
ALTER TABLE `inventario`
  ADD CONSTRAINT `fk_inv_setor`
  FOREIGN KEY (`setor`) REFERENCES `setores`(`nome`)
  ON UPDATE CASCADE
  ON DELETE SET NULL;

-- FK formal em impressoras.inventario_id
UPDATE `impressoras` SET `inventario_id` = NULL
WHERE `inventario_id` IS NOT NULL
  AND `inventario_id` NOT IN (SELECT id FROM inventario);

ALTER TABLE `impressoras`
  ADD CONSTRAINT `fk_imp_inventario`
  FOREIGN KEY (`inventario_id`) REFERENCES `inventario`(`id`)
  ON DELETE SET NULL;
