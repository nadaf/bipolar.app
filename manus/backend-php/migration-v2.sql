-- Bipolar automation v2. Additive only: no DROP or ALTER of existing tables.
-- Run a backup before applying this file.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS automation_settings (
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    settings_cipher MEDIUMBLOB NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_automation_settings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_recipients (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name_cipher BLOB NOT NULL,
    email_cipher BLOB NOT NULL,
    role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY ix_automation_recipient_user (user_id, active),
    CONSTRAINT fk_automation_recipient_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    endpoint_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    endpoint_cipher BLOB NOT NULL,
    p256dh_cipher BLOB NOT NULL,
    auth_cipher BLOB NOT NULL,
    created_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    disabled_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_push_endpoint (endpoint_hash),
    KEY ix_push_user (user_id, disabled_at),
    CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_outbox (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    channel VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recipient_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    subscription_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    recipient_label_cipher BLOB NOT NULL,
    payload_cipher MEDIUMBLOB NOT NULL,
    is_automated TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    failure_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    available_at DATETIME(6) NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_outbox_idempotency (user_id, event_key, target_key),
    KEY ix_outbox_ready (status, available_at, created_at),
    KEY ix_outbox_recipient (user_id, recipient_id, status),
    CONSTRAINT fk_outbox_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_deliveries (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outbox_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    channel VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    last_attempt_at DATETIME(6) NULL,
    recipient_label_cipher BLOB NOT NULL,
    message VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_delivery_outbox (outbox_id),
    KEY ix_delivery_user_created (user_id, created_at),
    CONSTRAINT fk_delivery_outbox FOREIGN KEY (outbox_id) REFERENCES automation_outbox (id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_cron_heartbeat (
    id TINYINT UNSIGNED NOT NULL,
    last_run_at DATETIME(6) NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
