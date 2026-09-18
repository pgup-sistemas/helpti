-- ============================================================
-- 0011_enterprise_fixes.sql — Correções enterprise HelpTI
-- Aplica: php bin/migrate.php
-- migrate.php tolera "Duplicate column/key/constraint" e "already exists"
-- ============================================================

-- ── 1. Soft delete — adicionar deleted_at ────────────────────────────────
ALTER TABLE `usuarios`    ADD COLUMN `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `inventario`  ADD COLUMN `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `impressoras` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL;

ALTER TABLE `usuarios`    ADD KEY `idx_usr_deleted`  (`deleted_at`);
ALTER TABLE `inventario`  ADD KEY `idx_inv_deleted`  (`deleted_at`);
ALTER TABLE `impressoras` ADD KEY `idx_imp_deleted`  (`deleted_at`);

-- ── 2. Corrigir CASCADE → RESTRICT em historico_movimentacao (G-03) ──────
-- ON DELETE CASCADE apagava toda a trilha de auditoria ao deletar ativo
ALTER TABLE `historico_movimentacao` DROP FOREIGN KEY `fk_hm_inv`;
ALTER TABLE `historico_movimentacao`
  ADD CONSTRAINT `fk_hm_inv`
  FOREIGN KEY (`inventario_id`) REFERENCES `inventario`(`id`)
  ON DELETE RESTRICT;

-- ── 3. Corrigir CASCADE → RESTRICT em auditoria_itens (G-03) ─────────────
ALTER TABLE `auditoria_itens` DROP FOREIGN KEY `fk_ai_inv`;
ALTER TABLE `auditoria_itens`
  ADD CONSTRAINT `fk_ai_inv`
  FOREIGN KEY (`inventario_id`) REFERENCES `inventario`(`id`)
  ON DELETE RESTRICT;

-- ── 4. FK em chamados.inventario_id (G-06) ────────────────────────────────
-- Limpa IDs órfãos antes de criar a constraint
UPDATE `chamados` SET `inventario_id` = NULL
WHERE `inventario_id` IS NOT NULL
  AND `inventario_id` NOT IN (SELECT id FROM inventario);

ALTER TABLE `chamados`
  ADD CONSTRAINT `fk_chamado_inventario`
  FOREIGN KEY (`inventario_id`) REFERENCES `inventario`(`id`)
  ON DELETE SET NULL;

ALTER TABLE `chamados` ADD KEY `idx_chamado_inv` (`inventario_id`);

-- ── 5. FK em inventario.setor → setores.nome (G-08) ──────────────────────
-- Garante que todos os setores do inventário existam no catálogo
INSERT IGNORE INTO setores (nome, ativo)
SELECT DISTINCT i.setor, 1
FROM inventario i
LEFT JOIN setores s ON s.nome = i.setor
WHERE i.setor IS NOT NULL AND i.setor != '' AND s.nome IS NULL;

ALTER TABLE `inventario`
  ADD CONSTRAINT `fk_inv_setor`
  FOREIGN KEY (`setor`) REFERENCES `setores`(`nome`)
  ON UPDATE CASCADE
  ON DELETE SET NULL;

-- ── 6. FK formal em impressoras.inventario_id (P-07) ─────────────────────
UPDATE `impressoras` SET `inventario_id` = NULL
WHERE `inventario_id` IS NOT NULL
  AND `inventario_id` NOT IN (SELECT id FROM inventario);

ALTER TABLE `impressoras`
  ADD CONSTRAINT `fk_imp_inventario`
  FOREIGN KEY (`inventario_id`) REFERENCES `inventario`(`id`)
  ON DELETE SET NULL;

-- ── 7. Snapshot imutável mensal (P-08) ───────────────────────────────────
CREATE TABLE IF NOT EXISTS `inventario_snapshot_mensal` (
  `id`               BIGINT NOT NULL AUTO_INCREMENT,
  `periodo`          DATE NOT NULL COMMENT 'Primeiro dia do mes (ex: 2026-09-01)',
  `inventario_id`    INT NOT NULL,
  `tipo`             VARCHAR(80) NOT NULL,
  `marca`            VARCHAR(60) DEFAULT NULL,
  `modelo`           VARCHAR(100) DEFAULT NULL,
  `numero_serie`     VARCHAR(100) DEFAULT NULL,
  `patrimonio`       VARCHAR(60) DEFAULT NULL,
  `setor`            VARCHAR(80) DEFAULT NULL,
  `responsavel_nome` VARCHAR(100) DEFAULT NULL,
  `status`           VARCHAR(40) NOT NULL,
  `valor`            DECIMAL(10,2) DEFAULT NULL,
  `capturado_em`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_snap_periodo_ativo` (`periodo`, `inventario_id`),
  KEY `idx_snap_periodo` (`periodo`),
  KEY `idx_snap_inv`     (`inventario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Snapshot imutavel — nunca DELETE/UPDATE via aplicacao';

-- ── 8. View publica de termos sem PII (G-07) ─────────────────────────────
CREATE OR REPLACE VIEW `v_termos_uso_publico` AS
SELECT
  id, inventario_id, responsavel_nome, setor,
  data_entrega, data_prevista_devolucao, data_devolucao,
  condicao_entrega, condicao_devolucao, status, observacoes,
  assinado_em, criado_em
FROM termos_uso;

-- ── 9. Índices de performance (P-06) ─────────────────────────────────────
ALTER TABLE `chamados`   ADD KEY `idx_chamados_status_criado` (`status`, `criado_em`);
ALTER TABLE `chamados`   ADD KEY `idx_chamados_resp_status`   (`responsavel_id`, `status`);
ALTER TABLE `chamados`   ADD KEY `idx_chamados_deleted_status`(`deleted_at`, `status`);
ALTER TABLE `inventario` ADD KEY `idx_inv_setor_status`       (`status`, `deleted_at`);
ALTER TABLE `auditoria_itens` ADD KEY `idx_ai_ciclo_verif`    (`ciclo_id`, `verificado`, `divergencia`);
ALTER TABLE `historico_movimentacao` ADD KEY `idx_hm_inv_data`(`inventario_id`, `criado_em`);
ALTER TABLE `termos_uso` ADD KEY `idx_termos_inv_status`      (`inventario_id`, `status`);
ALTER TABLE `hosts_rede` ADD KEY `idx_hosts_ip_online`        (`ip`, `online`);
ALTER TABLE `audit_log`  ADD KEY `idx_audit_usr_data`         (`usuario_id`, `criado_em`);
