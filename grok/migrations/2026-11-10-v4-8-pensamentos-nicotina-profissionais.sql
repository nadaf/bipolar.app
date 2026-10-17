-- V4.8 (0.5.0-beta) - pensamentos por periodo (incluindo pensamentos de morte, privado), nicotina no dia e compartilhamento com profissionais.
-- Rode na aba SQL do banco. Pode rodar de novo: se aparecer #1060 Duplicate column name (ou #1061 Duplicate key name), a coluna ja existe. Pule essa linha e rode as seguintes.
-- Nada e apagado. Pensamentos acelerados do dia (racing_thoughts) continuam valendo e passam a ser o maior valor dos periodos.
ALTER TABLE period_markers ADD COLUMN th_positive TINYINT NULL;
ALTER TABLE period_markers ADD COLUMN th_negative TINYINT NULL;
ALTER TABLE period_markers ADD COLUMN th_racing TINYINT NULL;
ALTER TABLE period_markers ADD COLUMN th_grandiose TINYINT NULL;
ALTER TABLE period_markers ADD COLUMN th_rumination TINYINT NULL;
ALTER TABLE period_markers ADD COLUMN th_death TINYINT NULL;
ALTER TABLE daily_markers ADD COLUMN th_positive TINYINT NULL;
ALTER TABLE daily_markers ADD COLUMN th_negative TINYINT NULL;
ALTER TABLE daily_markers ADD COLUMN th_grandiose TINYINT NULL;
ALTER TABLE daily_markers ADD COLUMN th_rumination TINYINT NULL;
ALTER TABLE daily_markers ADD COLUMN th_death TINYINT NULL;
ALTER TABLE daily_markers ADD COLUMN cigarettes SMALLINT UNSIGNED NULL;
ALTER TABLE daily_markers ADD COLUMN nicotine_other VARCHAR(20) NULL;
ALTER TABLE recipients ADD COLUMN include_death TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN is_professional TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN professional_kind VARCHAR(20) NULL;
ALTER TABLE users ADD COLUMN professional_reg VARCHAR(40) NULL;
CREATE TABLE IF NOT EXISTS care_shares (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id INT UNSIGNED NOT NULL,
  professional_id INT UNSIGNED NULL,
  invite_email VARCHAR(190) NULL,
  invite_token_hash CHAR(64) NULL,
  invite_expires_at DATETIME NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'pendente',
  scope_mask INT UNSIGNED NOT NULL DEFAULT 0,
  data_from DATE NULL,
  expires_on DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accepted_at DATETIME NULL,
  ended_at DATETIME NULL,
  ended_by VARCHAR(12) NULL,
  PRIMARY KEY (id),
  KEY idx_cs_patient (patient_id),
  KEY idx_cs_professional (professional_id),
  KEY idx_cs_email (invite_email),
  UNIQUE KEY uq_cs_token (invite_token_hash),
  CONSTRAINT fk_cs_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cs_professional FOREIGN KEY (professional_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS care_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  professional_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cc_hash (code_hash),
  CONSTRAINT fk_cc_professional FOREIGN KEY (professional_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS care_access_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  share_id INT UNSIGNED NULL,
  patient_id INT UNSIGNED NOT NULL,
  actor_id INT UNSIGNED NULL,
  actor_name VARCHAR(150) NOT NULL DEFAULT '',
  action VARCHAR(20) NOT NULL,
  scope_mask INT UNSIGNED NOT NULL DEFAULT 0,
  range_from DATE NULL,
  range_to DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cal_patient (patient_id, created_at),
  CONSTRAINT fk_cal_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
