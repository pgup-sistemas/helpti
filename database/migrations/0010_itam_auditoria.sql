-- ============================================================
-- 0010_itam_auditoria.sql — Módulo ITAM: Auditoria de Inventário
-- Aplica com: php bin/migrate.php
-- migrate.php tolera "Duplicate column"/"Duplicate key"/"already exists"
-- ============================================================

-- ── 1. Campos adicionais na tabela inventario ──────────────────────────────
ALTER TABLE `inventario` ADD COLUMN `responsavel_id`    INT DEFAULT NULL;
ALTER TABLE `inventario` ADD COLUMN `ultima_verificacao` DATETIME DEFAULT NULL;
ALTER TABLE `inventario` ADD KEY `idx_inv_resp_id` (`responsavel_id`);
ALTER TABLE `inventario` ADD CONSTRAINT `fk_inv_resp`
  FOREIGN KEY (`responsavel_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL;

-- ── 2. Ciclos de auditoria (envelope de cada auditoria periódica) ──────────
CREATE TABLE IF NOT EXISTS `ciclos_auditoria` (
  `id`               INT NOT NULL AUTO_INCREMENT,
  `nome`             VARCHAR(100) NOT NULL,
  `descricao`        TEXT,
  `responsavel_id`   INT DEFAULT NULL,
  `status`           ENUM('Aberto','Em Andamento','Concluído','Cancelado') NOT NULL DEFAULT 'Aberto',
  `setor_filtro`     VARCHAR(80) DEFAULT NULL,
  `total_esperado`   INT NOT NULL DEFAULT 0,
  `total_verificado` INT NOT NULL DEFAULT 0,
  `taxa_acuracia`    DECIMAL(5,2) DEFAULT NULL,
  `iniciado_em`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `encerrado_em`     DATETIME DEFAULT NULL,
  `criado_em`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ca_status` (`status`),
  KEY `fk_ca_resp` (`responsavel_id`),
  CONSTRAINT `fk_ca_resp` FOREIGN KEY (`responsavel_id`)
    REFERENCES `usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Itens do checklist por ciclo ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `auditoria_itens` (
  `id`                      INT NOT NULL AUTO_INCREMENT,
  `ciclo_id`                INT NOT NULL,
  `inventario_id`           INT NOT NULL,
  `tecnico_id`              INT DEFAULT NULL,
  `status_esperado`         VARCHAR(40) NOT NULL,
  `status_encontrado`       VARCHAR(40) DEFAULT NULL,
  `setor_esperado`          VARCHAR(80) DEFAULT NULL,
  `setor_encontrado`        VARCHAR(80) DEFAULT NULL,
  `responsavel_esperado`    VARCHAR(100) DEFAULT NULL,
  `responsavel_encontrado`  VARCHAR(100) DEFAULT NULL,
  `verificado`              TINYINT NOT NULL DEFAULT 0,
  `divergencia`             TINYINT NOT NULL DEFAULT 0,
  `observacao`              TEXT,
  `verificado_em`           DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ciclo_ativo` (`ciclo_id`,`inventario_id`),
  KEY `idx_ai_verificado` (`verificado`),
  KEY `idx_ai_divergencia` (`divergencia`),
  KEY `fk_ai_tecnico` (`tecnico_id`),
  CONSTRAINT `fk_ai_ciclo`   FOREIGN KEY (`ciclo_id`)      REFERENCES `ciclos_auditoria`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_inv`     FOREIGN KEY (`inventario_id`) REFERENCES `inventario`(`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_ai_tecnico` FOREIGN KEY (`tecnico_id`)    REFERENCES `usuarios`(`id`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Histórico de movimentações de ativos ────────────────────────────────
CREATE TABLE IF NOT EXISTS `historico_movimentacao` (
  `id`               INT NOT NULL AUTO_INCREMENT,
  `inventario_id`    INT NOT NULL,
  `usuario_id`       INT DEFAULT NULL,
  `tipo`             ENUM('Transferência','Entrada','Descarte','Manutenção','Auditoria') NOT NULL,
  `setor_anterior`   VARCHAR(80) DEFAULT NULL,
  `setor_novo`       VARCHAR(80) DEFAULT NULL,
  `resp_anterior`    VARCHAR(100) DEFAULT NULL,
  `resp_novo`        VARCHAR(100) DEFAULT NULL,
  `status_anterior`  VARCHAR(40) DEFAULT NULL,
  `status_novo`      VARCHAR(40) DEFAULT NULL,
  `observacao`       TEXT,
  `criado_em`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hm_inventario` (`inventario_id`),
  KEY `idx_hm_criado_em` (`criado_em`),
  KEY `fk_hm_user` (`usuario_id`),
  CONSTRAINT `fk_hm_inv`  FOREIGN KEY (`inventario_id`) REFERENCES `inventario`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hm_user` FOREIGN KEY (`usuario_id`)    REFERENCES `usuarios`(`id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
