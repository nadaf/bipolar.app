-- Bipolar 2.x - MySQL 5.7+ - gerado por bipolar:export-sql em 2026-11-01
-- PROTECAO: para (sem alterar nada) se o banco nao estiver vazio ou for do Bipolar 0.5.
DROP PROCEDURE IF EXISTS bipolar_verificar;
DELIMITER $$
CREATE PROCEDURE bipolar_verificar(IN modo VARCHAR(10))
BEGIN
  DECLARE ok INT DEFAULT 1;
  DECLARE CONTINUE HANDLER FOR 1146, 1054 SET ok = 0;
  SET ok = 1;
  DO (SELECT COUNT(*) FROM `entries`);
  IF ok = 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PARE: banco do Bipolar 0.5 (antigo). NADA foi alterado. Crie um banco NOVO vazio e importe instalar-banco-novo.sql';
  END IF;
  SET ok = 1;
  DO (SELECT COUNT(*) FROM `care_shares`);
  IF ok = 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PARE: banco do Bipolar 0.5 (antigo). NADA foi alterado. Crie um banco NOVO vazio e importe instalar-banco-novo.sql';
  END IF;
  SET ok = 1;
  DO (SELECT COUNT(*) FROM `care_codes`);
  IF ok = 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PARE: banco do Bipolar 0.5 (antigo). NADA foi alterado. Crie um banco NOVO vazio e importe instalar-banco-novo.sql';
  END IF;
  SET ok = 1;
  DO (SELECT COUNT(*) FROM `auth_tokens`);
  IF ok = 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PARE: banco do Bipolar 0.5 (antigo). NADA foi alterado. Crie um banco NOVO vazio e importe instalar-banco-novo.sql';
  END IF;
  SET ok = 1;
  DO (SELECT COUNT(*) FROM `outbox`);
  IF ok = 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PARE: banco do Bipolar 0.5 (antigo). NADA foi alterado. Crie um banco NOVO vazio e importe instalar-banco-novo.sql';
  END IF;
  SET ok = 1;
  DO (SELECT COUNT(*) FROM `period_markers`);
  IF ok = 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PARE: banco do Bipolar 0.5 (antigo). NADA foi alterado. Crie um banco NOVO vazio e importe instalar-banco-novo.sql';
  END IF;
  SET ok = 1;
  DO (SELECT COUNT(*) FROM `daily_markers`);
  IF ok = 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PARE: banco do Bipolar 0.5 (antigo). NADA foi alterado. Crie um banco NOVO vazio e importe instalar-banco-novo.sql';
  END IF;
  SET ok = 1;
  DO (SELECT COUNT(*) FROM `recipients`);
  IF ok = 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PARE: banco do Bipolar 0.5 (antigo). NADA foi alterado. Crie um banco NOVO vazio e importe instalar-banco-novo.sql';
  END IF;
  SET ok = 1;
  DO (SELECT COUNT(*) FROM `users`);
  IF ok = 1 THEN
    SET ok = 1;
    DO (SELECT COUNT(`remember_token`) FROM `users`);
    IF ok = 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PARE: banco do Bipolar 0.5 (antigo). NADA foi alterado. Crie um banco NOVO vazio e importe instalar-banco-novo.sql';
    END IF;
  END IF;
  SET ok = 1;
  DO (SELECT COUNT(*) FROM `tenants`);
  IF ok = 1 THEN
    SET ok = 1;
    DO (SELECT COUNT(`kind`) FROM `tenants`);
    IF ok = 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PARE: a tabela tenants e de outro sistema. NADA foi alterado. Use um banco NOVO e vazio para o Bipolar 2.0';
    END IF;
    IF modo = 'instalar' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PARE: banco nao esta vazio. NADA foi alterado. Para instalar, use um banco NOVO e vazio. Para atualizar, use atualizar-banco.sql';
    END IF;
  END IF;
  SET ok = 1;
  DO (SELECT COUNT(*) FROM `migrations`);
  IF ok = 1 THEN
    SET ok = 1;
    DO (SELECT COUNT(`migration`) FROM `migrations`);
    IF ok = 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PARE: a tabela migrations e de outro sistema. NADA foi alterado. Use um banco NOVO e vazio para o Bipolar 2.0';
    END IF;
    IF modo = 'instalar' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PARE: banco nao esta vazio. NADA foi alterado. Para instalar, use um banco NOVO e vazio. Para atualizar, use atualizar-banco.sql';
    END IF;
  END IF;
  IF modo = 'instalar' THEN
    SET ok = 1;
    DO (SELECT COUNT(*) FROM `users`);
    IF ok = 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PARE: banco nao esta vazio. NADA foi alterado. Para instalar, use um banco NOVO e vazio. Para atualizar, use atualizar-banco.sql';
    END IF;
  END IF;
  IF modo = 'atualizar' THEN
    SET ok = 1;
    DO (SELECT COUNT(*) FROM `migrations`);
    IF ok = 0 THEN
      SET ok = 1;
      DO (SELECT COUNT(*) FROM `users`);
      IF ok = 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PARE: banco sem controle de versao do Bipolar 2.0. NADA foi alterado. Use um banco NOVO e importe instalar-banco-novo.sql';
      END IF;
    END IF;
  END IF;
END$$
DELIMITER ;
CALL bipolar_verificar('instalar');
DROP PROCEDURE bipolar_verificar;
SET NAMES utf8mb4;
SET time_zone = '+00:00';
CREATE TABLE IF NOT EXISTS migrations (id int unsigned not null auto_increment primary key, migration varchar(255) not null, batch int not null) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
create table `tenants` (`id` bigint unsigned not null auto_increment primary key, `kind` varchar(10) not null, `name` varchar(150) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
create table `users` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(150) not null, `email` varchar(190) not null, `email_verified_at` timestamp null, `password` varchar(255) not null, `timezone` varchar(64) not null default 'America/Sao_Paulo', `mfa_totp_secret` text null, `mfa_enabled_at` timestamp null, `legacy_id` bigint unsigned null, `remember_token` varchar(100) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `users` add unique `users_email_unique`(`email`);
alter table `users` add unique `users_legacy_id_unique`(`legacy_id`);
create table `memberships` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `user_id` bigint unsigned not null, `role` varchar(15) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `memberships` add constraint `memberships_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `memberships` add constraint `memberships_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade;
alter table `memberships` add unique `memberships_tenant_id_user_id_unique`(`tenant_id`, `user_id`);
alter table `memberships` add index `memberships_user_id_role_index`(`user_id`, `role`);
create table `password_reset_tokens` (`email` varchar(190) not null, `token` varchar(255) not null, `created_at` timestamp null, primary key (`email`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
create table `sessions` (`id` varchar(255) not null, `user_id` bigint unsigned null, `ip_address` varchar(45) null, `user_agent` text null, `payload` longtext not null, `last_activity` int not null, primary key (`id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `sessions` add index `sessions_user_id_index`(`user_id`);
alter table `sessions` add index `sessions_last_activity_index`(`last_activity`);
INSERT INTO migrations (migration, batch) VALUES ('0001_01_01_000000_create_users_table', 1);
create table `cache` (`key` varchar(255) not null, `value` mediumtext not null, `expiration` bigint not null, primary key (`key`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `cache` add index `cache_expiration_index`(`expiration`);
create table `cache_locks` (`key` varchar(255) not null, `owner` varchar(255) not null, `expiration` bigint not null, primary key (`key`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `cache_locks` add index `cache_locks_expiration_index`(`expiration`);
INSERT INTO migrations (migration, batch) VALUES ('0001_01_01_000001_create_cache_table', 1);
create table `jobs` (`id` bigint unsigned not null auto_increment primary key, `queue` varchar(255) not null, `payload` longtext not null, `attempts` smallint unsigned not null, `reserved_at` int unsigned null, `available_at` int unsigned not null, `created_at` int unsigned not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `jobs` add index `jobs_queue_index`(`queue`);
create table `job_batches` (`id` varchar(255) not null, `name` varchar(255) not null, `total_jobs` int not null, `pending_jobs` int not null, `failed_jobs` int not null, `failed_job_ids` longtext not null, `options` mediumtext null, `cancelled_at` int null, `created_at` int not null, `finished_at` int null, primary key (`id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
create table `failed_jobs` (`id` bigint unsigned not null auto_increment primary key, `uuid` varchar(255) not null, `connection` varchar(255) not null, `queue` varchar(255) not null, `payload` longtext not null, `exception` longtext not null, `failed_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `failed_jobs` add index `failed_jobs_connection_queue_failed_at_index`(`connection`, `queue`, `failed_at`);
alter table `failed_jobs` add unique `failed_jobs_uuid_unique`(`uuid`);
INSERT INTO migrations (migration, batch) VALUES ('0001_01_01_000002_create_jobs_table', 1);
create table `personal_access_tokens` (`id` bigint unsigned not null auto_increment primary key, `tokenable_type` varchar(255) not null, `tokenable_id` bigint unsigned not null, `name` text not null, `token` varchar(64) not null, `abilities` text null, `last_used_at` timestamp null, `expires_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `personal_access_tokens` add index `personal_access_tokens_tokenable_type_tokenable_id_index`(`tokenable_type`, `tokenable_id`);
alter table `personal_access_tokens` add unique `personal_access_tokens_token_unique`(`token`);
alter table `personal_access_tokens` add index `personal_access_tokens_expires_at_index`(`expires_at`);
INSERT INTO migrations (migration, batch) VALUES ('2026_10_31_175806_create_personal_access_tokens_table', 1);
create table `mood_entries` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `patient_id` bigint unsigned not null, `version` int unsigned not null default '1', `entry_date` date not null, `period` varchar(5) not null, `mood` tinyint not null, `energy` tinyint null, `energy_legacy` tinyint null, `anxiety` tinyint null, `irritability` tinyint null, `sleep_hours` decimal(4, 1) null, `medication_taken` tinyint(1) null, `note` text null, `client_uuid` char(36) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `mood_entries` add unique `uq_me_tenant_patient_day_period`(`tenant_id`, `patient_id`, `entry_date`, `period`);
alter table `mood_entries` add unique `uq_me_client_uuid`(`tenant_id`, `patient_id`, `client_uuid`);
alter table `mood_entries` add index `ix_me_tenant_id`(`tenant_id`, `id`);
alter table `mood_entries` add constraint `fk_me_tenant` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `mood_entries` add constraint `fk_me_membership` foreign key (`tenant_id`, `patient_id`) references `memberships` (`tenant_id`, `user_id`) on delete cascade;
create table `care_grants` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `patient_id` bigint unsigned not null, `grantee_user_id` bigint unsigned null, `grantee_kind` varchar(15) not null, `scope_mask` int unsigned not null default '7', `invite_token_hash` char(64) null, `invite_expires_at` timestamp null, `data_from` date null, `expires_at` timestamp null, `accepted_at` timestamp null, `revoked_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `care_grants` add index `care_grants_tenant_id_patient_id_index`(`tenant_id`, `patient_id`);
alter table `care_grants` add index `care_grants_grantee_user_id_revoked_at_index`(`grantee_user_id`, `revoked_at`);
alter table `care_grants` add constraint `fk_cg_membership` foreign key (`tenant_id`, `patient_id`) references `memberships` (`tenant_id`, `user_id`) on delete cascade;
alter table `care_grants` add constraint `fk_cg_grantee` foreign key (`grantee_user_id`) references `users` (`id`) on delete cascade;
alter table `care_grants` add unique `care_grants_invite_token_hash_unique`(`invite_token_hash`);
create table `audit_logs` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `actor_id` bigint unsigned null, `patient_id` bigint unsigned null, `action` varchar(40) not null, `subject_type` varchar(60) null, `subject_id` bigint unsigned null, `scope_mask` int unsigned null, `ip_hash` varchar(64) null, `created_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `audit_logs` add index `audit_logs_tenant_id_patient_id_created_at_index`(`tenant_id`, `patient_id`, `created_at`);
alter table `audit_logs` add constraint `fk_al_tenant` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
INSERT INTO migrations (migration, batch) VALUES ('2026_11_01_000100_create_mood_entries_table', 1);
alter table `tenants` add `slug` varchar(30) null after `name`;
alter table `tenants` add unique `tenants_slug_unique`(`slug`);
alter table `personal_access_tokens` add `tenant_id` bigint unsigned null after `tokenable_id`;
alter table `personal_access_tokens` add index `personal_access_tokens_tenant_id_index`(`tenant_id`);
create table `alert_settings` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `patient_id` bigint unsigned not null, `mood_low` tinyint not null default '-2', `mood_high` tinyint not null default '2', `energy_low` tinyint not null default '-2', `energy_high` tinyint not null default '2', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `alert_settings` add unique `uq_alert_patient`(`tenant_id`, `patient_id`);
alter table `alert_settings` add constraint `fk_as_membership` foreign key (`tenant_id`, `patient_id`) references `memberships` (`tenant_id`, `user_id`) on delete cascade;
INSERT INTO migrations (migration, batch) VALUES ('2026_11_01_000200_hosts_tokens_alerts', 1);
create table `day_blocks` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `patient_id` bigint unsigned not null, `entry_date` date not null, `period` varchar(5) not null, `block` varchar(20) not null, `data` text not null, `version` int unsigned not null default '1', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `day_blocks` add unique `uq_db_tenant_patient_day_block`(`tenant_id`, `patient_id`, `entry_date`, `period`, `block`);
alter table `day_blocks` add index `ix_db_tenant_patient_day`(`tenant_id`, `patient_id`, `entry_date`);
alter table `day_blocks` add constraint `fk_db_tenant` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `day_blocks` add constraint `fk_db_membership` foreign key (`tenant_id`, `patient_id`) references `memberships` (`tenant_id`, `user_id`) on delete cascade;
alter table `users` add `theme` varchar(10) not null default 'sistema' after `timezone`;
alter table `users` add `modules` varchar(200) null after `theme`;
alter table `users` add `pin_hash` varchar(255) null after `modules`;
alter table `users` add `pending_email` varchar(190) null after `pin_hash`;
alter table `users` add `email_change_hash` char(64) null after `pending_email`;
alter table `users` add `email_change_expires_at` timestamp null after `email_change_hash`;
alter table `alert_settings` add `reminders` text null after `energy_high`;
alter table `mood_entries` modify `mood` tinyint null;
INSERT INTO migrations (migration, batch) VALUES ('2026_11_02_000100_day_blocks_prefs_reminders', 1);
create table `push_subscriptions` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `user_id` bigint unsigned not null, `endpoint` text not null, `endpoint_hash` char(64) not null, `p256dh` varchar(200) not null, `auth` varchar(100) not null, `last_slot` varchar(30) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `push_subscriptions` add unique `uq_ps_tenant_endpoint`(`tenant_id`, `endpoint_hash`);
alter table `push_subscriptions` add index `ix_ps_tenant_user`(`tenant_id`, `user_id`);
alter table `push_subscriptions` add constraint `fk_ps_tenant` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `push_subscriptions` add constraint `fk_ps_membership` foreign key (`tenant_id`, `user_id`) references `memberships` (`tenant_id`, `user_id`) on delete cascade;
INSERT INTO migrations (migration, batch) VALUES ('2026_11_03_000100_create_push_subscriptions_table', 1);
alter table `users` add `mfa_recovery_codes` text null after `mfa_enabled_at`;
alter table `users` add `mfa_last_step` bigint unsigned null after `mfa_recovery_codes`;
alter table `tenants` add `require_mfa` tinyint(1) not null default '0' after `slug`;
create table `clinic_invites` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `email` varchar(190) not null, `role` varchar(20) not null default 'professional', `token_hash` char(64) not null, `invited_by` bigint unsigned null, `expires_at` timestamp null, `accepted_at` timestamp null, `accepted_user_id` bigint unsigned null, `revoked_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `clinic_invites` add unique `uq_ci_token`(`token_hash`);
alter table `clinic_invites` add index `ix_ci_tenant_email`(`tenant_id`, `email`);
alter table `clinic_invites` add constraint `fk_ci_tenant` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `clinic_invites` add constraint `fk_ci_inviter` foreign key (`invited_by`) references `users` (`id`) on delete set null;
alter table `clinic_invites` add constraint `fk_ci_accepted` foreign key (`accepted_user_id`) references `users` (`id`) on delete set null;
INSERT INTO migrations (migration, batch) VALUES ('2026_11_04_000100_mfa_exports_clinic_invites', 1);
create table `password_resets` (`id` bigint unsigned not null auto_increment primary key, `user_id` bigint unsigned not null, `host_tenant_id` bigint unsigned null, `host` varchar(190) not null, `token_hash` char(64) not null, `expires_at` timestamp not null, `used_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `password_resets` add unique `uq_pr_token`(`token_hash`);
alter table `password_resets` add index `ix_pr_user_host`(`user_id`, `host`);
alter table `password_resets` add constraint `fk_pr_user` foreign key (`user_id`) references `users` (`id`) on delete cascade;
INSERT INTO migrations (migration, batch) VALUES ('2026_11_05_000100_create_password_resets_table', 1);
create table `legacy_links` (`id` bigint unsigned not null auto_increment primary key, `kind` varchar(20) not null, `legacy_id` bigint unsigned not null, `new_id` bigint unsigned null, `created_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `legacy_links` add unique `uq_ll_kind_legacy`(`kind`, `legacy_id`);
create table `legacy_import_progress` (`step` varchar(20) not null, `last_id` bigint unsigned not null default '0', `done` tinyint(1) not null default '0', `copied` int unsigned not null default '0', `skipped` int unsigned not null default '0', `notes` text null, `updated_at` timestamp null, primary key (`step`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
INSERT INTO migrations (migration, batch) VALUES ('2026_11_06_000100_legacy_import', 1);
