-- Tabela de Templates de Propostas
CREATE TABLE IF NOT EXISTS `proposal_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL COMMENT 'Nome do template',
  `descricao` text DEFAULT NULL COMMENT 'Descrição do template',
  `conteudo_padrao` text DEFAULT NULL COMMENT 'Conteúdo padrão da proposta',
  `condicoes_padrao` text DEFAULT NULL COMMENT 'Condições padrão',
  `observacoes` text DEFAULT NULL COMMENT 'Observações sobre o uso do template',
  `ativo` tinyint(1) DEFAULT 1 COMMENT 'Template ativo ou não',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Templates de propostas comerciais';

-- Inserir alguns templates padrão
INSERT INTO `proposal_templates` (`nome`, `descricao`, `conteudo_padrao`, `condicoes_padrao`, `ativo`) VALUES
('Proposta Comercial Padrão', 'Template padrão para propostas comerciais', 
'Prezado(a) Cliente,\n\nApresentamos nossa proposta comercial conforme solicitado.\n\n[DETALHES DA PROPOSTA]', 
'Prazo de validade: 30 dias\nForma de pagamento: A negociar\nPrazo de entrega: Conforme acordo', 1),

('Proposta de Serviços', 'Template para propostas de serviços', 
'Prezado(a),\n\nSegue nossa proposta de prestação de serviços.\n\n[ESCOPO DOS SERVIÇOS]', 
'Validade: 15 dias\nPagamento: 50% antecipado, 50% na entrega\nGarantia: 90 dias', 1),

('Proposta Simplificada', 'Template simplificado para propostas rápidas', 
'Olá,\n\nConforme conversamos, segue nossa proposta:\n\n[ITENS]', 
'Validade: 7 dias\nPagamento: À vista ou parcelado', 1);

-- Alterar tabela proposals para tornar contact_id e company_id opcionais
-- (Caso já não sejam NULL por padrão no seu banco)
ALTER TABLE `proposals` 
  MODIFY COLUMN `contact_id` int(11) DEFAULT NULL,
  MODIFY COLUMN `company_id` int(11) DEFAULT NULL,
  MODIFY COLUMN `modelo_id` int(11) DEFAULT NULL;

-- Adicionar comentários explicativos
ALTER TABLE `proposals` 
  MODIFY COLUMN `lead_id` int(11) DEFAULT NULL COMMENT 'Lead relacionado (obrigatório)',
  MODIFY COLUMN `contact_id` int(11) DEFAULT NULL COMMENT 'Contato relacionado (opcional)',
  MODIFY COLUMN `company_id` int(11) DEFAULT NULL COMMENT 'Empresa relacionada (opcional)',
  MODIFY COLUMN `modelo_id` int(11) DEFAULT NULL COMMENT 'Template utilizado (opcional)';

-- Criar Foreign Key para modelo_id (caso não exista)
ALTER TABLE `proposals` 
  ADD CONSTRAINT `fk_proposals_template` 
  FOREIGN KEY (`modelo_id`) REFERENCES `proposal_templates` (`id`) 
  ON DELETE SET NULL 
  ON UPDATE CASCADE;
