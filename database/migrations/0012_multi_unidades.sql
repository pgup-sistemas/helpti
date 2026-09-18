-- ============================================================
-- 0012_multi_unidades.sql — Suporte multi-filial por usuário
-- ============================================================

-- ── Tabela de unidades/filiais ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `unidades` (
  `id`                   INT NOT NULL AUTO_INCREMENT,
  `nome`                 VARCHAR(120) NOT NULL,
  `codigo`               VARCHAR(20)  DEFAULT NULL COMMENT 'Código interno da filial',
  `cidade`               VARCHAR(80)  DEFAULT NULL,
  `endereco`             VARCHAR(200) DEFAULT NULL,
  `telefone`             VARCHAR(30)  DEFAULT NULL,
  `email_suporte`        VARCHAR(120) DEFAULT NULL,
  `equipe_responsavel_id` INT         DEFAULT NULL COMMENT 'Técnico/gestor responsável pela unidade',
  `sla_multiplicador`    DECIMAL(4,2) NOT NULL DEFAULT 1.00 COMMENT 'Fator de ajuste de SLA (ex: 1.5 = 50% mais prazo)',
  `ativo`                TINYINT(1)   NOT NULL DEFAULT 1,
  `criado_em`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_unidade_codigo` (`codigo`),
  KEY `idx_unidade_ativo` (`ativo`),
  CONSTRAINT `fk_unidade_responsavel`
    FOREIGN KEY (`equipe_responsavel_id`) REFERENCES `usuarios` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Unidades/filiais atendidas pelo sistema';

-- ── Pivot N:M usuários ↔ unidades ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `usuarios_unidades` (
  `id`          INT         NOT NULL AUTO_INCREMENT,
  `usuario_id`  INT         NOT NULL,
  `unidade_id`  INT         NOT NULL,
  `eh_padrao`   TINYINT(1)  NOT NULL DEFAULT 0 COMMENT 'Unidade padrão ao abrir chamados',
  `criado_em`   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_usr_und` (`usuario_id`, `unidade_id`),
  KEY `idx_uu_unidade` (`unidade_id`),
  CONSTRAINT `fk_uu_usuario`
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_uu_unidade`
    FOREIGN KEY (`unidade_id`) REFERENCES `unidades` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Relacionamento N:M usuários ↔ unidades';

-- Trigger criado via bin/create_trigger_uu.php (não suportado pelo migrate.php)

-- ── Coluna unidade_id nos chamados ────────────────────────────────────────────
ALTER TABLE `chamados`
  ADD COLUMN `unidade_id` INT DEFAULT NULL COMMENT 'Unidade/filial de origem do chamado';

ALTER TABLE `chamados`
  ADD CONSTRAINT `fk_chamado_unidade`
    FOREIGN KEY (`unidade_id`) REFERENCES `unidades` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `chamados`
  ADD KEY `idx_chamado_unidade` (`unidade_id`);

-- ── Unidade padrão "Matriz" para retrocompatibilidade ────────────────────────
INSERT IGNORE INTO `unidades` (`id`, `nome`, `codigo`, `sla_multiplicador`, `ativo`)
VALUES (1, 'Matriz', 'MATRIZ', 1.00, 1);
