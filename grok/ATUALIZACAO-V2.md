# Atualização V2: marcadores diários, Sexualidade (privado) e Indicadores de mania

**Pacote cumulativo.** Inclui as correções do **QA1** (que ainda não foram enviadas) e as novidades da **V2**.
Este pacote parte do que está hoje no servidor (versão com login por e-mail + migração `2026-10-14-email-login.sql`). Não é preciso enviar o `bipolar-update-qa1.zip` antes.

| Pasta no servidor | Conteúdo |
|---|---|
| `/home1/rona3541/bipolar.app.br/` | arquivos do site (= `public_html/` do pacote) |
| `/home1/rona3541/app/` | código interno e `config.php` (**o seu `config.php` não é substituído**) |
| `/home1/rona3541/cron/` | tarefas agendadas |
| `/home1/rona3541/tools/` | ferramentas de linha de comando (crie a pasta se ainda não existir) |

Tempo estimado: 15 minutos. **Há uma migração no banco** (seção 2). Ela só acrescenta colunas e tabelas e não altera nem apaga dados.

## 0. Backup
1. cPanel → **Gerenciador de Arquivos** → selecione `bipolar.app.br`, `app`, `cron` (e `tools`, se existir) → **Compactar** → baixe o `.zip`.
2. cPanel → **phpMyAdmin** → selecione o banco do app → **Exportar** → Rápido → SQL → Executar (baixa o backup do banco).
3. Descompacte `bipolar-update-v2.zip` no seu computador. Dentro há `bipolar-update-v2/` com `public_html/`, `app/`, `cron/`, `tools/`, `migrations/`, `docs/`, `loadtest/` e esta documentação.

## 1. Envie os arquivos (substituindo os existentes)

| Arquivo(s) do pacote | Enviar para |
|---|---|
| `public_html/index.php`, `privacidade.php`, `relatorio.php`, `offline.html`, `sw.js` | `/home1/rona3541/bipolar.app.br/` |
| `public_html/loadtest-admin.php` (**novo**, QA1; fica desativado e responde 404) | `/home1/rona3541/bipolar.app.br/` |
| `public_html/api/account.php`, `entries.php`, `recipients.php`, `reminders.php`, `day.php` (**novo**) | `/home1/rona3541/bipolar.app.br/api/` |
| `public_html/assets/css/app.css` | `/home1/rona3541/bipolar.app.br/assets/css/` |
| `public_html/assets/js/app.js` | `/home1/rona3541/bipolar.app.br/assets/js/` |
| `app/bootstrap.php` | `/home1/rona3541/app/` |
| `app/config.example.php` (só referência; **não** renomeie para config.php) | `/home1/rona3541/app/` |
| `app/lib/auth.php`, `helpers.php`, `oauth.php`, `password_auth.php`, `report.php`, `smtp.php`, `validate.php`, `loadtest.php` (**novo**), `markers.php` (**novo**) | `/home1/rona3541/app/lib/` |
| `app/views/layout.php`, `day_panels.php` (**novo**), `account_modules.php` (**novo**) | `/home1/rona3541/app/views/` |
| `cron/send_reminders.php`, `cron/send_reports.php` | `/home1/rona3541/cron/` |
| `tools/seed_loadtest.php`, `tools/cleanup_loadtest.php` (**novos**) | `/home1/rona3541/tools/` |
| `migrations/`, `docs/`, `loadtest/`, `schema.sql`, `*.md` | **não** envie. São documentação, SQL para o phpMyAdmin e o kit de teste de carga |

Atenção:
- Os arquivos de `public_html/` vão para **`bipolar.app.br/`**, **não** para `/home1/rona3541/public_html/`.
- **Nunca** coloque `app/`, `cron/` ou `tools/` dentro de `bipolar.app.br/`.
- **Rode a migração (seção 2) logo depois de enviar os arquivos.** Até ela rodar, as partes novas (Meu dia, indicadores, relatórios por e-mail) dão erro, porque procuram tabelas que ainda não existem. Se preferir zero minuto de erro, rode a migração **antes** de enviar: a versão atual do servidor funciona normalmente com as colunas novas.
- `bootstrap.php`, `markers.php`, `index.php`, `app.js`, `app.css` e as duas views novas dependem uns dos outros: envie todos.

## 2. Migração do banco (phpMyAdmin)
cPanel → **phpMyAdmin** → clique no banco do app (à esquerda) → aba **SQL** → cole **todo** o bloco abaixo → **Executar**.
Pode rodar mais de uma vez sem problema. No fim aparecem duas verificações: **4** colunas novas e **2** tabelas novas.

> **Importante (hospedagem):** o usuário do phpMyAdmin não tem acesso ao `information_schema` (erro `#1044`). Por isso a migração é SQL simples: `ALTER TABLE` e `CREATE TABLE IF NOT EXISTS`.
> **Se aparecer `#1060 Duplicate column name` (ou `#1061 Duplicate key name`)**, aquela linha já tinha sido aplicada: apague do editor as linhas até ela (inclusive) e clique em **Executar** de novo para rodar o restante. `CREATE TABLE IF NOT EXISTS` nunca dá erro se a tabela já existir.

```sql
-- Migração V2: marcadores diários, módulo Sexualidade (privado) e preferências. Pré-requisito: login por e-mail.
-- MySQL 5.7+ / MariaDB 10.3+. SQL simples (sem information_schema, PREPARE ou variáveis @), pois o usuário
-- do phpMyAdmin da hospedagem não pode consultar information_schema (#1044).
-- Pode rodar de novo: CREATE TABLE IF NOT EXISTS é ignorado, e se um ALTER mostrar
-- "#1060 Duplicate column name" (ou "#1061 Duplicate key name"), essa linha já foi aplicada — apague do
-- editor as linhas até ela (inclusive) e clique em Executar de novo para rodar o restante.
SET NAMES utf8mb4;

ALTER TABLE users ADD COLUMN prefs TEXT NULL AFTER session_epoch, ADD COLUMN sex_pin_hash VARCHAR(255) NULL AFTER prefs;
ALTER TABLE recipients ADD COLUMN include_markers TINYINT(1) NOT NULL DEFAULT 1 AFTER include_media_list, ADD COLUMN include_sexuality TINYINT(1) NOT NULL DEFAULT 0 AFTER include_markers;

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

-- Conferência (deve listar 2 + 2 linhas e as 2 tabelas):
SHOW COLUMNS FROM users WHERE Field IN ('prefs','sex_pin_hash');
SHOW COLUMNS FROM recipients WHERE Field IN ('include_markers','include_sexuality');
SHOW TABLES LIKE '%_log';
```

(O mesmo conteúdo está em `migrations/2026-10-15-v2-marcadores.sql`.)

## 3. Confira no navegador
O cache atualiza sozinho (o service worker passou para a versão 3). Se o visual antigo continuar, recarregue **duas vezes** (Ctrl+F5); no app instalado, feche e abra de novo.

**Itens do QA1** (incluídos neste pacote):
- **Editar**: Registrar → Hoje → **✎ Editar** abre o registro preenchido.
- **Datas**: aparecem em **dd/mm/aaaa**.
- **Celular**: barra de abas embaixo.
- **Lembretes**: só por e-mail enquanto não houver VAPID.
- **Convites**: mensagem de erro clara.
- **Fusos do Brasil**: aparecem primeiro.
- **CSV**: formato do Excel BR.
- **Faixa de crise no tema escuro**: legível.

Detalhes no `ATUALIZACAO-QA1.md`.

**Novidades da V2:**
1. **Registrar → “Meu dia”**: aparece abaixo do humor por período, que continua com 1 toque. As seções são recolhíveis:
   - **Sono**: deitar e acordar; o total é calculado sozinho.
   - **Sol e luz**: minutos e momento do dia.
   - **Cafeína, álcool e exercício.**
   - **Pensamentos/fala acelerados, gastos, irritação.**
   - **Estresse.**
   - **Medicação por dose.**
   - **Sinais pessoais.**
   - **Ciclo menstrual.**

   O botão **Salvar meu dia** salva o dia todo de uma vez.
2. **Conta → Módulos do registro diário**: escolha o que aparece. Nesse card você também:
   - cadastra **sinais de alerta pessoais** e o **plano de ação**;
   - cadastra **horários de medicação**;
   - configura **Sexualidade (privado)**: desligado por padrão; tem opção de borrar a tela, **PIN** e “Apagar todos os registros de sexualidade”.
3. **Alerta precoce**: um aviso amarelo e gentil no topo de Registrar. Ele aparece quando há sinais de (hipo)mania por 2 dos últimos 3 dias, quando um dia fica em alerta, ou na tríade **sono ↓ + humor ↑ + desejo ↑**. O aviso mostra o plano de ação e CVV/SAMU.
4. **Histórico → Indicadores de (hipo)mania**: tabela por dia com nível, pontos, sono e os sinais.
5. **Relatórios**:
   - novas opções por destinatário: “Incluir marcadores…” (ligada por padrão) e “Incluir resumo de sexualidade” (desligada, com confirmação). Mesmo marcada, vai só um resumo, sem atividades ou anotações.
   - no PDF imprimível também há a opção, se o módulo estiver ligado.
   - destinatários que já existem recebem marcadores e **nunca** recebem sexualidade.
6. **Lembretes** nunca mencionam sexualidade.
7. **Exportar JSON** inclui marcadores e sexualidade. O PIN não é exportado.

## 4. `config.php`
Nenhuma chave obrigatória nova. As chaves opcionais do QA1 (`loadtest_bypass_ratelimit_token`, `loadtest_admin_secret`) só servem para o teste de carga e devem ficar `''` no dia a dia (veja `ATUALIZACAO-QA1.md`, seção 3). Confirme que `dev_login` continua `false`.

## 5. Se algo der errado
1. Restaure os arquivos do backup (etapa 0).
2. Banco: as colunas e tabelas novas **podem ficar**. A versão anterior as ignora. Para removê-las mesmo assim (isso apaga os marcadores registrados):
```sql
DROP TABLE IF EXISTS sexuality_log; DROP TABLE IF EXISTS daily_markers;
ALTER TABLE users DROP COLUMN sex_pin_hash, DROP COLUMN prefs;
ALTER TABLE recipients DROP COLUMN include_sexuality, DROP COLUMN include_markers;
```

## Documentação
- `docs/COMPARATIVO-ESTABILIZA.md`: comparação com o app Estabiliza.
- `docs/SEXUALIDADE.md`: por que a sexualidade é um marcador de mania, como o app usa esses dados e as referências.
