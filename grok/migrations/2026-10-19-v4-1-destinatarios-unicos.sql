-- Migração V4.1: um destinatário por e-mail para cada pessoa. Pré-requisito: V4.
-- MySQL 5.7+ / MariaDB 10.3+. SQL simples (sem information_schema, PREPARE ou variáveis @), pois o usuário
-- do phpMyAdmin da hospedagem não pode consultar information_schema (#1044).
-- Pode rodar de novo: se o ALTER mostrar "#1061 Duplicate key name" (ou "#1060 Duplicate column name"), essa linha
-- já foi aplicada e não há mais nada a fazer.
SET NAMES utf8mb4;

-- 1) Remove cadastros repetidos do mesmo e-mail para a mesma pessoa, mantendo o mais antigo.
DELETE r1 FROM recipients r1 JOIN recipients r2 ON r1.user_id = r2.user_id AND r1.email = r2.email AND r1.id > r2.id;
-- 2) Impede novas repetições (a comparação ignora maiúsculas/minúsculas).
ALTER TABLE recipients ADD UNIQUE KEY uq_recipient_user_email (user_id, email);

SHOW INDEX FROM recipients WHERE Key_name = 'uq_recipient_user_email';
