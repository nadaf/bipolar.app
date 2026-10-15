-- Migração V4: alertas automáticos, biometria (WebAuthn), notificações e IA. Pré-requisito: V3.
-- MySQL 5.7+ / MariaDB 10.3+. SQL simples (sem information_schema, PREPARE ou variáveis @), pois o usuário
-- do phpMyAdmin da hospedagem não pode consultar information_schema (#1044).
-- Pode rodar de novo: CREATE TABLE IF NOT EXISTS é ignorado, e se um ALTER mostrar
-- "#1060 Duplicate column name" (ou "#1061 Duplicate key name"), essa linha já foi aplicada — apague do
-- editor as linhas até ela (inclusive) e clique em Executar de novo para rodar o restante.
SET NAMES utf8mb4;

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
  PRIMARY KEY (id),
  KEY idx_alert_user_time (user_id, created_at),
  KEY idx_alert_user_rule (user_id, rule_code, created_at),
  CONSTRAINT fk_alert_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

ALTER TABLE recipients ADD COLUMN include_alerts TINYINT(1) NOT NULL DEFAULT 0 AFTER include_substances, ADD COLUMN last_alert_at DATETIME NULL AFTER include_alerts;
ALTER TABLE push_subscriptions ADD COLUMN label VARCHAR(120) NULL AFTER auth, ADD COLUMN last_ok_at DATETIME NULL AFTER label, ADD COLUMN snooze_until DATETIME NULL AFTER last_ok_at;

-- Conferência (2 + 3 linhas e as 3 tabelas novas):
SHOW COLUMNS FROM recipients WHERE Field IN ('include_alerts','last_alert_at');
SHOW COLUMNS FROM push_subscriptions WHERE Field IN ('label','last_ok_at','snooze_until');
SHOW TABLES LIKE 'alerts';
SHOW TABLES LIKE 'webauthn_credentials';
SHOW TABLES LIKE 'ai_usage';
