-- V4.3.1 - controle de versao (concorrencia otimista) para duas abas ou aparelhos editando o mesmo registro.
-- Rode no phpMyAdmin (aba SQL). Pode rodar de novo: se aparecer #1060 Duplicate column name, a coluna ja existe. Pule essa linha e rode as seguintes.
-- Nada existente e alterado: os registros atuais comecam na versao 1.
ALTER TABLE entries ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE alert_rules ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE recipients ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE sexuality_log ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1;
