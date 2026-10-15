-- Migração: login por e-mail e senha (Bipolar — Diário de Humor)
-- MySQL 5.7+ / MariaDB 10.3+. SQL simples (sem information_schema, PREPARE ou variáveis @), pois o usuário
-- do phpMyAdmin da hospedagem não pode consultar information_schema (#1044).
-- Pode rodar de novo: CREATE TABLE IF NOT EXISTS é ignorado, e se um ALTER mostrar
-- "#1060 Duplicate column name" (ou "#1061 Duplicate key name"), essa linha já foi aplicada — apague do
-- editor as linhas até ela (inclusive) e clique em Executar de novo para rodar o restante.
SET NAMES utf8mb4;

ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NULL AFTER extreme_alerts, ADD COLUMN email_verified_at DATETIME NULL AFTER password_hash, ADD COLUMN session_epoch INT UNSIGNED NOT NULL DEFAULT 0 AFTER email_verified_at;

CREATE TABLE IF NOT EXISTS auth_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  purpose ENUM('verify','reset') NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token_hash (token_hash),
  KEY idx_user_purpose (user_id, purpose),
  CONSTRAINT fk_at_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conferência (deve listar 3 linhas):
SHOW COLUMNS FROM users WHERE Field IN ('password_hash','email_verified_at','session_epoch');
