-- ============================================================
-- 0001_baseline.sql — Schema canônico do HelpTI
-- Fonte: consolidação do antigo setup.php + missing_tables.sql + ti_chamados_backup.sql
-- Aplicar com: php bin/migrate.php
-- ============================================================
-- Todas as tabelas usam IF NOT EXISTS para ser idempotente sobre bancos já existentes.
-- Se o seu banco de produção JÁ tem estas tabelas, esta migration é no-op seguro.
-- Recomendado: substituir este arquivo pelo dump real (`mysqldump --no-data helpti`)
-- na primeira aplicação, mantendo o cabeçalho.

CREATE TABLE IF NOT EXISTS `categorias` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icone` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bi-tag',
  `ativo` tinyint NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `idx_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `senha` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `perfil` enum('tecnico','gestora','admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tecnico',
  `ativo` tinyint NOT NULL DEFAULT '1',
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `setores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ativo` tinyint NOT NULL DEFAULT '1',
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chamados` (
  `id` int NOT NULL AUTO_INCREMENT,
  `numero` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `setor` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `solicitante` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefone_solicitante` varchar(25) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `responsavel_id` int DEFAULT NULL,
  `nivel` enum('A Definir','Baixa Complexidade','Média Complexidade','Alta Complexidade') COLLATE utf8mb4_unicode_ci DEFAULT 'A Definir',
  `categoria_id` int DEFAULT NULL,
  `status` enum('Aberto','Em Andamento','Pendente','Concluído') COLLATE utf8mb4_unicode_ci DEFAULT 'Aberto',
  `semana` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resolucao` text COLLATE utf8mb4_unicode_ci,
  `origem` enum('Formulário Web','WhatsApp','Telefone','Presencial') COLLATE utf8mb4_unicode_ci DEFAULT 'Formulário Web',
  `imagens` text COLLATE utf8mb4_unicode_ci,
  `criado_em` datetime DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `fechado_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero` (`numero`),
  KEY `responsavel_id` (`responsavel_id`),
  KEY `idx_status` (`status`),
  KEY `idx_criado_em` (`criado_em`),
  KEY `fk_chamado_cat` (`categoria_id`),
  CONSTRAINT `chamados_ibfk_1` FOREIGN KEY (`responsavel_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_chamado_cat` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `historico` (
  `id` int NOT NULL AUTO_INCREMENT,
  `chamado_id` int NOT NULL,
  `usuario_id` int DEFAULT NULL,
  `acao` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `criado_em` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `chamado_id` (`chamado_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `historico_ibfk_1` FOREIGN KEY (`chamado_id`) REFERENCES `chamados` (`id`) ON DELETE CASCADE,
  CONSTRAINT `historico_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `impressoras` (
  `id` int NOT NULL AUTO_INCREMENT,
  `inventario_id` int DEFAULT NULL,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `marca_modelo` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `numero_serie` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `setor` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `modelo_toner` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Ativa','Em Manutenção','Inativa') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Ativa',
  `alerta_toner_em` datetime DEFAULT NULL,
  `alerta_offline_em` datetime DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_setor` (`setor`),
  KEY `idx_inventario` (`inventario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `manutencoes_impressoras` (
  `id` int NOT NULL AUTO_INCREMENT,
  `impressora_id` int NOT NULL,
  `tecnico_id` int DEFAULT NULL,
  `tipo` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao_problema` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `solucao` text COLLATE utf8mb4_unicode_ci,
  `pecas_trocadas` text COLLATE utf8mb4_unicode_ci,
  `custo` decimal(10,2) DEFAULT NULL,
  `data_manutencao` date NOT NULL,
  `status` enum('Pendente','Em Realização','Concluída') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pendente',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_manut_tecnico` (`tecnico_id`),
  KEY `idx_impressora_id` (`impressora_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_manut_impressora` FOREIGN KEY (`impressora_id`) REFERENCES `impressoras` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_manut_tecnico` FOREIGN KEY (`tecnico_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tipos_suprimentos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `estoque_atual` int NOT NULL DEFAULT '0',
  `estoque_minimo` int NOT NULL DEFAULT '0',
  `ativo` tinyint NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `idx_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pedidos_suprimentos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `numero` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `impressora_id` int DEFAULT NULL,
  `setor` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `solicitante` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('Pendente','Aprovado','Entregue','Cancelado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pendente',
  `observacoes` text COLLATE utf8mb4_unicode_ci,
  `observacoes_entrega` text COLLATE utf8mb4_unicode_ci,
  `acompanhamento_token` char(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero` (`numero`),
  KEY `fk_ped_impressora` (`impressora_id`),
  KEY `idx_status` (`status`),
  KEY `idx_criado_em` (`criado_em`),
  CONSTRAINT `fk_ped_impressora` FOREIGN KEY (`impressora_id`) REFERENCES `impressoras` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pedidos_suprimentos_itens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pedido_id` int NOT NULL,
  `tipo_suprimento_id` int DEFAULT NULL,
  `descricao_livre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantidade` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `fk_item_tipo` (`tipo_suprimento_id`),
  KEY `idx_pedido_id` (`pedido_id`),
  CONSTRAINT `fk_item_pedido` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos_suprimentos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_tipo` FOREIGN KEY (`tipo_suprimento_id`) REFERENCES `tipos_suprimentos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tipos_inventario` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icone` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bi-cpu',
  `ativo` tinyint NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventario` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tipo` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `marca` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `modelo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero_serie` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `patrimonio` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `setor` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `responsavel_nome` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Em Uso','Disponível','Em Manutenção','Descartado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Em Uso',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mac_address` varchar(17) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_aquisicao` date DEFAULT NULL,
  `valor` decimal(10,2) DEFAULT NULL,
  `garantia_ate` date DEFAULT NULL,
  `imei` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observacoes` text COLLATE utf8mb4_unicode_ci,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_status` (`status`),
  KEY `idx_setor` (`setor`),
  KEY `idx_mac` (`mac_address`),
  KEY `idx_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contratos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tipo` enum('Contrato','Licença','Garantia','Assinatura','Suporte','Outro') COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fornecedor` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero_contrato` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valor` decimal(10,2) DEFAULT NULL,
  `periodicidade` enum('Mensal','Trimestral','Semestral','Anual','Único') COLLATE utf8mb4_unicode_ci DEFAULT 'Anual',
  `data_inicio` date DEFAULT NULL,
  `data_vencimento` date NOT NULL,
  `renovacao_auto` tinyint NOT NULL DEFAULT '0',
  `alerta_dias` int NOT NULL DEFAULT '30',
  `status` enum('Ativo','Vencido','Cancelado','Em Renovação') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Ativo',
  `observacoes` text COLLATE utf8mb4_unicode_ci,
  `corpo` mediumtext COLLATE utf8mb4_unicode_ci,
  `arquivo_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vencimento` (`data_vencimento`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `termos_uso` (
  `id` int NOT NULL AUTO_INCREMENT,
  `inventario_id` int NOT NULL,
  `responsavel_nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `responsavel_cpf` varchar(14) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `responsavel_matricula` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `setor` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_entrega` date NOT NULL,
  `data_prevista_devolucao` date DEFAULT NULL,
  `data_devolucao` date DEFAULT NULL,
  `condicao_entrega` text COLLATE utf8mb4_unicode_ci,
  `condicao_devolucao` text COLLATE utf8mb4_unicode_ci,
  `status` enum('Ativo','Devolvido') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Ativo',
  `observacoes` text COLLATE utf8mb4_unicode_ci,
  `assinado_em` datetime DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_inventario` (`inventario_id`),
  CONSTRAINT `termos_uso_ibfk_1` FOREIGN KEY (`inventario_id`) REFERENCES `inventario` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
