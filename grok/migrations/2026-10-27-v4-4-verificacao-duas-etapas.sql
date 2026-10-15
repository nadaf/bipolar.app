-- V4.4 - verificacao em duas etapas (MFA) para contas de e-mail e senha.
-- Rode no phpMyAdmin (aba SQL). Pode rodar de novo: se aparecer #1060 Duplicate column name, a coluna ja existe. Pule essa linha e rode as seguintes.
-- Nada existente e alterado: todas as contas comecam com a verificacao em duas etapas desligada.
ALTER TABLE users ADD COLUMN mfa_totp_secret_enc TEXT NULL;
ALTER TABLE users ADD COLUMN mfa_enabled_at DATETIME NULL;
ALTER TABLE users ADD COLUMN mfa_totp_last_step BIGINT UNSIGNED NULL;
CREATE TABLE IF NOT EXISTS mfa_recovery_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mfa_rc (user_id, code_hash),
  CONSTRAINT fk_mfa_rc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
