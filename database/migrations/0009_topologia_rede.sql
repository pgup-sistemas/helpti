-- ============================================================
-- 0009_topologia_rede.sql
-- Fundação de dados para a página de Topologia de Rede:
--   - hosts_rede.attributes: atributos dinâmicos por tipo de ativo
--     (ex: toner de impressora, portas de switch), sem redesenhar
--     o schema a cada novo tipo de equipamento.
--   - hosts_rede.gateway_ip: gateway inferido da sub-rede do host,
--     usado como pai provisório na árvore enquanto não há LLDP/SNMP
--     de topologia (fase 2/3).
--   - asset_relationships: relações entre ativos (genealogia da rede),
--     com origem e confiança, para permitir tanto relação automática
--     (scanner) quanto correção manual pelo administrador.
-- ============================================================

ALTER TABLE `hosts_rede`
  ADD COLUMN `attributes` JSON DEFAULT NULL AFTER `portas`,
  ADD COLUMN `gateway_ip` varchar(45) DEFAULT NULL AFTER `rede`;

CREATE TABLE IF NOT EXISTS `asset_relationships` (
  `id` int NOT NULL AUTO_INCREMENT,
  `source_host_id` int NOT NULL,
  `target_host_id` int NOT NULL,
  `relationship_type` varchar(30) NOT NULL DEFAULT 'CONNECTED_TO',
  `source` varchar(30) NOT NULL DEFAULT 'inferred',
  `confidence` tinyint unsigned NOT NULL DEFAULT 50,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rel` (`source_host_id`,`target_host_id`,`relationship_type`),
  KEY `idx_target` (`target_host_id`),
  CONSTRAINT `fk_rel_source` FOREIGN KEY (`source_host_id`) REFERENCES `hosts_rede` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rel_target` FOREIGN KEY (`target_host_id`) REFERENCES `hosts_rede` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
