-- Migração V3: módulo Substâncias (redução de danos, privado). Pré-requisito: V2.
-- MySQL 5.7+ / MariaDB 10.3+. SQL simples (sem information_schema, PREPARE ou variáveis @), pois o usuário
-- do phpMyAdmin da hospedagem não pode consultar information_schema (#1044).
-- Pode rodar de novo: CREATE TABLE IF NOT EXISTS é ignorado, e se um ALTER mostrar
-- "#1060 Duplicate column name" (ou "#1061 Duplicate key name"), essa linha já foi aplicada — apague do
-- editor as linhas até ela (inclusive) e clique em Executar de novo para rodar o restante.
SET NAMES utf8mb4;

ALTER TABLE recipients ADD COLUMN include_substances TINYINT(1) NOT NULL DEFAULT 0 AFTER include_sexuality;

CREATE TABLE IF NOT EXISTS substance_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  entry_date DATE NOT NULL,
  period VARCHAR(10) NULL,
  substance VARCHAR(20) NOT NULL,
  form VARCHAR(20) NULL,
  other_name VARCHAR(200) NULL,
  amount DECIMAL(6,1) NULL,
  reason VARCHAR(20) NULL,
  mood_effect TINYINT NULL,
  above_usual TINYINT(1) NULL,
  migrated TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sub_user_day (user_id, entry_date),
  CONSTRAINT fk_sub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Texto livre antigo (daily_markers.substances) vira registro "Outra" com o mesmo texto, e depois o campo é limpo.
INSERT INTO substance_log (user_id, entry_date, substance, other_name, migrated)
  SELECT dm.user_id, dm.entry_date, 'outra', LEFT(dm.substances, 200), 1 FROM daily_markers dm
  WHERE dm.substances IS NOT NULL AND TRIM(dm.substances) <> ''
    AND NOT EXISTS (SELECT 1 FROM substance_log s WHERE s.user_id = dm.user_id AND s.entry_date = dm.entry_date AND s.migrated = 1);
UPDATE daily_markers SET substances = NULL WHERE substances IS NOT NULL;

-- Conferência (1 linha e a tabela substance_log):
SHOW COLUMNS FROM recipients LIKE 'include_substances';
SHOW TABLES LIKE 'substance_log';
