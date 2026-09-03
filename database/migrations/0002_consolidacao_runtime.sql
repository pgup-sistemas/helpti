-- ============================================================
-- 0002_consolidacao_runtime.sql
-- Consolida TODAS as tabelas que antes eram criadas em runtime via
-- "CREATE TABLE IF NOT EXISTS" espalhado por:
--   estoque_helpers.php, esqueci_senha.php, contratos.php,
--   cron_scanner.php, snmp_coletar.php, migrations.sql antigo
-- A partir daqui esses arquivos NÃO devem mais conter DDL.
-- ============================================================

CREATE TABLE IF NOT EXISTS `sequences` (
  `name`  varchar(40) NOT NULL,
  `value` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `sequences` (`name`,`value`) VALUES ('chamados',0),('suprimentos',0);

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `ip`               varchar(45) NOT NULL,
  `tentativas`       int NOT NULL DEFAULT '0',
  `ultima_tentativa` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`        int AUTO_INCREMENT PRIMARY KEY,
  `email`     varchar(100) NOT NULL,
  `token`     varchar(64)  NOT NULL,
  `criado_em` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `usado`     tinyint      NOT NULL DEFAULT 0,
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`          int NOT NULL AUTO_INCREMENT,
  `usuario_id`  int DEFAULT NULL,
  `acao`        varchar(80) NOT NULL,
  `tabela`      varchar(60) DEFAULT NULL,
  `registro_id` int DEFAULT NULL,
  `detalhe`     text DEFAULT NULL,
  `ip`          varchar(45) DEFAULT NULL,
  `criado_em`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_criado_em` (`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `knowledge_base` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `titulo`     varchar(200) NOT NULL,
  `conteudo`   mediumtext NOT NULL,
  `categoria`  varchar(80) DEFAULT NULL,
  `criado_em`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FULLTEXT KEY `ft_kb` (`titulo`,`conteudo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_queue` (
  `id`           int NOT NULL AUTO_INCREMENT,
  `destinatario` varchar(160) NOT NULL,
  `assunto`      varchar(255) NOT NULL,
  `corpo`        mediumtext NOT NULL,
  `tentativas`   int NOT NULL DEFAULT 0,
  `erro`         varchar(255) DEFAULT NULL,
  `criado_em`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `enviado_em`   datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pendentes` (`enviado_em`,`tentativas`,`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `avaliacoes` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `chamado_id` int NOT NULL,
  `nota`       tinyint NOT NULL,
  `comentario` text DEFAULT NULL,
  `criado_em`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chamado` (`chamado_id`),
  CONSTRAINT `fk_aval_chamado` FOREIGN KEY (`chamado_id`) REFERENCES `chamados` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `estoque_movimentos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tipo_suprimento_id` int NOT NULL,
  `tipo` enum('entrada','saida','ajuste') NOT NULL,
  `quantidade` int NOT NULL COMMENT 'sempre positivo; o sinal e dado pela coluna tipo',
  `motivo` varchar(255) DEFAULT NULL,
  `pedido_id` int DEFAULT NULL,
  `usuario_id` int DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tipo_sup` (`tipo_suprimento_id`),
  KEY `idx_pedido` (`pedido_id`),
  CONSTRAINT `fk_mov_tipo_suprimento` FOREIGN KEY (`tipo_suprimento_id`) REFERENCES `tipos_suprimentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contratos_renovacoes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `contrato_id` int NOT NULL,
  `data_anterior` date NOT NULL,
  `data_nova` date NOT NULL,
  `tipo` enum('auto','manual') NOT NULL,
  `usuario_id` int DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contrato` (`contrato_id`),
  CONSTRAINT `fk_renov_contrato` FOREIGN KEY (`contrato_id`) REFERENCES `contratos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  KEY `idx_impressora_data` (`impressora_id`,`coletado_em`),
  CONSTRAINT `fk_snap_impressora` FOREIGN KEY (`impressora_id`) REFERENCES `impressoras` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hosts_rede` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `mac_address` varchar(17) NOT NULL,
  `hostname` varchar(255) DEFAULT NULL,
  `fabricante` varchar(100) DEFAULT NULL,
  `tipo` varchar(50) DEFAULT NULL,
  `marca` varchar(60) DEFAULT NULL,
  `portas` text DEFAULT NULL,
  `rede` varchar(20) DEFAULT NULL,
  `setor` varchar(60) DEFAULT NULL,
  `inventario_id` int DEFAULT NULL,
  `primeiro_visto` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ultimo_visto` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `online` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mac` (`mac_address`),
  KEY `idx_ip` (`ip`),
  KEY `idx_tipo` (`tipo`),
  KEY `fk_hosts_inventario` (`inventario_id`),
  CONSTRAINT `fk_hosts_inventario` FOREIGN KEY (`inventario_id`) REFERENCES `inventario` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
