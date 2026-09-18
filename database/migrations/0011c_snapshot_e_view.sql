-- ============================================================
-- 0011c_snapshot_e_view.sql — Snapshot mensal + view PII-safe + índices
-- ============================================================

-- ── Snapshot imutável mensal ───────────────────────────────────────────────
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

-- ── View publica de termos sem PII (G-07) ─────────────────────────────────
CREATE OR REPLACE VIEW `v_termos_uso_publico` AS
SELECT
  id, inventario_id, responsavel_nome, setor,
  data_entrega, data_prevista_devolucao, data_devolucao,
  condicao_entrega, condicao_devolucao, status, observacoes,
  assinado_em, criado_em
FROM termos_uso;

-- ── Índices de performance (idempotente via ALTER IGNORE) ─────────────────
ALTER TABLE `chamados`   ADD KEY `idx_chamados_status_criado`  (`status`, `criado_em`);
ALTER TABLE `chamados`   ADD KEY `idx_chamados_resp_status`    (`responsavel_id`, `status`);
ALTER TABLE `chamados`   ADD KEY `idx_chamados_deleted_status` (`deleted_at`, `status`);
ALTER TABLE `inventario` ADD KEY `idx_inv_setor_status`        (`status`, `deleted_at`);
ALTER TABLE `auditoria_itens` ADD KEY `idx_ai_ciclo_verif`     (`ciclo_id`, `verificado`, `divergencia`);
ALTER TABLE `historico_movimentacao` ADD KEY `idx_hm_inv_data` (`inventario_id`, `criado_em`);
ALTER TABLE `termos_uso` ADD KEY `idx_termos_inv_status`       (`inventario_id`, `status`);
ALTER TABLE `hosts_rede` ADD KEY `idx_hosts_ip_online`         (`ip`, `online`);
ALTER TABLE `audit_log`  ADD KEY `idx_audit_usr_data`          (`usuario_id`, `criado_em`);
