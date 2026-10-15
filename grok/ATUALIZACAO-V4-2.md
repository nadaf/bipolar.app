# Atualização V4.2: alertas personalizados e correções

Este pacote parte da **V4.1 já instalada** e traz **só os arquivos que mudaram**. Seu `app/config.php` não vem no pacote e não é substituído.

Tempo estimado: 15 minutos. **Primeiro rode o SQL (seção 1), depois envie os arquivos (seção 2).**

## 1. SQL (phpMyAdmin → banco `rona3541_bipolargrok` → aba SQL)

Faça antes o backup: phpMyAdmin → **Exportar** → Rápido → SQL → Executar. No Gerenciador de Arquivos, compacte e baixe `bipolar.app.br`, `app`, `cron` e `tools`.

Cole e execute:

```sql
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
```

- Se aparecer **#1060 Duplicate column name** ou **#1061 Duplicate key name**, essa linha já foi aplicada: **pule essa linha** e execute as seguintes (selecione o restante e clique em Executar de novo).
- Nenhum dado existente é alterado. Os alertas antigos ficam como “não privados” e os convites antigos ficam sem situação, até serem reenviados.
- O mesmo SQL está em `migrations/2026-10-21-v4-2-alertas-personalizados.sql`.
- Conferência: `SHOW TABLES LIKE 'alert_rules';` deve listar 1 linha.

## 2. Envie os arquivos (substituindo os existentes)

| Arquivo(s) do pacote | Enviar para |
|---|---|
| `public_html/index.php`, `relatorio.php`, `sw.js` | `/home1/rona3541/bipolar.app.br/` |
| `public_html/api/account.php`, `ai.php`, `alerts.php`, `day.php`, `entries.php`, `recipients.php`, `custom_alerts.php` (**novo**) | `/home1/rona3541/bipolar.app.br/api/` |
| `public_html/assets/css/app.css` | `/home1/rona3541/bipolar.app.br/assets/css/` |
| `public_html/assets/js/app.js` | `/home1/rona3541/bipolar.app.br/assets/js/` |
| `app/lib/ai.php`, `alerts.php`, `helpers.php`, `markers.php`, `report.php`, `signals.php`, `custom_alerts.php` (**novo**) | `/home1/rona3541/app/lib/` |
| `app/views/account_modules.php`, `day_panels.php`, `layout.php` | `/home1/rona3541/app/views/` |
| `cron/analyze_signals.php`, `send_reports.php` | `/home1/rona3541/cron/` |
| `migrations/`, `schema.sql`, `docs/`, `*.md` | **não** envie (SQL e documentação) |

Atenção:
- Envie **todos** os arquivos de `app/lib/` juntos: eles dependem uns dos outros.
- O service worker mudou (`bp-v7`, com `app.js?v=7` e `app.css?v=7`): quem já usa o app recebe a versão nova na próxima abertura. Se algo parecer antigo, feche e abra o app de novo.
- Nunca coloque `app/`, `cron/`, `tools/` ou `storage/` dentro de `bipolar.app.br/`.

## 3. Cron: nada muda
As 3 tarefas continuam iguais. O `analyze_signals.php` (de hora em hora) agora também avalia os alertas personalizados e entrega por push/e-mail os avisos criados no app desde a última execução. **Conta → Tarefas automáticas** mostra as 3 tarefas.

## O que mudou

**Novo: alertas personalizados** (Histórico → Meus alertas personalizados)
- Você monta regras com até 6 condições ligadas por **E** ou **OU**, usando qualquer dado registrado: humor por período, sono, sintomas, gastos, café, álcool, medicação, registros do dia e, com os módulos ligados, sexualidade e cada substância.
- Cada condição vale **no dia**, **em pelo menos K dos últimos N dias** ou **por N dias seguidos**.
- 10 modelos prontos, editar, pausar, excluir e **prévia** (“teria avisado nestes dias”) sobre os dados antigos.
- Avalia logo depois de cada registro salvo e no cron de hora em hora.
- Avisos no app, e por push e/ou e-mail se você escolher, sempre com texto genérico.
- Regras com Sexualidade/Substâncias só aparecem com as seções privadas desbloqueadas.
- Destinatários recebem só a **quantidade**, e só com a nova caixa marcada.

**Correções**
- **Salvar rápido várias vezes**:
  - o botão Salvar não some mais;
  - o registro aparece em Hoje na hora;
  - um salvamento simultâneo não falha mais (o servidor tenta de novo).
- **Histórico**: a lista de alertas é calculada na hora.
- **Relatório para impressão** agora bate com o Histórico:
  - dias de alerta;
  - pensamentos acelerados e gastos (contam a partir de “leve”);
  - coluna Sono preenchida;
  - nova seção com os alertas do período.
- **Destinatários**:
  - convite que voltou aparece como “convite recusado: confira o e-mail” (ou “convite não enviado”), com o botão **Reenviar convite**;
  - o formulário é limpo depois de adicionar.
- **Interface**:
  - “Salvar PIN” em uma linha;
  - o aviso (toast) não cobre mais as abas;
  - o foco fica preso na janela de confirmação;
  - leitores de tela não leem mais “✓ ✓ ✓”;
  - o calendário mostra “outubro de 2026” em minúsculas;
  - horários de medicação e lembretes em 24 h;
  - nomes de fusos em português;
  - o número de dias do resumo semanal da IA bate com a lista.
- **Substâncias**: nova opção **Café/cafeína**, que também preenche os cafés do dia.
- **Conta**: situação das 3 tarefas automáticas (também em `/api/account.php?action=cron`).

## 4. Conferência rápida
- Histórico → Meus alertas personalizados → **＋ Novo alerta** → escolha um modelo → **Ver prévia nos últimos 90 dias** mostra os dias.
- Salve a Manhã e logo depois a Tarde: as duas aparecem em Hoje sem recarregar.
- Relatório: a coluna Sono tem horas e a seção “Alertas do app no período” aparece.
- Conta → Tarefas automáticas: 3 linhas. Depois da próxima hora cheia, a análise de sinais aparece “em dia”.
