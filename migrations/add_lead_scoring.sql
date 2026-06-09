-- Migration: add_lead_scoring.sql
-- Adiciona suporte para Score e Auditoria do Site na tabela de Leads

ALTER TABLE `leads`
  ADD COLUMN `score` INT DEFAULT 0 AFTER `active`,
  ADD COLUMN `site_audit_status` VARCHAR(50) DEFAULT 'Pendente' AFTER `score`,
  ADD COLUMN `site_performance` LONGTEXT DEFAULT NULL AFTER `site_audit_status`,
  ADD COLUMN `site_technologies` LONGTEXT DEFAULT NULL AFTER `site_performance`,
  ADD COLUMN `site_pain_points` LONGTEXT DEFAULT NULL AFTER `site_technologies`;
