-- Migração V2: marcadores diários, módulo Sexualidade (privado) e preferências. Pré-requisito: login por e-mail.
-- MySQL 5.7+ / MariaDB 10.3+. SQL simples (sem information_schema, PREPARE ou variáveis @), pois o usuário
-- do phpMyAdmin da hospedagem não pode consultar information_schema (#1044).
-- Pode rodar de novo: CREATE TABLE IF NOT EXISTS é ignorado, e se um ALTER mostrar
-- "#1060 Duplicate column name" (ou "#1061 Duplicate key name"), essa linha já foi aplicada — apague do
-- editor as linhas até ela (inclusive) e clique em Executar de novo para rodar o restante.
SET NAMES utf8mb4;

ALTER TABLE users ADD COLUMN prefs TEXT NULL AFTER session_epoch, ADD COLUMN sex_pin_hash VARCHAR(255) NULL AFTER prefs;
ALTER TABLE recipients ADD COLUMN include_markers TINYINT(1) NOT NULL DEFAULT 1 AFTER include_media_list, ADD COLUMN include_sexuality TINYINT(1) NOT NULL DEFAULT 0 AFTER include_markers;

CREATE TABLE IF NOT EXISTS daily_markers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  entry_date DATE NOT NULL,
  bed_time CHAR(5) NULL,
  wake_time CHAR(5) NULL,
  sleep_quality TINYINT NULL,
  sun_minutes SMALLINT UNSIGNED NULL,
  sun_period VARCHAR(10) NULL,
  caffeine_cups TINYINT UNSIGNED NULL,
  alcohol_units TINYINT UNSIGNED NULL,
  substances VARCHAR(200) NULL,
  exercise_minutes SMALLINT UNSIGNED NULL,
  racing_thoughts TINYINT NULL,
  fast_speech TINYINT NULL,
  impulsive_spending TINYINT NULL,
  spending_amount DECIMAL(10,2) NULL,
  irritability_day TINYINT NULL,
  cycle_phase VARCHAR(20) NULL,
  stress_level TINYINT NULL,
  stress_note VARCHAR(500) NULL,
  doses_json TEXT NULL,
  signs_json TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dm_user_day (user_id, entry_date),
  CONSTRAINT fk_dm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sexuality_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  entry_date DATE NOT NULL,
  libido TINYINT NULL,
  activities VARCHAR(400) NULL,
  frequency TINYINT UNSIGNED NULL,
  online_minutes SMALLINT UNSIGNED NULL,
  different_from_usual TINYINT(1) NULL,
  note TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sx_user_day (user_id, entry_date),
  CONSTRAINT fk_sx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conferência (deve listar 2 + 2 linhas e as 2 tabelas):
SHOW COLUMNS FROM users WHERE Field IN ('prefs','sex_pin_hash');
SHOW COLUMNS FROM recipients WHERE Field IN ('include_markers','include_sexuality');
SHOW TABLES LIKE '%_log';
