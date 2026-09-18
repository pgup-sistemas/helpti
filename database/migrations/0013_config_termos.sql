-- ============================================================
-- 0013_config_termos.sql — Tabela de configuração do modelo de termo de uso
-- ============================================================

CREATE TABLE IF NOT EXISTS `config_termos` (
  `chave`        VARCHAR(60)   NOT NULL,
  `valor`        TEXT          DEFAULT NULL,
  `atualizado_em` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Configurações do modelo de Termo de Guarda e Uso de Equipamento';

-- Valores padrão
INSERT IGNORE INTO `config_termos` (chave, valor) VALUES
  ('titulo',        'Termo de Responsabilidade pelo Uso e Guarda de Equipamento'),
  ('subtitulo',     'Documento de controle de ativo de TI'),
  ('assinatura_ti', 'Setor de Tecnologia da Informação'),
  ('rodape',        ''),
  ('clausulas',     '1. Responsabilidade. O colaborador identificado neste documento assume total responsabilidade pelo equipamento descrito acima, comprometendo-se a utilizá-lo exclusivamente para fins profissionais.

2. Conservação. O equipamento deve ser mantido em perfeito estado de conservação, evitando danos, avarias ou mau uso. Qualquer ocorrência deverá ser comunicada imediatamente ao setor de TI.

3. Devolução. O colaborador se compromete a devolver o equipamento nas mesmas condições de recebimento ao término do contrato, mudança de função ou quando solicitado pela empresa.

4. Segurança. É vedada a instalação de softwares não autorizados, o acesso a conteúdos impróprios ou qualquer atividade que comprometa a segurança da informação da empresa.

5. Penalidades. O descumprimento das cláusulas deste termo sujeitará o colaborador às medidas disciplinares previstas no regulamento interno e à responsabilização pelos danos causados.');
