-- V4.7 - acontecimentos e estresse POR PERIODO (manha, tarde, noite) e o novo item Alimentacao (doces, ultraprocessados, comer fora de hora).
-- Rode no phpMyAdmin (aba SQL). Pode rodar de novo: se aparecer #1060 Duplicate column name, a coluna ja existe. Pule essa linha e rode as seguintes.
-- Nada e apagado. Os valores antigos de estresse (que valiam para o dia inteiro) sao COPIADOS para o ultimo periodo registrado naquele dia
-- (ou para a Noite, se nao houver registro de humor no dia) e marcados com migrated = 1. A copia acontece uma unica vez por dia.
CREATE TABLE IF NOT EXISTS period_markers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  entry_date DATE NOT NULL,
  period ENUM('manha','tarde','noite') NOT NULL,
  stress_level TINYINT NULL,
  stress_note VARCHAR(500) NULL,
  food_sweets TINYINT NULL,
  food_processed TINYINT NULL,
  food_binge TINYINT NULL,
  migrated TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pm_user_day_period (user_id, entry_date, period),
  CONSTRAINT fk_pm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO period_markers (user_id, entry_date, period, stress_level, stress_note, migrated)
  SELECT dm.user_id, dm.entry_date,
    COALESCE((SELECT e.period FROM entries e WHERE e.user_id = dm.user_id AND e.entry_date = dm.entry_date ORDER BY FIELD(e.period, 'manha', 'tarde', 'noite') DESC LIMIT 1), 'noite'),
    dm.stress_level, dm.stress_note, 1
  FROM daily_markers dm
  WHERE (dm.stress_level IS NOT NULL OR dm.stress_note IS NOT NULL)
    AND NOT EXISTS (SELECT 1 FROM period_markers pm WHERE pm.user_id = dm.user_id AND pm.entry_date = dm.entry_date);
ALTER TABLE daily_markers ADD COLUMN food_sweets TINYINT NULL;
ALTER TABLE daily_markers ADD COLUMN food_processed TINYINT NULL;
ALTER TABLE daily_markers ADD COLUMN food_binge TINYINT NULL;
ALTER TABLE recipients ADD COLUMN include_food TINYINT(1) NOT NULL DEFAULT 0;
