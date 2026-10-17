-- V4.2: alertas personalizados, convites não entregues e entrega pendente de alertas.
-- Rode no phpMyAdmin (banco SEU_USUARIO_bipolar → aba SQL). Pode rodar de novo sem problema:
-- se aparecer #1060 (Duplicate column name) ou #1061 (Duplicate key name), essa linha já foi aplicada: pule e siga.
CREATE TABLE IF NOT EXISTS alert_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
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
ALTER TABLE alerts ADD COLUMN custom_rule_id INT UNSIGNED NULL;
ALTER TABLE alerts ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE alerts ADD COLUMN pending_delivery TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE alerts ADD KEY idx_alert_custom (user_id, custom_rule_id, period_end);
ALTER TABLE alerts ADD KEY idx_alert_pending (pending_delivery, created_at);
ALTER TABLE recipients ADD COLUMN include_custom_alerts TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE recipients ADD COLUMN invite_status VARCHAR(20) NULL;
ALTER TABLE recipients ADD COLUMN invite_error_at DATETIME NULL;
