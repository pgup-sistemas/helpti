-- 0014_knowledge_base_v2.sql
-- Evolui a tabela knowledge_base para suportar:
--   categoria_id (FK → categorias), publico, autor_id, visualizacoes
-- Migra o campo texto 'categoria' para a FK inteira e remove a coluna antiga.

-- 1. Adiciona as novas colunas
ALTER TABLE `knowledge_base`
  ADD COLUMN `categoria_id`  int          NULL         AFTER `conteudo`,
  ADD COLUMN `publico`       tinyint(1)   NOT NULL DEFAULT 1 AFTER `categoria_id`,
  ADD COLUMN `autor_id`      int          NULL         AFTER `publico`,
  ADD COLUMN `visualizacoes` int          NOT NULL DEFAULT 0 AFTER `autor_id`;

-- 2. Remove a coluna texto legada (não havia dados reais)
ALTER TABLE `knowledge_base`
  DROP COLUMN `categoria`;

-- 3. Referências externas
ALTER TABLE `knowledge_base`
  ADD CONSTRAINT `fk_kb_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_kb_autor`     FOREIGN KEY (`autor_id`)     REFERENCES `usuarios`(`id`)   ON DELETE SET NULL;
