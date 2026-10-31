-- V5.1 (0.5.1-beta) - correcoes de seguranca: troca de e-mail em duas etapas, fila de envios (lembretes e relatorios),
-- registros offline sem duplicar, exportacao com confirmacao e identidade de login com hash.
-- Rode na aba SQL do banco ANTES de enviar os arquivos. Pode rodar de novo: se aparecer #1060 Duplicate column name
-- (ou #1061 Duplicate key name), essa linha ja foi aplicada. Pule essa linha e rode as seguintes.
-- Nada e apagado. Nenhum compartilhamento com profissional e alterado. Nenhuma linha de dados de saude e tocada.
SET NAMES utf8mb4;

-- 1) Troca de e-mail em duas etapas: o e-mail novo fica pendente ate a confirmacao pelo link.
ALTER TABLE users ADD COLUMN pending_email VARCHAR(190) NULL;
ALTER TABLE users ADD COLUMN pending_email_at DATETIME NULL;
-- endereco que foi realmente comprovado (clique no link de confirmacao). Convites de profissional so valem para ele.
ALTER TABLE users ADD COLUMN email_verified_email VARCHAR(190) NULL;
-- Preenche so para quem confirmou o e-mail pelo link (existe token de verificacao usado) e ainda usa o mesmo e-mail do login.
-- Quem entrou por Google passa a ter o e-mail comprovado no proximo login. Os demais podem confirmar em Conta.
UPDATE users u JOIN oauth_identities i ON i.user_id = u.id AND i.provider = 'email' AND i.subject = LOWER(TRIM(u.email))
  SET u.email_verified_email = LOWER(TRIM(u.email))
  WHERE u.email_verified_email IS NULL AND u.email_verified_at IS NOT NULL
    AND EXISTS (SELECT 1 FROM auth_tokens t WHERE t.user_id = u.id AND t.purpose = 'verify' AND t.used_at IS NOT NULL);

-- 2) Tokens de uso unico para a troca de e-mail e para a exportacao (so o SHA-256 fica guardado).
ALTER TABLE auth_tokens MODIFY purpose ENUM('verify','reset','email_change','export') NOT NULL;
ALTER TABLE auth_tokens ADD COLUMN payload VARCHAR(190) NULL;

-- 3) Identidade de login: unicidade por hash (cabe no limite de 767 bytes de indice do MySQL 5.7 antigo).
ALTER TABLE oauth_identities ADD COLUMN subject_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL;
UPDATE oauth_identities SET subject_hash = SHA2(CONCAT(provider, '|', subject), 256) WHERE subject_hash IS NULL;
ALTER TABLE oauth_identities ADD UNIQUE KEY uq_oi_subject_hash (subject_hash);

-- 4) Lembretes: proximo horario em UTC (e a data local usada para nao repetir no mesmo dia).
ALTER TABLE reminders ADD COLUMN next_due_at DATETIME NULL;
ALTER TABLE reminders ADD KEY idx_rm_due (active, next_due_at);

-- 5) Fila de envios (lembretes, adiamentos de 30 min e relatorios). Cada envio e reservado, enviado e so depois confirmado.
CREATE TABLE IF NOT EXISTS outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind VARCHAR(12) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  ref_id INT UNSIGNED NOT NULL,
  dedupe_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  state VARCHAR(10) NOT NULL DEFAULT 'ready',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL,
  expires_at DATETIME NULL,
  due_at DATETIME NOT NULL,
  local_date DATE NULL,
  ref_value VARCHAR(40) NULL,
  lease_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  lease_until DATETIME NULL,
  last_error_code VARCHAR(40) NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_outbox_dedupe (dedupe_key),
  KEY idx_outbox_pick (state, available_at),
  KEY idx_outbox_lease (lease_token),
  KEY idx_outbox_user (user_id),
  CONSTRAINT fk_outbox_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) Registros enviados pela fila offline: cada envio (client_uuid) e gravado uma unica vez.
CREATE TABLE IF NOT EXISTS entry_client_ids (
  user_id INT UNSIGNED NOT NULL,
  client_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  entry_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, client_uuid),
  KEY idx_eci_created (created_at),
  CONSTRAINT fk_eci_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conferencia: deve mostrar as duas tabelas novas.
SHOW TABLES LIKE 'outbox';
SHOW TABLES LIKE 'entry_client_ids';

-- V5.1: religiosidade por periodo (opcional, 0 Nenhuma, 1 Normal, 2 Sensivel ao tema, 3 Muito sensivel ao tema)
ALTER TABLE period_markers ADD COLUMN religiosity TINYINT NULL;
ALTER TABLE daily_markers ADD COLUMN religiosity TINYINT NULL;
