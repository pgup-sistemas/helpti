-- ============================================================
-- HelpTI — Migrations de segurança e produto
-- MySQL 8.0 compatível
-- ============================================================

-- 1. Campo de telefone/ramal do solicitante
ALTER TABLE chamados
  ADD COLUMN telefone_solicitante VARCHAR(25) NULL AFTER solicitante;

-- 2. Tabela de recuperação de senha
CREATE TABLE IF NOT EXISTS password_resets (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    email     VARCHAR(100) NOT NULL,
    token     VARCHAR(64)  NOT NULL UNIQUE,
    criado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usado     TINYINT      NOT NULL DEFAULT 0,
    INDEX idx_token (token),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Índices de performance (completo)
ALTER TABLE chamados
  ADD INDEX IF NOT EXISTS idx_status      (status),
  ADD INDEX IF NOT EXISTS idx_criado_em   (criado_em),
  ADD INDEX IF NOT EXISTS idx_nivel       (nivel),
  ADD INDEX IF NOT EXISTS idx_setor       (setor(50)),
  ADD INDEX IF NOT EXISTS idx_categoria   (categoria_id),
  ADD INDEX IF NOT EXISTS idx_resp_status (responsavel_id, status);

-- 4. historico.acao ampliado para TEXT
ALTER TABLE historico
  MODIFY COLUMN acao TEXT NOT NULL;

-- 5. Timestamp de conclusão para SLA futuro
ALTER TABLE chamados
  ADD COLUMN IF NOT EXISTS fechado_em DATETIME NULL AFTER atualizado_em;

-- 6. Rate limiting de login no banco (substitui arquivos temp)
CREATE TABLE IF NOT EXISTS login_attempts (
    ip               VARCHAR(45) NOT NULL PRIMARY KEY,
    tentativas       TINYINT     NOT NULL DEFAULT 1,
    ultima_tentativa DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Sequências atômicas para numeração de chamados (substitui md5/uniqid)
CREATE TABLE IF NOT EXISTS sequences (
    name  VARCHAR(50) NOT NULL PRIMARY KEY,
    value INT         NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sequences (name, value) VALUES ('chamados', 0);

-- 8. Soft delete e flag SLA em chamados
ALTER TABLE chamados
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS sla_alerta_enviado TINYINT NOT NULL DEFAULT 0;

-- 9. Log de auditoria
CREATE TABLE IF NOT EXISTS audit_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT NULL,
    acao        VARCHAR(100) NOT NULL,
    tabela      VARCHAR(50)  NULL,
    registro_id INT          NULL,
    detalhe     TEXT         NULL,
    ip          VARCHAR(45)  NULL,
    criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_usuario (usuario_id),
    INDEX idx_criado  (criado_em),
    INDEX idx_tabela  (tabela, registro_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Base de conhecimento com busca full-text
CREATE TABLE IF NOT EXISTS knowledge_base (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    titulo        VARCHAR(200) NOT NULL,
    conteudo      TEXT         NOT NULL,
    categoria_id  INT          NULL,
    publico       TINYINT      NOT NULL DEFAULT 1,
    visualizacoes INT          NOT NULL DEFAULT 0,
    autor_id      INT          NULL,
    criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    FULLTEXT KEY ft_kb (titulo, conteudo),
    INDEX idx_pub (publico),
    INDEX idx_cat (categoria_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Sequence para numeração de suprimentos (substitui md5/uniqid)
INSERT IGNORE INTO sequences (name, value)
SELECT 'suprimentos', COALESCE(MAX(
    CAST(SUBSTRING_INDEX(numero, '-', -1) AS UNSIGNED)
), 0) FROM pedidos_suprimentos;

-- 13. Snapshots SNMP de impressoras (páginas + toner)
CREATE TABLE IF NOT EXISTS `impressoras_snapshot` (
  `id` int NOT NULL AUTO_INCREMENT,
  `impressora_id` int NOT NULL,
  `coletado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paginas_total` int DEFAULT NULL,
  `toner_preto_pct` tinyint DEFAULT NULL,
  `toner_ciano_pct` tinyint DEFAULT NULL,
  `toner_magenta_pct` tinyint DEFAULT NULL,
  `toner_amarelo_pct` tinyint DEFAULT NULL,
  `raw_snmp` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_impressora_data` (`impressora_id`, `coletado_em`),
  CONSTRAINT `fk_snap_impressora` FOREIGN KEY (`impressora_id`)
    REFERENCES `impressoras` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Fila de e-mail assíncrona (substitui @mail() síncrono)
CREATE TABLE IF NOT EXISTS email_queue (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    destinatario VARCHAR(100) NOT NULL,
    assunto      VARCHAR(255) NOT NULL,
    corpo        LONGTEXT     NOT NULL,
    tentativas   TINYINT      NOT NULL DEFAULT 0,
    criado_em    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviado_em   DATETIME     NULL,
    erro         TEXT         NULL,
    INDEX idx_pendentes (enviado_em, tentativas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
