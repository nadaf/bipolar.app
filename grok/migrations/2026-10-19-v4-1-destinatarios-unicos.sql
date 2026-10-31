-- Migração V4.1: um destinatário por e-mail para cada pessoa. Pré-requisito: V4.
-- MySQL 5.7+ / MariaDB 10.3+. SQL simples (sem information_schema, PREPARE ou variáveis @), pois o usuário
-- do phpMyAdmin da hospedagem não pode consultar information_schema (#1044).
-- Pode rodar de novo: se o ALTER mostrar "#1061 Duplicate key name" (ou "#1060 Duplicate column name"), essa linha
-- já foi aplicada e não há mais nada a fazer.
-- V5.1 (revisão): quem já rodou esta migração NÃO precisa rodar de novo. A regra para repetidos ficou mais segura:
--   fica o cadastro ATIVO (confirmado e sem cancelamento), depois o CANCELADO (respeita quem pediu para não receber),
--   depois o pendente. Entre dois do mesmo tipo fica o que compartilha MENOS itens e, empatando, o mais antigo.
--   Se houver dois cadastros ativos do mesmo e-mail com opções de compartilhamento DIFERENTES, a migração para com
--   "#1062 Duplicate entry" no passo 1 e nada é apagado. Nesse caso apague à mão, na tabela recipients, o cadastro
--   que a pessoa não quer mais e rode o arquivo de novo.
SET NAMES utf8mb4;

-- 1) Trava de segurança: dois cadastros ativos do mesmo e-mail com opções diferentes param a migração aqui.
DROP TEMPORARY TABLE IF EXISTS v41_guard;
CREATE TEMPORARY TABLE v41_guard (user_id INT UNSIGNED NOT NULL, email VARCHAR(190) NOT NULL, PRIMARY KEY (user_id, email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO v41_guard (user_id, email)
  SELECT d.user_id, d.email FROM (SELECT DISTINCT user_id, email, include_notes, include_media_list, include_markers, include_sexuality, include_substances, include_alerts
    FROM recipients WHERE confirmed_at IS NOT NULL AND unsubscribed_at IS NULL) d;
DROP TEMPORARY TABLE IF EXISTS v41_guard;

-- 2) Remove os repetidos do mesmo e-mail para a mesma pessoa, mantendo o melhor pela regra acima.
DELETE r1 FROM recipients r1 JOIN recipients r2 ON r1.user_id = r2.user_id AND r1.email = r2.email AND r1.id <> r2.id
  WHERE ((CASE WHEN r2.confirmed_at IS NOT NULL AND r2.unsubscribed_at IS NULL THEN 300 WHEN r2.unsubscribed_at IS NOT NULL THEN 200 ELSE 100 END)
          - (r2.include_notes + r2.include_media_list + r2.include_markers + r2.include_sexuality + r2.include_substances + r2.include_alerts))
      > ((CASE WHEN r1.confirmed_at IS NOT NULL AND r1.unsubscribed_at IS NULL THEN 300 WHEN r1.unsubscribed_at IS NOT NULL THEN 200 ELSE 100 END)
          - (r1.include_notes + r1.include_media_list + r1.include_markers + r1.include_sexuality + r1.include_substances + r1.include_alerts))
     OR (((CASE WHEN r2.confirmed_at IS NOT NULL AND r2.unsubscribed_at IS NULL THEN 300 WHEN r2.unsubscribed_at IS NOT NULL THEN 200 ELSE 100 END)
          - (r2.include_notes + r2.include_media_list + r2.include_markers + r2.include_sexuality + r2.include_substances + r2.include_alerts))
      = ((CASE WHEN r1.confirmed_at IS NOT NULL AND r1.unsubscribed_at IS NULL THEN 300 WHEN r1.unsubscribed_at IS NOT NULL THEN 200 ELSE 100 END)
          - (r1.include_notes + r1.include_media_list + r1.include_markers + r1.include_sexuality + r1.include_substances + r1.include_alerts))
        AND r2.id < r1.id);
-- 3) Impede novas repetições (a comparação ignora maiúsculas/minúsculas).
ALTER TABLE recipients ADD UNIQUE KEY uq_recipient_user_email (user_id, email);

SHOW INDEX FROM recipients WHERE Key_name = 'uq_recipient_user_email';
