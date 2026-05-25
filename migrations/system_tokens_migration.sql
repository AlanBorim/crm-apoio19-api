-- Migration: Create system_tokens table
-- Description: Table to store API system integration keys (long-life tokens) and their custom scopes.

CREATE TABLE IF NOT EXISTS `system_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL COMMENT 'Name of the integration key (e.g. n8n Prospecting)',
  `token` varchar(255) NOT NULL COMMENT 'Cryptographically secure long-life token',
  `user_id` int(11) NOT NULL COMMENT 'CRM User whose context and ID this token acts under',
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'JSON-formatted scopes representing resource actions',
  `active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = Active, 0 = Inactive',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_used_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  CONSTRAINT `fk_system_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
