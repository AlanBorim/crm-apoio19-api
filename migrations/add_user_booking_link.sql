-- Migration: add_user_booking_link.sql
-- Adiciona coluna de link de agendamento personalizado na tabela de usuários

ALTER TABLE `users`
  ADD COLUMN `booking_link` VARCHAR(255) DEFAULT NULL AFTER `email`;
