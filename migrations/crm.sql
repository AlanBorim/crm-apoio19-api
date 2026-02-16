-- phpMyAdmin SQL Dump
-- version 5.2.1deb1+deb12u1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Tempo de geração: 14/02/2026 às 02:06
-- Versão do servidor: 10.11.14-MariaDB-0+deb12u2
-- Versão do PHP: 8.2.29

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `crm`
--

-- --------------------------------------------------------

--
-- Estrutura para tabela `atividade_logs`
--

CREATE TABLE `atividade_logs` (
  `id` int(11) NOT NULL,
  `tarefa_id` int(11) DEFAULT NULL,
  `coluna_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `acao` enum('create','update','delete','move','comment','assign') NOT NULL,
  `descricao` text NOT NULL,
  `valor_antigo` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`valor_antigo`)),
  `valor_novo` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`valor_novo`)),
  `criado_em` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `companies`
--

CREATE TABLE `companies` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `cnpj` varchar(18) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `zip_code` varchar(10) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `contacts`
--

CREATE TABLE `contacts` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `whatsapp_contact_id` int(11) DEFAULT NULL COMMENT 'Vinculação com contato WhatsApp'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `historico_interacoes`
--

CREATE TABLE `historico_interacoes` (
  `id` int(10) UNSIGNED NOT NULL,
  `lead_id` int(10) NOT NULL,
  `contato_id` int(10) DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `temperatura` enum('frio','morno','quente') DEFAULT NULL,
  `acao` text NOT NULL,
  `observacao` text DEFAULT NULL,
  `data` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `historico_propostas`
--

CREATE TABLE `historico_propostas` (
  `id` int(11) NOT NULL,
  `proposta_id` int(11) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `acao` varchar(255) NOT NULL,
  `detalhes` text DEFAULT NULL,
  `data_acao` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `kanban_colunas`
--

CREATE TABLE `kanban_colunas` (
  `id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `ordem` int(11) NOT NULL DEFAULT 0,
  `cor` varchar(20) DEFAULT NULL,
  `limite_cards` int(11) DEFAULT NULL,
  `criado_em` timestamp NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `leads`
--

CREATE TABLE `leads` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `contact_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `company` varchar(255) NOT NULL,
  `position` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `source` varchar(100) DEFAULT NULL,
  `source_extra` varchar(250) DEFAULT NULL,
  `interest` text DEFAULT NULL,
  `temperature` enum('frio','morno','quente') DEFAULT 'frio',
  `stage` enum('novo','contatado','reuniao','proposta','fechado','perdido') DEFAULT 'novo',
  `assigned_to` int(11) DEFAULT NULL,
  `cep` varchar(10) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `value` int(20) DEFAULT NULL,
  `last_contact` date DEFAULT NULL,
  `next_contact` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `active` enum('0','1') NOT NULL DEFAULT '1',
  `whatsapp_contact_id` int(11) DEFAULT NULL COMMENT 'Vinculação com contato WhatsApp'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `lead_settings`
--

CREATE TABLE `lead_settings` (
  `id` int(11) NOT NULL,
  `type` enum('stage','temperature','source') NOT NULL,
  `value` varchar(100) NOT NULL,
  `meta_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_config`)),
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `login_rate_limit`
--

CREATE TABLE `login_rate_limit` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_account` varchar(255) NOT NULL,
  `device_fp` char(64) NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `endpoint` varchar(250) DEFAULT NULL,
  `type` enum('info','warning','error','success') DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `readed_at` datetime DEFAULT NULL,
  `active` enum('0','1') NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `proposals`
--

CREATE TABLE `proposals` (
  `id` int(11) NOT NULL,
  `lead_id` int(11) DEFAULT NULL COMMENT 'Lead relacionado (obrigatório)',
  `contact_id` int(11) DEFAULT NULL COMMENT 'Contato relacionado (opcional)',
  `company_id` int(11) DEFAULT NULL COMMENT 'Empresa relacionada (opcional)',
  `responsavel_id` int(11) DEFAULT NULL,
  `titulo` varchar(255) NOT NULL,
  `descricao` text DEFAULT NULL,
  `condicoes` text DEFAULT NULL,
  `valor_total` decimal(10,2) DEFAULT NULL,
  `status` enum('rascunho','enviada','aceita','rejeitada','em_negociacao') DEFAULT 'rascunho',
  `data_envio` date DEFAULT NULL,
  `data_validade` date DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `modelo_id` int(11) DEFAULT NULL COMMENT 'Template utilizado (opcional)',
  `observacoes` text DEFAULT NULL,
  `criado_em` timestamp NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `proposal_templates`
--

CREATE TABLE `proposal_templates` (
  `id` int(11) NOT NULL,
  `nome` varchar(255) NOT NULL COMMENT 'Nome do template',
  `descricao` text DEFAULT NULL COMMENT 'Descrição do template',
  `conteudo_padrao` text DEFAULT NULL COMMENT 'Conteúdo padrão da proposta',
  `condicoes_padrao` text DEFAULT NULL COMMENT 'Condições padrão',
  `observacoes` text DEFAULT NULL COMMENT 'Observações sobre o uso do template',
  `ativo` tinyint(1) DEFAULT 1 COMMENT 'Template ativo ou não',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Templates de propostas comerciais';

-- --------------------------------------------------------

--
-- Estrutura para tabela `proposta_itens`
--

CREATE TABLE `proposta_itens` (
  `id` int(11) NOT NULL,
  `proposal_id` int(11) DEFAULT NULL,
  `description` text NOT NULL,
  `quantity` int(11) DEFAULT 1,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `total_price` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `tarefas`
--

CREATE TABLE `tarefas` (
  `id` int(11) NOT NULL,
  `titulo` varchar(255) NOT NULL,
  `descricao` text DEFAULT NULL,
  `kanban_coluna_id` int(11) DEFAULT NULL,
  `responsavel_id` int(11) DEFAULT NULL,
  `criador_id` int(11) DEFAULT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `contato_id` int(11) DEFAULT NULL,
  `proposta_id` int(11) DEFAULT NULL,
  `data_vencimento` date DEFAULT NULL,
  `prioridade` enum('baixa','media','alta') DEFAULT 'media',
  `concluida` tinyint(1) DEFAULT 0,
  `data_conclusao` datetime DEFAULT NULL,
  `ordem_na_coluna` int(11) DEFAULT 0,
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `criado_em` timestamp NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `tarefas_usuario`
--

CREATE TABLE `tarefas_usuario` (
  `id` int(11) NOT NULL,
  `titulo` varchar(255) NOT NULL,
  `descricao` text DEFAULT NULL,
  `data_vencimento` datetime DEFAULT NULL,
  `prioridade` enum('baixa','media','alta') DEFAULT 'media',
  `status` enum('pendente','em_andamento','concluida') DEFAULT 'pendente',
  `usuario_id` int(11) NOT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `tarefa_comentarios`
--

CREATE TABLE `tarefa_comentarios` (
  `id` int(11) NOT NULL,
  `tarefa_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `conteudo` text NOT NULL,
  `criado_em` timestamp NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `tarefa_responsaveis`
--

CREATE TABLE `tarefa_responsaveis` (
  `id` int(11) NOT NULL,
  `tarefa_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `criado_em` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','gerente','vendedor','suporte','comercial','financeiro') DEFAULT 'comercial',
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `2fa_secret` text DEFAULT NULL,
  `active` enum('0','1') NOT NULL,
  `password_reset_expires_at` datetime DEFAULT NULL,
  `password_reset_token` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `whatsapp_campaigns`
--

CREATE TABLE `whatsapp_campaigns` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `phone_number_id` bigint(20) DEFAULT NULL COMMENT 'Número de telefone usado na campanha',
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('draft','scheduled','processing','completed','cancelled') NOT NULL DEFAULT 'draft',
  `scheduled_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `whatsapp_campaign_access`
--

CREATE TABLE `whatsapp_campaign_access` (
  `id` int(11) NOT NULL,
  `campaign_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `whatsapp_campaign_errors`
--

CREATE TABLE `whatsapp_campaign_errors` (
  `id` int(11) NOT NULL,
  `campaign_id` int(11) NOT NULL,
  `flow_id` int(11) DEFAULT NULL,
  `contact_id` int(11) DEFAULT NULL,
  `message_id` int(11) DEFAULT NULL,
  `error_type` varchar(100) NOT NULL COMMENT 'Tipo do erro',
  `error_code` varchar(50) DEFAULT NULL COMMENT 'Código do erro da API',
  `error_message` text NOT NULL COMMENT 'Mensagem de erro',
  `error_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Detalhes completos do erro' CHECK (json_valid(`error_details`)),
  `resolution_status` enum('pending','resolved','ignored') DEFAULT 'pending',
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL COMMENT 'Usuário que resolveu',
  `resolution_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `whatsapp_campaign_messages`
--

CREATE TABLE `whatsapp_campaign_messages` (
  `id` int(11) NOT NULL,
  `campaign_id` int(11) DEFAULT NULL,
  `flow_id` int(11) DEFAULT NULL COMMENT 'Fluxo associado',
  `step_id` int(11) DEFAULT NULL COMMENT 'Etapa do fluxo',
  `contact_id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL,
  `phone_number_id` bigint(20) DEFAULT NULL COMMENT 'ID do número de telefone usado para enviar',
  `message_id` varchar(255) DEFAULT NULL COMMENT 'ID da mensagem retornado pela API',
  `status` enum('pending','sent','delivered','read','failed') NOT NULL DEFAULT 'pending',
  `template_params` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`template_params`)),
  `sent_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `failed_at` datetime DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `failure_message` text DEFAULT NULL COMMENT 'Error message if delivery failed'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `whatsapp_chat_messages`
--

CREATE TABLE `whatsapp_chat_messages` (
  `id` int(11) NOT NULL,
  `contact_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `phone_number_id` bigint(20) DEFAULT NULL COMMENT 'Phone Number ID da Meta API (não é FK)',
  `direction` enum('outgoing','incoming') NOT NULL,
  `message_type` varchar(50) NOT NULL DEFAULT 'text',
  `message_content` text NOT NULL,
  `media_url` varchar(500) DEFAULT NULL,
  `whatsapp_message_id` varchar(255) DEFAULT NULL,
  `status` enum('pending','sent','delivered','read','failed') NOT NULL DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `whatsapp_contacts`
--

CREATE TABLE `whatsapp_contacts` (
  `id` int(11) NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `lead_id` int(11) DEFAULT NULL COMMENT 'Vinculação com lead do CRM',
  `contact_id` int(11) DEFAULT NULL COMMENT 'Vinculação com contato do CRM',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `whatsapp_flow_contact_progress`
--

CREATE TABLE `whatsapp_flow_contact_progress` (
  `id` int(11) NOT NULL,
  `flow_id` int(11) NOT NULL,
  `contact_id` int(11) NOT NULL,
  `current_step_id` int(11) DEFAULT NULL COMMENT 'Etapa atual do contato',
  `status` enum('pending','in_progress','waiting_response','completed','failed','paused') NOT NULL DEFAULT 'pending',
  `last_message_id` int(11) DEFAULT NULL COMMENT 'Última mensagem enviada',
  `last_message_sent_at` datetime DEFAULT NULL,
  `last_response_received_at` datetime DEFAULT NULL,
  `next_scheduled_at` datetime DEFAULT NULL COMMENT 'Próximo envio agendado',
  `error_count` int(11) DEFAULT 0 COMMENT 'Contador de erros',
  `last_error` text DEFAULT NULL COMMENT 'Último erro ocorrido',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Dados adicionais do progresso' CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `whatsapp_flow_steps`
--

CREATE TABLE `whatsapp_flow_steps` (
  `id` int(11) NOT NULL,
  `flow_id` int(11) NOT NULL COMMENT 'Fluxo associado',
  `step_order` int(11) NOT NULL COMMENT 'Ordem da etapa no fluxo',
  `step_name` varchar(255) NOT NULL COMMENT 'Nome da etapa',
  `template_id` int(11) NOT NULL COMMENT 'Template a ser enviado',
  `template_variables` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Variáveis do template' CHECK (json_valid(`template_variables`)),
  `delay_days` int(11) DEFAULT 0 COMMENT 'Dias de espera desde etapa anterior',
  `delay_hours` int(11) DEFAULT 0 COMMENT 'Horas de espera adicionais',
  `wait_for_response` tinyint(1) DEFAULT 0 COMMENT 'Aguardar resposta antes de próxima etapa',
  `response_timeout_hours` int(11) DEFAULT 24 COMMENT 'Timeout para resposta (horas)',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `whatsapp_flow_step_conditions`
--

CREATE TABLE `whatsapp_flow_step_conditions` (
  `id` int(11) NOT NULL,
  `step_id` int(11) NOT NULL COMMENT 'Etapa que aguarda resposta',
  `condition_type` enum('keyword','button','any','none') NOT NULL COMMENT 'Tipo de condição',
  `condition_value` varchar(500) DEFAULT NULL COMMENT 'Valor esperado (palavra-chave, ID do botão)',
  `next_step_id` int(11) DEFAULT NULL COMMENT 'Próxima etapa se condição atender',
  `alternative_template_id` int(11) DEFAULT NULL COMMENT 'Template alternativo a enviar',
  `action` enum('continue','goto_step','send_template','end_flow') NOT NULL DEFAULT 'continue',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `whatsapp_message_flows`
--

CREATE TABLE `whatsapp_message_flows` (
  `id` int(11) NOT NULL,
  `campaign_id` int(11) NOT NULL COMMENT 'Campanha associada',
  `phone_number_id` int(11) NOT NULL COMMENT 'Número de telefone usado',
  `name` varchar(255) NOT NULL COMMENT 'Nome do fluxo',
  `description` text DEFAULT NULL,
  `status` enum('draft','active','paused','completed','cancelled') NOT NULL DEFAULT 'draft',
  `start_date` datetime DEFAULT NULL COMMENT 'Data de início do fluxo',
  `end_date` datetime DEFAULT NULL COMMENT 'Data de término',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `whatsapp_message_responses`
--

CREATE TABLE `whatsapp_message_responses` (
  `id` int(11) NOT NULL,
  `message_id` int(11) DEFAULT NULL,
  `from_number` varchar(20) NOT NULL,
  `response_text` text NOT NULL,
  `response_type` varchar(50) NOT NULL DEFAULT 'text',
  `media_url` varchar(500) DEFAULT NULL,
  `received_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `whatsapp_phone_numbers`
--

CREATE TABLE `whatsapp_phone_numbers` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL COMMENT 'Nome identificador do número',
  `phone_number` varchar(20) NOT NULL COMMENT 'Número no formato internacional',
  `phone_number_id` bigint(20) NOT NULL COMMENT 'Phone Number ID da API',
  `business_account_id` varchar(255) NOT NULL COMMENT 'Business Account ID',
  `access_token` text NOT NULL COMMENT 'Token de acesso',
  `webhook_verify_token` varchar(255) DEFAULT NULL COMMENT 'Token de verificação do webhook',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `daily_limit` int(11) DEFAULT 1000 COMMENT 'Limite diário de mensagens',
  `current_daily_count` int(11) DEFAULT 0 COMMENT 'Contador diário atual',
  `last_reset_date` date DEFAULT NULL COMMENT 'Última data de reset do contador',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Metadados adicionais' CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `whatsapp_scheduled_messages`
--

CREATE TABLE `whatsapp_scheduled_messages` (
  `id` int(11) NOT NULL,
  `campaign_id` int(11) NOT NULL,
  `cron_expression` varchar(100) NOT NULL,
  `next_run` datetime NOT NULL,
  `last_run` datetime DEFAULT NULL,
  `status` enum('active','paused','completed') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `whatsapp_settings`
--

CREATE TABLE `whatsapp_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `whatsapp_templates`
--

CREATE TABLE `whatsapp_templates` (
  `id` int(11) NOT NULL,
  `template_id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `language` varchar(10) NOT NULL DEFAULT 'pt_BR',
  `category` varchar(50) NOT NULL,
  `status` enum('APPROVED','PENDING','REJECTED') NOT NULL DEFAULT 'PENDING',
  `components` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`components`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `atividade_logs`
--
ALTER TABLE `atividade_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tarefa` (`tarefa_id`),
  ADD KEY `idx_coluna` (`coluna_id`),
  ADD KEY `idx_usuario` (`usuario_id`),
  ADD KEY `idx_acao` (`acao`),
  ADD KEY `idx_criado_em` (`criado_em`);

--
-- Índices de tabela `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Índices de tabela `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `contacts`
--
ALTER TABLE `contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `idx_whatsapp_contact` (`whatsapp_contact_id`);

--
-- Índices de tabela `historico_interacoes`
--
ALTER TABLE `historico_interacoes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lead_id` (`lead_id`),
  ADD KEY `users_id` (`usuario_id`);

--
-- Índices de tabela `historico_propostas`
--
ALTER TABLE `historico_propostas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_proposta` (`proposta_id`),
  ADD KEY `idx_usuario` (`usuario_id`),
  ADD KEY `idx_data_acao` (`data_acao`);

--
-- Índices de tabela `kanban_colunas`
--
ALTER TABLE `kanban_colunas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ordem` (`ordem`);

--
-- Índices de tabela `leads`
--
ALTER TABLE `leads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `contact_id` (`contact_id`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `idx_whatsapp_contact` (`whatsapp_contact_id`);

--
-- Índices de tabela `lead_settings`
--
ALTER TABLE `lead_settings`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `login_rate_limit`
--
ALTER TABLE `login_rate_limit`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_iru` (`ip_address`,`user_account`,`failed_at`),
  ADD KEY `idx_account` (`user_account`,`failed_at`);

--
-- Índices de tabela `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Índices de tabela `proposals`
--
ALTER TABLE `proposals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_proposals_contact` (`contact_id`),
  ADD KEY `fk_proposals_company` (`company_id`),
  ADD KEY `fk_proposals_responsavel` (`responsavel_id`),
  ADD KEY `fk_proposals_lead` (`lead_id`),
  ADD KEY `fk_proposals_template` (`modelo_id`);

--
-- Índices de tabela `proposal_templates`
--
ALTER TABLE `proposal_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ativo` (`ativo`);

--
-- Índices de tabela `proposta_itens`
--
ALTER TABLE `proposta_itens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `proposal_id` (`proposal_id`);

--
-- Índices de tabela `tarefas`
--
ALTER TABLE `tarefas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_coluna` (`kanban_coluna_id`),
  ADD KEY `idx_responsavel` (`responsavel_id`),
  ADD KEY `idx_criador` (`criador_id`),
  ADD KEY `idx_lead` (`lead_id`),
  ADD KEY `idx_contato` (`contato_id`),
  ADD KEY `idx_proposta` (`proposta_id`),
  ADD KEY `idx_ordem` (`ordem_na_coluna`);

--
-- Índices de tabela `tarefas_usuario`
--
ALTER TABLE `tarefas_usuario`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`),
  ADD KEY `lead_id` (`lead_id`);

--
-- Índices de tabela `tarefa_comentarios`
--
ALTER TABLE `tarefa_comentarios`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tarefa` (`tarefa_id`),
  ADD KEY `idx_usuario` (`usuario_id`);

--
-- Índices de tabela `tarefa_responsaveis`
--
ALTER TABLE `tarefa_responsaveis`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_tarefa_usuario` (`tarefa_id`,`usuario_id`),
  ADD KEY `idx_tarefa` (`tarefa_id`),
  ADD KEY `idx_usuario` (`usuario_id`);

--
-- Índices de tabela `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Índices de tabela `whatsapp_campaigns`
--
ALTER TABLE `whatsapp_campaigns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_scheduled_at` (`scheduled_at`);

--
-- Índices de tabela `whatsapp_campaign_access`
--
ALTER TABLE `whatsapp_campaign_access`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_access` (`campaign_id`,`user_id`),
  ADD KEY `idx_campaign_id` (`campaign_id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Índices de tabela `whatsapp_campaign_errors`
--
ALTER TABLE `whatsapp_campaign_errors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `flow_id` (`flow_id`),
  ADD KEY `contact_id` (`contact_id`),
  ADD KEY `message_id` (`message_id`),
  ADD KEY `resolved_by` (`resolved_by`),
  ADD KEY `idx_campaign` (`campaign_id`),
  ADD KEY `idx_resolution_status` (`resolution_status`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Índices de tabela `whatsapp_campaign_messages`
--
ALTER TABLE `whatsapp_campaign_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `template_id` (`template_id`),
  ADD KEY `idx_campaign_id` (`campaign_id`),
  ADD KEY `idx_contact_id` (`contact_id`),
  ADD KEY `idx_message_id` (`message_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_sent_at` (`sent_at`),
  ADD KEY `idx_messages_flow` (`flow_id`,`contact_id`),
  ADD KEY `idx_messages_step` (`step_id`),
  ADD KEY `idx_wamid` (`message_id`);

--
-- Índices de tabela `whatsapp_chat_messages`
--
ALTER TABLE `whatsapp_chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_contact_id` (`contact_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_direction` (`direction`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_whatsapp_message_id` (`whatsapp_message_id`),
  ADD KEY `idx_contact_created` (`contact_id`,`created_at`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_phone_number_id` (`phone_number_id`);

--
-- Índices de tabela `whatsapp_contacts`
--
ALTER TABLE `whatsapp_contacts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `phone_number` (`phone_number`),
  ADD KEY `idx_phone_number` (`phone_number`),
  ADD KEY `idx_lead_id` (`lead_id`),
  ADD KEY `idx_contact_id` (`contact_id`);

--
-- Índices de tabela `whatsapp_flow_contact_progress`
--
ALTER TABLE `whatsapp_flow_contact_progress`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_flow_contact` (`flow_id`,`contact_id`),
  ADD KEY `contact_id` (`contact_id`),
  ADD KEY `current_step_id` (`current_step_id`),
  ADD KEY `last_message_id` (`last_message_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_next_scheduled` (`next_scheduled_at`),
  ADD KEY `idx_flow` (`flow_id`);

--
-- Índices de tabela `whatsapp_flow_steps`
--
ALTER TABLE `whatsapp_flow_steps`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_flow_step_order` (`flow_id`,`step_order`),
  ADD KEY `template_id` (`template_id`),
  ADD KEY `idx_flow` (`flow_id`),
  ADD KEY `idx_step_order` (`flow_id`,`step_order`);

--
-- Índices de tabela `whatsapp_flow_step_conditions`
--
ALTER TABLE `whatsapp_flow_step_conditions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `next_step_id` (`next_step_id`),
  ADD KEY `alternative_template_id` (`alternative_template_id`),
  ADD KEY `idx_step` (`step_id`);

--
-- Índices de tabela `whatsapp_message_flows`
--
ALTER TABLE `whatsapp_message_flows`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_campaign` (`campaign_id`),
  ADD KEY `idx_status` (`status`);

--
-- Índices de tabela `whatsapp_message_responses`
--
ALTER TABLE `whatsapp_message_responses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_message_id` (`message_id`),
  ADD KEY `idx_from_number` (`from_number`),
  ADD KEY `idx_received_at` (`received_at`);

--
-- Índices de tabela `whatsapp_phone_numbers`
--
ALTER TABLE `whatsapp_phone_numbers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_phone_number` (`phone_number`),
  ADD KEY `idx_status` (`status`);

--
-- Índices de tabela `whatsapp_scheduled_messages`
--
ALTER TABLE `whatsapp_scheduled_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_campaign_id` (`campaign_id`),
  ADD KEY `idx_next_run` (`next_run`),
  ADD KEY `idx_status` (`status`);

--
-- Índices de tabela `whatsapp_settings`
--
ALTER TABLE `whatsapp_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_setting_key` (`setting_key`);

--
-- Índices de tabela `whatsapp_templates`
--
ALTER TABLE `whatsapp_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_template_name` (`name`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_category` (`category`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `atividade_logs`
--
ALTER TABLE `atividade_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `contacts`
--
ALTER TABLE `contacts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `historico_interacoes`
--
ALTER TABLE `historico_interacoes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `historico_propostas`
--
ALTER TABLE `historico_propostas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `kanban_colunas`
--
ALTER TABLE `kanban_colunas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `leads`
--
ALTER TABLE `leads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `lead_settings`
--
ALTER TABLE `lead_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `login_rate_limit`
--
ALTER TABLE `login_rate_limit`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `proposals`
--
ALTER TABLE `proposals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `proposal_templates`
--
ALTER TABLE `proposal_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `proposta_itens`
--
ALTER TABLE `proposta_itens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `tarefas`
--
ALTER TABLE `tarefas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `tarefas_usuario`
--
ALTER TABLE `tarefas_usuario`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `tarefa_comentarios`
--
ALTER TABLE `tarefa_comentarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `tarefa_responsaveis`
--
ALTER TABLE `tarefa_responsaveis`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `whatsapp_campaigns`
--
ALTER TABLE `whatsapp_campaigns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `whatsapp_campaign_access`
--
ALTER TABLE `whatsapp_campaign_access`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `whatsapp_campaign_errors`
--
ALTER TABLE `whatsapp_campaign_errors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `whatsapp_campaign_messages`
--
ALTER TABLE `whatsapp_campaign_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `whatsapp_chat_messages`
--
ALTER TABLE `whatsapp_chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `whatsapp_contacts`
--
ALTER TABLE `whatsapp_contacts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `whatsapp_flow_contact_progress`
--
ALTER TABLE `whatsapp_flow_contact_progress`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `whatsapp_flow_steps`
--
ALTER TABLE `whatsapp_flow_steps`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `whatsapp_flow_step_conditions`
--
ALTER TABLE `whatsapp_flow_step_conditions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `whatsapp_message_flows`
--
ALTER TABLE `whatsapp_message_flows`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `whatsapp_message_responses`
--
ALTER TABLE `whatsapp_message_responses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `whatsapp_phone_numbers`
--
ALTER TABLE `whatsapp_phone_numbers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `whatsapp_scheduled_messages`
--
ALTER TABLE `whatsapp_scheduled_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `whatsapp_settings`
--
ALTER TABLE `whatsapp_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `whatsapp_templates`
--
ALTER TABLE `whatsapp_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `atividade_logs`
--
ALTER TABLE `atividade_logs`
  ADD CONSTRAINT `fk_atividade_logs_coluna` FOREIGN KEY (`coluna_id`) REFERENCES `kanban_colunas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_atividade_logs_tarefa` FOREIGN KEY (`tarefa_id`) REFERENCES `tarefas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Restrições para tabelas `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `contacts`
--
ALTER TABLE `contacts`
  ADD CONSTRAINT `contacts_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `historico_interacoes`
--
ALTER TABLE `historico_interacoes`
  ADD CONSTRAINT `lead_id` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  ADD CONSTRAINT `users_id` FOREIGN KEY (`usuario_id`) REFERENCES `users` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION;

--
-- Restrições para tabelas `historico_propostas`
--
ALTER TABLE `historico_propostas`
  ADD CONSTRAINT `fk_historico_propostas_proposta` FOREIGN KEY (`proposta_id`) REFERENCES `proposals` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_historico_propostas_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `leads`
--
ALTER TABLE `leads`
  ADD CONSTRAINT `leads_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leads_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leads_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `proposals`
--
ALTER TABLE `proposals`
  ADD CONSTRAINT `fk_proposals_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_proposals_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_proposals_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_proposals_responsavel` FOREIGN KEY (`responsavel_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_proposals_template` FOREIGN KEY (`modelo_id`) REFERENCES `proposal_templates` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `proposals_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `proposta_itens`
--
ALTER TABLE `proposta_itens`
  ADD CONSTRAINT `proposta_itens_ibfk_1` FOREIGN KEY (`proposal_id`) REFERENCES `proposals` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `tarefas_usuario`
--
ALTER TABLE `tarefas_usuario`
  ADD CONSTRAINT `tarefas_usuario_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tarefas_usuario_ibfk_2` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `tarefa_comentarios`
--
ALTER TABLE `tarefa_comentarios`
  ADD CONSTRAINT `fk_tarefa_comentarios_tarefa` FOREIGN KEY (`tarefa_id`) REFERENCES `tarefas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Restrições para tabelas `tarefa_responsaveis`
--
ALTER TABLE `tarefa_responsaveis`
  ADD CONSTRAINT `fk_tarefa_responsaveis_tarefa` FOREIGN KEY (`tarefa_id`) REFERENCES `tarefas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Restrições para tabelas `whatsapp_campaigns`
--
ALTER TABLE `whatsapp_campaigns`
  ADD CONSTRAINT `whatsapp_campaigns_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `whatsapp_campaign_access`
--
ALTER TABLE `whatsapp_campaign_access`
  ADD CONSTRAINT `whatsapp_campaign_access_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `whatsapp_campaigns` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `whatsapp_campaign_access_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `whatsapp_campaign_errors`
--
ALTER TABLE `whatsapp_campaign_errors`
  ADD CONSTRAINT `whatsapp_campaign_errors_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `whatsapp_campaigns` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `whatsapp_campaign_errors_ibfk_2` FOREIGN KEY (`flow_id`) REFERENCES `whatsapp_message_flows` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `whatsapp_campaign_errors_ibfk_3` FOREIGN KEY (`contact_id`) REFERENCES `whatsapp_contacts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `whatsapp_campaign_errors_ibfk_4` FOREIGN KEY (`message_id`) REFERENCES `whatsapp_campaign_messages` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `whatsapp_campaign_errors_ibfk_5` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `whatsapp_campaign_messages`
--
ALTER TABLE `whatsapp_campaign_messages`
  ADD CONSTRAINT `whatsapp_campaign_messages_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `whatsapp_campaigns` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `whatsapp_campaign_messages_ibfk_2` FOREIGN KEY (`flow_id`) REFERENCES `whatsapp_message_flows` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `whatsapp_campaign_messages_ibfk_3` FOREIGN KEY (`step_id`) REFERENCES `whatsapp_flow_steps` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `whatsapp_campaign_messages_ibfk_4` FOREIGN KEY (`contact_id`) REFERENCES `whatsapp_contacts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `whatsapp_campaign_messages_ibfk_5` FOREIGN KEY (`template_id`) REFERENCES `whatsapp_templates` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `whatsapp_chat_messages`
--
ALTER TABLE `whatsapp_chat_messages`
  ADD CONSTRAINT `whatsapp_chat_messages_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `whatsapp_contacts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `whatsapp_chat_messages_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `whatsapp_contacts`
--
ALTER TABLE `whatsapp_contacts`
  ADD CONSTRAINT `whatsapp_contacts_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `whatsapp_contacts_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `whatsapp_flow_contact_progress`
--
ALTER TABLE `whatsapp_flow_contact_progress`
  ADD CONSTRAINT `whatsapp_flow_contact_progress_ibfk_1` FOREIGN KEY (`flow_id`) REFERENCES `whatsapp_message_flows` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `whatsapp_flow_contact_progress_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `whatsapp_contacts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `whatsapp_flow_contact_progress_ibfk_3` FOREIGN KEY (`current_step_id`) REFERENCES `whatsapp_flow_steps` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `whatsapp_flow_steps`
--
ALTER TABLE `whatsapp_flow_steps`
  ADD CONSTRAINT `whatsapp_flow_steps_ibfk_1` FOREIGN KEY (`flow_id`) REFERENCES `whatsapp_message_flows` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `whatsapp_flow_steps_ibfk_2` FOREIGN KEY (`template_id`) REFERENCES `whatsapp_templates` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `whatsapp_flow_step_conditions`
--
ALTER TABLE `whatsapp_flow_step_conditions`
  ADD CONSTRAINT `whatsapp_flow_step_conditions_ibfk_1` FOREIGN KEY (`step_id`) REFERENCES `whatsapp_flow_steps` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `whatsapp_flow_step_conditions_ibfk_2` FOREIGN KEY (`next_step_id`) REFERENCES `whatsapp_flow_steps` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `whatsapp_flow_step_conditions_ibfk_3` FOREIGN KEY (`alternative_template_id`) REFERENCES `whatsapp_templates` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `whatsapp_message_flows`
--
ALTER TABLE `whatsapp_message_flows`
  ADD CONSTRAINT `whatsapp_message_flows_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `whatsapp_campaigns` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `whatsapp_message_flows_ibfk_2` FOREIGN KEY (`phone_number_id`) REFERENCES `whatsapp_phone_numbers` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `whatsapp_message_responses`
--
ALTER TABLE `whatsapp_message_responses`
  ADD CONSTRAINT `whatsapp_message_responses_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `whatsapp_campaign_messages` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `whatsapp_scheduled_messages`
--
ALTER TABLE `whatsapp_scheduled_messages`
  ADD CONSTRAINT `whatsapp_scheduled_messages_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `whatsapp_campaigns` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
