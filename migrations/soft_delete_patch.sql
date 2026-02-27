-- Patch SQL para adicionar Soft Delete no CRM Apoio19
-- Este script adiciona a coluna `deleted_at` nas tabelas principais.

ALTER TABLE `leads` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `companies` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `contacts` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `proposals` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `tarefas` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `tarefas_usuario` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `clients` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `client_projects` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `kanban_colunas` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL;

-- Para as tabelas que possuem active, pode ser necessário inicializar deleted_at 
-- de acordo com o valor atual (ex: se active for '0', setar deleted_at = NOW()).
-- Mas para segurança apenas adicionamos a coluna neste script.
