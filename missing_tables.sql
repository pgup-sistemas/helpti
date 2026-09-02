-- ============================================================
-- HelpTI — Tabelas ausentes no backup
-- MySQL 8.0 compatível
-- ============================================================

CREATE TABLE IF NOT EXISTS impressoras (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    nome          VARCHAR(100) NOT NULL,
    marca_modelo  VARCHAR(100) NOT NULL,
    numero_serie  VARCHAR(100) NULL,
    ip            VARCHAR(45)  NULL,
    setor         VARCHAR(100) NOT NULL,
    modelo_toner  VARCHAR(100) NULL,
    status        ENUM('Ativa','Em Manutenção','Inativa') NOT NULL DEFAULT 'Ativa',
    criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_setor  (setor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tipos_suprimentos (
    id    INT AUTO_INCREMENT PRIMARY KEY,
    nome  VARCHAR(100) NOT NULL,
    ativo TINYINT NOT NULL DEFAULT 1,
    INDEX idx_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manutencoes_impressoras (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    impressora_id      INT NOT NULL,
    tecnico_id         INT NULL,
    tipo               VARCHAR(80) NOT NULL,
    descricao_problema TEXT NOT NULL,
    solucao            TEXT NULL,
    pecas_trocadas     TEXT NULL,
    data_manutencao    DATE NOT NULL,
    status             ENUM('Pendente','Em Realização','Concluída') NOT NULL DEFAULT 'Pendente',
    criado_em          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_manut_impressora FOREIGN KEY (impressora_id) REFERENCES impressoras (id) ON DELETE CASCADE,
    CONSTRAINT fk_manut_tecnico    FOREIGN KEY (tecnico_id)    REFERENCES usuarios (id) ON DELETE SET NULL,
    INDEX idx_impressora_id (impressora_id),
    INDEX idx_status        (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pedidos_suprimentos (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    numero              VARCHAR(20) NOT NULL UNIQUE,
    impressora_id       INT NULL,
    setor               VARCHAR(100) NOT NULL,
    solicitante         VARCHAR(100) NOT NULL,
    status              ENUM('Pendente','Aprovado','Entregue','Cancelado') NOT NULL DEFAULT 'Pendente',
    observacoes         TEXT NULL,
    observacoes_entrega TEXT NULL,
    criado_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ped_impressora FOREIGN KEY (impressora_id) REFERENCES impressoras (id) ON DELETE SET NULL,
    INDEX idx_status    (status),
    INDEX idx_criado_em (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pedidos_suprimentos_itens (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    pedido_id          INT NOT NULL,
    tipo_suprimento_id INT NULL,
    descricao_livre    VARCHAR(255) NULL,
    quantidade         INT NOT NULL DEFAULT 1,
    CONSTRAINT fk_item_pedido FOREIGN KEY (pedido_id)          REFERENCES pedidos_suprimentos (id) ON DELETE CASCADE,
    CONSTRAINT fk_item_tipo   FOREIGN KEY (tipo_suprimento_id) REFERENCES tipos_suprimentos (id) ON DELETE SET NULL,
    INDEX idx_pedido_id (pedido_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
