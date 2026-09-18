-- ============================================================
-- 0006_pedidos_token.sql
-- Token de acompanhamento para pedidos de suprimento (P0-4).
-- ============================================================
ALTER TABLE `pedidos_suprimentos` ADD COLUMN `acompanhamento_token` char(20) DEFAULT NULL;
ALTER TABLE `pedidos_suprimentos` ADD UNIQUE KEY `uk_ps_acomp_token` (`acompanhamento_token`);
UPDATE `pedidos_suprimentos`
   SET `acompanhamento_token` = SUBSTRING(REPLACE(UUID(),'-',''), 1, 16)
 WHERE `acompanhamento_token` IS NULL;
