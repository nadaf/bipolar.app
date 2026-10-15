-- V4.3 - IA com provedor e chave propria (BYOK). Rode no phpMyAdmin (aba SQL).
-- Pode rodar de novo: se aparecer #1060 Duplicate column name, a coluna ja existe. Pule essa linha e rode as seguintes.
-- A chave fica cifrada (libsodium secretbox) com ai.secret_key do config.php. Nada existente e alterado.
ALTER TABLE users ADD COLUMN ai_provider VARCHAR(20) NULL;
ALTER TABLE users ADD COLUMN ai_model VARCHAR(100) NULL;
ALTER TABLE users ADD COLUMN ai_base_url VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN ai_key_enc TEXT NULL;
ALTER TABLE users ADD COLUMN ai_key_last4 VARCHAR(4) NULL;
ALTER TABLE users ADD COLUMN ai_key_updated_at DATETIME NULL;
