-- ============================================================
-- 0003_auditoria_correcoes.sql
-- Alteracoes de schema exigidas pelas correcoes da auditoria.
-- migrate.php tolera "Duplicate column"/"Duplicate key"/"exists" (idempotente).
-- ============================================================

-- P0-4 / P1-1 : token opaco para acompanhamento publico e avaliacao
ALTER TABLE `chamados` ADD COLUMN `acompanhamento_token` char(20) DEFAULT NULL;
ALTER TABLE `chamados` ADD COLUMN `avaliacao_token`      char(20) DEFAULT NULL;
ALTER TABLE `chamados` ADD UNIQUE KEY `uk_acomp_token` (`acompanhamento_token`);
ALTER TABLE `chamados` ADD UNIQUE KEY `uk_aval_token`  (`avaliacao_token`);

-- Colunas usadas pelo codigo mas ausentes do dump antigo
ALTER TABLE `chamados` ADD COLUMN `deleted_at`          datetime DEFAULT NULL;
ALTER TABLE `chamados` ADD COLUMN `sla_alerta_enviado`  tinyint NOT NULL DEFAULT 0;
ALTER TABLE `chamados` ADD COLUMN `inventario_id`       int DEFAULT NULL;
ALTER TABLE `chamados` ADD KEY `idx_deleted_at` (`deleted_at`);

-- P1-4 / P3-4 : fila de e-mail com estado + lock por linha
ALTER TABLE `email_queue` ADD COLUMN `status` enum('pendente','enviando','enviado','falhou') NOT NULL DEFAULT 'pendente';
ALTER TABLE `email_queue` ADD COLUMN `locked_at` datetime DEFAULT NULL;
ALTER TABLE `email_queue` ADD COLUMN `lote` char(12) DEFAULT NULL;
ALTER TABLE `email_queue` ADD KEY `idx_status_lock` (`status`,`locked_at`,`criado_em`);
UPDATE `email_queue` SET `status` = 'enviado' WHERE `enviado_em` IS NOT NULL AND `status` = 'pendente';
UPDATE `email_queue` SET `status` = 'falhou'  WHERE `enviado_em` IS NULL AND `tentativas` >= 3 AND `status` = 'pendente';

-- P1-8 : rate limiting generico (reset de senha, portal, IA)
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `bucket`      varchar(120) NOT NULL,
  `tentativas`  int NOT NULL DEFAULT 0,
  `janela_inicio` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- P2-9 : heartbeat de jobs
CREATE TABLE IF NOT EXISTS `cron_runs` (
  `nome`        varchar(60) NOT NULL,
  `iniciado_em` datetime DEFAULT NULL,
  `terminado_em` datetime DEFAULT NULL,
  `ok`          tinyint(1) DEFAULT NULL,
  `mensagem`    varchar(255) DEFAULT NULL,
  `duracao_seg` int DEFAULT NULL,
  PRIMARY KEY (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- P1-2 : impede debito duplo do mesmo pedido no estoque (idempotencia)
-- Antes de criar a UNIQUE, remove duplicatas ja existentes (debitos dobrados),
-- mantendo a linha mais antiga, e corrige o estoque_atual dos afetados.
UPDATE tipos_suprimentos t
JOIN (
    SELECT m.tipo_suprimento_id AS sup, SUM(m.quantidade) AS excesso
    FROM estoque_movimentos m
    JOIN (
        SELECT MIN(id) AS keep_id, pedido_id, tipo_suprimento_id, tipo
        FROM estoque_movimentos
        WHERE pedido_id IS NOT NULL
        GROUP BY pedido_id, tipo_suprimento_id, tipo
        HAVING COUNT(*) > 1
    ) d ON d.pedido_id = m.pedido_id AND d.tipo_suprimento_id = m.tipo_suprimento_id
        AND d.tipo = m.tipo AND m.id <> d.keep_id
    WHERE m.tipo = 'saida'
    GROUP BY m.tipo_suprimento_id
) x ON x.sup = t.id
SET t.estoque_atual = t.estoque_atual + x.excesso;

DELETE m FROM estoque_movimentos m
JOIN (
    SELECT MIN(id) AS keep_id, pedido_id, tipo_suprimento_id, tipo
    FROM estoque_movimentos
    WHERE pedido_id IS NOT NULL
    GROUP BY pedido_id, tipo_suprimento_id, tipo
    HAVING COUNT(*) > 1
) d ON d.pedido_id = m.pedido_id AND d.tipo_suprimento_id = m.tipo_suprimento_id
    AND d.tipo = m.tipo AND m.id <> d.keep_id;

ALTER TABLE `estoque_movimentos` ADD UNIQUE KEY `uk_saida_pedido_item` (`pedido_id`,`tipo_suprimento_id`,`tipo`);

-- Backfill de tokens para chamados existentes (16 hex = 16 chars)
UPDATE `chamados`
   SET `acompanhamento_token` = SUBSTRING(REPLACE(UUID(),'-',''), 1, 16)
 WHERE `acompanhamento_token` IS NULL;
UPDATE `chamados`
   SET `avaliacao_token` = SUBSTRING(REPLACE(UUID(),'-',''), 1, 16)
 WHERE `avaliacao_token` IS NULL;
