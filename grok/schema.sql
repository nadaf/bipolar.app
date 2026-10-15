-- Bipolar — Diário de Humor | MySQL 5.7+ / MariaDB 10.2+ | utf8mb4
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL DEFAULT '',
  email VARCHAR(190) NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Cuiaba',
  extreme_alerts TINYINT(1) NOT NULL DEFAULT 1,
  password_hash VARCHAR(255) NULL,
  email_verified_at DATETIME NULL,
  session_epoch INT UNSIGNED NOT NULL DEFAULT 0,
  prefs TEXT NULL,
  sex_pin_hash VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL,
  ai_provider VARCHAR(20) NULL,
  ai_model VARCHAR(100) NULL,
  ai_base_url VARCHAR(255) NULL,
  ai_key_enc TEXT NULL,
  ai_key_last4 VARCHAR(4) NULL,
  ai_key_updated_at DATETIME NULL,
  mfa_totp_secret_enc TEXT NULL,
  mfa_enabled_at DATETIME NULL,
  mfa_totp_last_step BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_identities (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  provider VARCHAR(20) NOT NULL,
  subject VARCHAR(190) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_provider_subject (provider, subject),
  KEY idx_user (user_id),
  CONSTRAINT fk_oi_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  entry_date DATE NOT NULL,
  period ENUM('manha','tarde','noite') NOT NULL,
  mood TINYINT NOT NULL,
  sleep_hours DECIMAL(4,1) NULL,
  irritability TINYINT NULL,
  anxiety TINYINT NULL,
  energy TINYINT NULL,
  medication_taken TINYINT(1) NULL,
  note TEXT NULL,
  tags VARCHAR(500) NULL,
  client_uuid CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_day_period (user_id, entry_date, period),
  KEY idx_user_date (user_id, entry_date),
  CONSTRAINT fk_en_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  entry_id INT UNSIGNED NULL,
  kind ENUM('audio','video','image') NOT NULL,
  mime VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  stored_name VARCHAR(100) NOT NULL,
  original_name VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_entry (entry_id),
  CONSTRAINT fk_me_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_me_entry FOREIGN KEY (entry_id) REFERENCES entries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipients (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  role ENUM('psicologo','psiquiatra','familiar','outro') NOT NULL DEFAULT 'outro',
  frequency ENUM('diaria','semanal','mensal','personalizada') NOT NULL DEFAULT 'semanal',
  interval_days SMALLINT UNSIGNED NULL,
  weekday TINYINT NULL,
  include_notes TINYINT(1) NOT NULL DEFAULT 0,
  include_media_list TINYINT(1) NOT NULL DEFAULT 0,
  include_markers TINYINT(1) NOT NULL DEFAULT 1,
  include_sexuality TINYINT(1) NOT NULL DEFAULT 0,
  include_substances TINYINT(1) NOT NULL DEFAULT 0,
  include_alerts TINYINT(1) NOT NULL DEFAULT 0,
  last_alert_at DATETIME NULL,
  include_custom_alerts TINYINT(1) NOT NULL DEFAULT 0,
  invite_status VARCHAR(20) NULL,
  invite_error_at DATETIME NULL,
  confirm_token CHAR(64) NOT NULL,
  confirmed_at DATETIME NULL,
  unsubscribed_at DATETIME NULL,
  last_sent_at DATETIME NULL,
  next_send_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (confirm_token),
  UNIQUE KEY uq_recipient_user_email (user_id, email),
  KEY idx_next (next_send_at),
  CONSTRAINT fk_re_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reminders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  time_local CHAR(5) NOT NULL,
  days VARCHAR(20) NOT NULL DEFAULT '0,1,2,3,4,5,6',
  channel ENUM('push','email','ambos') NOT NULL DEFAULT 'push',
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_sent_local_date DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  CONSTRAINT fk_rm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  endpoint TEXT NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  p256dh VARCHAR(200) NULL,
  auth VARCHAR(100) NULL,
  label VARCHAR(120) NULL,
  last_ok_at DATETIME NULL,
  snooze_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_endpoint (endpoint_hash),
  KEY idx_user (user_id),
  CONSTRAINT fk_ps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  version INT UNSIGNED NOT NULL DEFAULT 1,
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

CREATE TABLE IF NOT EXISTS alerts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  rule_code VARCHAR(40) NOT NULL,
  kind VARCHAR(20) NOT NULL,
  severity TINYINT NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  title VARCHAR(200) NOT NULL,
  explanation TEXT NOT NULL,
  evidence_json TEXT NULL,
  feedback VARCHAR(3) NULL,
  feedback_at DATETIME NULL,
  seen_at DATETIME NULL,
  notified_push TINYINT(1) NOT NULL DEFAULT 0,
  notified_email TINYINT(1) NOT NULL DEFAULT 0,
  notified_recipients TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  custom_rule_id INT UNSIGNED NULL,
  is_private TINYINT(1) NOT NULL DEFAULT 0,
  pending_delivery TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_alert_user_time (user_id, created_at),
  KEY idx_alert_user_rule (user_id, rule_code, created_at),
  KEY idx_alert_custom (user_id, custom_rule_id, period_end),
  KEY idx_alert_pending (pending_delivery, created_at),
  CONSTRAINT fk_alert_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alert_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  name VARCHAR(100) NOT NULL,
  logic VARCHAR(3) NOT NULL DEFAULT 'and',
  conditions_json TEXT NOT NULL,
  severity TINYINT NOT NULL DEFAULT 1,
  notify_push TINYINT(1) NOT NULL DEFAULT 0,
  notify_email TINYINT(1) NOT NULL DEFAULT 0,
  cooldown_days TINYINT UNSIGNED NOT NULL DEFAULT 3,
  is_private TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  template VARCHAR(40) NULL,
  last_fired_on DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ar_user (user_id, active),
  CONSTRAINT fk_ar_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webauthn_credentials (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  cred_hash CHAR(64) NOT NULL,
  credential_id TEXT NOT NULL,
  public_key_pem TEXT NOT NULL,
  alg INT NOT NULL,
  sign_count INT UNSIGNED NOT NULL DEFAULT 0,
  name VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cred_hash (cred_hash),
  KEY idx_wa_user (user_id),
  CONSTRAINT fk_wa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_usage (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  month CHAR(7) NOT NULL,
  requests INT UNSIGNED NOT NULL DEFAULT 0,
  input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  cost_usd DECIMAL(10,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ai_user_month (user_id, month),
  CONSTRAINT fk_ai_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  rl_key CHAR(64) NOT NULL,
  window_start INT UNSIGNED NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (rl_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- V4.4: codigos de recuperacao da verificacao em duas etapas (so o SHA-256 fica guardado)
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
