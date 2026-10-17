# Atualização V4: alertas automáticos, biometria (passkeys), notificações push completas e “Reflexões com IA” (IA)

Este pacote parte da **V3 já instalada** (migração V3 aplicada e arquivos da V3 enviados). Se os arquivos da V3 ainda não terminaram de subir, termine a V3 primeiro. Esta é uma atualização incremental: ela contém **só os arquivos que mudaram** desde a V3.

| Pasta no servidor | Conteúdo |
|---|---|
| `/home/SEU_USUARIO/bipolar.app.br/` | arquivos do site (= `public_html/` do pacote) |
| `/home/SEU_USUARIO/app/` | código interno e `config.php` (**o seu `config.php` não está no pacote e não é substituído**) |
| `/home/SEU_USUARIO/cron/` | tarefas agendadas |
| `/home/SEU_USUARIO/tools/` | ferramentas |
| `/home/SEU_USUARIO/storage/` | criada automaticamente pelo gerador VAPID (fora do site, permissão 700/600) |

Tempo estimado: 20 minutos. Há **uma migração** (seção 2), um **cron novo** (seção 3) e duas configurações opcionais: VAPID (seção 4) e chave da xAI (seção 5).

## 0. Backup
1. cPanel → **Gerenciador de Arquivos** → selecione `bipolar.app.br`, `app`, `cron` e `tools` → **Compactar** → baixe o `.zip`.
2. cPanel → **phpMyAdmin** → banco do app → **Exportar** → Rápido → SQL → Executar.
3. Descompacte `bipolar-update-v4.zip` no seu computador.

## 1. Envie os arquivos (substituindo os existentes)

| Arquivo(s) do pacote | Enviar para |
|---|---|
| `public_html/index.php`, `privacidade.php`, `escala.php`, `sw.js`, `registro-rapido.php` (**novo**) | `/home/SEU_USUARIO/bipolar.app.br/` |
| `public_html/api/account.php`, `day.php`, `push.php`, `recipients.php`, `alerts.php` (**novo**), `webauthn.php` (**novo**), `ai.php` (**novo**) | `/home/SEU_USUARIO/bipolar.app.br/api/` |
| `public_html/assets/css/app.css` | `/home/SEU_USUARIO/bipolar.app.br/assets/css/` |
| `public_html/assets/js/app.js`, `passkey.js` (**novo**), `quick.js` (**novo**) | `/home/SEU_USUARIO/bipolar.app.br/assets/js/` |
| `app/bootstrap.php`, `app/config.example.php` (só referência; **não** renomeie) | `/home/SEU_USUARIO/app/` |
| `app/lib/auth.php`, `markers.php`, `webpush.php`, `alerts.php` (**novo**), `signals.php` (**novo**), `lock.php` (**novo**), `webauthn.php` (**novo**), `ai.php` (**novo**) | `/home/SEU_USUARIO/app/lib/` |
| `app/views/layout.php`, `lock.php` (**novo**), `account_v4.php` (**novo**) | `/home/SEU_USUARIO/app/views/` |
| `cron/send_reminders.php`, `cron/analyze_signals.php` (**novo**) | `/home/SEU_USUARIO/cron/` |
| `tools/generate_vapid.php` | `/home/SEU_USUARIO/tools/` |
| `migrations/`, `docs/`, `schema.sql`, `*.md` | **não** envie (documentação e SQL para o phpMyAdmin) |

Atenção:
- **Rode a migração (seção 2) antes de enviar os arquivos.** A V3 funciona normalmente com as tabelas e colunas novas, então assim não há nenhum minuto de erro.
- `bootstrap.php` carrega `lock.php`, e `auth.php` depende dele: envie **todos** os arquivos de `app/lib/` juntos.
- Nunca coloque `app/`, `cron/`, `tools/` ou `storage/` dentro de `bipolar.app.br/`.
- O service worker mudou (`bp-v5`): quem já usa o app recebe a versão nova automaticamente na próxima abertura.

## 2. Migração do banco (phpMyAdmin)
cPanel → **phpMyAdmin** → clique no banco do app → aba **SQL** → cole **todo** o bloco → **Executar**. No fim aparecem três verificações: `alerts`, `webauthn_credentials` e `ai_usage` (uma linha cada).

> **Hospedagem:** o usuário do phpMyAdmin não pode consultar o `information_schema` (erro `#1044`). Por isso a migração usa só `ALTER TABLE` e `CREATE TABLE IF NOT EXISTS` simples.
> **Se aparecer `#1060 Duplicate column name` (ou `#1061 Duplicate key name`)**, aquela linha já tinha sido aplicada: apague do editor as linhas até ela (inclusive) e clique em **Executar** de novo para rodar o restante. `CREATE TABLE IF NOT EXISTS` nunca dá erro se a tabela já existir.

```sql
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
```

## 3. Cron novo: análise de sinais (alertas)
cPanel → **Cron Jobs** → **Adicionar novo**, configuração personalizada: minuto `7`, demais campos `*` (uma vez por hora). Comando:

```
/usr/local/bin/php /home/SEU_USUARIO/cron/analyze_signals.php >> /home/SEU_USUARIO/cron/alerts.log 2>&1
```

Os crons existentes (`send_reminders.php`, `send_reports.php`) continuam iguais. O de lembretes agora também envia push com os botões **Registrar agora** e **Lembrar em 30 min**.
Os alertas aparecem no app mesmo sem esse cron (são recalculados ao abrir o Histórico, no máximo a cada 10 min), mas push e e-mail de alerta só saem pelo cron.

## 4. Notificações push (VAPID), sem SSH
Se o seu `config.php` **já tem** `vapid.public_key` e `vapid.private_key` preenchidas, pule esta seção (não troque as chaves: isso desliga as inscrições existentes).
1. cPanel → **Cron Jobs** → adicione uma tarefa com todos os campos `*` (todo minuto) e o comando:
   `/usr/local/bin/php /home/SEU_USUARIO/tools/generate_vapid.php >> /home/SEU_USUARIO/cron/vapid.log 2>&1`
2. Espere 1–2 minutos. Abra `/home/SEU_USUARIO/cron/vapid.log` no Gerenciador de Arquivos: deve dizer que as chaves foram geradas.
3. **Apague essa tarefa do cron.** (Ela não faz mal se rodar de novo, porque nunca sobrescreve chaves, mas não precisa ficar lá.)
4. O app passa a ler as chaves de `/home/SEU_USUARIO/storage/vapid.php` automaticamente. Se preferir tê-las no `config.php`, copie o trecho de `storage/vapid-config.txt`.
5. Teste: app → **Lembretes** → **Ativar notificações neste aparelho** → **Testar** ao lado do aparelho. No iPhone a notificação só funciona com o app adicionado à Tela de Início (iOS 16.4+); as instruções estão na própria tela.

## 5. “Reflexões com IA” (IA, opcional)
Sem chave, o recurso fica **desligado e escondido**. Para ativar:
1. Crie a chave em https://console.x.ai → **API Keys** e defina um limite de gasto na conta da xAI.
2. Gerenciador de Arquivos → `/home/SEU_USUARIO/app/config.php` → **Editar** → antes do `];` final, cole (ajuste a chave):

```php
    'xai' => [
        'api_key' => 'xai-COLE-SUA-CHAVE-AQUI',
        'model' => 'grok-4.7',
        'endpoint' => 'https://api.x.ai/v1/chat/completions',
        'price_in_per_mtok' => 2.00,
        'price_out_per_mtok' => 6.00,
        'monthly_cap_usd' => 2.00,
        'requests_per_hour' => 20,
        'max_output_tokens' => 900,
    ],
    'webauthn' => [
        'rp_id' => 'bipolar.app.br',
    ],
```
3. Confira os preços do modelo em https://docs.x.ai e ajuste `price_*` se mudaram (eles servem para o teto mensal por pessoa).
4. Cada pessoa precisa ativar em **Conta → Reflexões com IA** e escolher as categorias. Por padrão, notas, sexualidade e substâncias **não** são enviadas.

O bloco `webauthn` é recomendado mesmo sem IA: ele fixa o domínio da biometria em `bipolar.app.br`. Sem ele, o domínio vem de `app_url`.

## 6. Conferência rápida
- Entre no app → **Conta → Biometria**: cadastre a digital/rosto; marque **Bloquear o app inteiro ao abrir**, feche e reabra o app e desbloqueie.
- Saia e use **Entrar com biometria** na tela inicial.
- **Histórico → Alertas** mostra “Nenhum alerta” ou os alertas com o botão **Faz sentido / Não faz sentido**.
- `/home/SEU_USUARIO/cron/alerts.log` registra uma linha por execução.
- Confirme que `config.php` continua com `'dev_login' => false`.

## Como desfazer
Restaure o `.zip` do backup da seção 0. As tabelas e colunas novas podem ficar no banco: a V3 as ignora.
