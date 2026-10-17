# Atualização V4.3.1: edição em duas abas, “Testar chave”, status da IA e controlador na privacidade

Este pacote parte da **V4.3 já instalada** e traz **só os arquivos que mudaram**. Seu `app/config.php` não vem no pacote e não é substituído.

Tempo estimado: 10 minutos. **Primeiro rode o SQL (seção 1), depois envie os arquivos (seção 2).**

## 1. SQL (phpMyAdmin → banco `SEU_USUARIO_bipolar` → aba SQL)

Faça antes o backup: phpMyAdmin → **Exportar** → Rápido → SQL → Executar.

Cole e execute:

```sql
ALTER TABLE entries ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE alert_rules ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE recipients ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE sexuality_log ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1;
```

- Se aparecer **#1060 Duplicate column name**, essa linha já foi aplicada: **pule essa linha** e execute as seguintes.
- Nenhum dado existente é alterado: todos os registros começam na versão 1.
- O mesmo SQL está em `migrations/2026-10-25-v4-3-1-versao-registros.sql`.
- Conferência: `SHOW COLUMNS FROM entries LIKE 'version';` (e o mesmo para `alert_rules`, `recipients` e `sexuality_log`) deve listar 1 linha.
- **Rode o SQL antes de enviar os arquivos.** Sem a coluna `version`, salvar registros dá erro 500.

## 2. Envie os arquivos (substituindo os existentes)

| Arquivo(s) do pacote | Enviar para |
|---|---|
| `public_html/index.php`, `registro-rapido.php`, `sw.js` | `/home/SEU_USUARIO/bipolar.app.br/` |
| `public_html/api/ai.php`, `custom_alerts.php`, `day.php`, `entries.php`, `recipients.php` | `/home/SEU_USUARIO/bipolar.app.br/api/` |
| `public_html/assets/js/app.js`, `passkey.js`, `quick.js` | `/home/SEU_USUARIO/bipolar.app.br/assets/js/` |
| `app/lib/helpers.php`, `custom_alerts.php` | `/home/SEU_USUARIO/app/lib/` |
| `app/views/layout.php`, `lock.php` | `/home/SEU_USUARIO/app/views/` |
| `migrations/`, `schema.sql`, `tests/`, `*.md` | **não** envie (SQL, testes e documentação) |

- O service worker mudou (`bp-v9`, com `app.js?v=9`, `app.css?v=9`, `passkey.js?v=3` e `quick.js?v=2`). Se algo parecer antigo, feche e abra o app de novo.
- Nada muda no `config.php` e no cron.

## 3. Privacidade mostrando o texto genérico (`legal.controller`)

O código está certo: `privacidade.php` lê `cfg('legal.controller')`, e `cfg()` percorre o `config.php` a partir do nível de cima. Com `'legal' => ['controller' => 'Ronaldo Nadaf, Cuiabá/MT', ...]` no nível de cima, o nome aparece (há teste automático com exatamente esse valor). Se o site continua mostrando “a pessoa responsável pelo site bipolar.app.br”, o problema está no servidor, não no código. Confira nesta ordem:

1. **Dois blocos `'legal'` no `config.php`.** Se você colou o seu bloco e o bloco do exemplo (com `'controller' => ''`) continuou mais abaixo, o PHP usa o **último** e o nome some. Deixe só um.
2. **Bloco dentro de outro bloco.** O `'legal' => [...]` precisa estar direto no `return [ ... ]`, não dentro de `'ai'`, `'db'` etc.
3. **Arquivo antigo.** Confira se o `config.php` editado é o `/home/SEU_USUARIO/app/config.php` (e não uma cópia), e se `/home/SEU_USUARIO/bipolar.app.br/privacidade.php` é o da V4.3 (deve conter `cfg('legal.controller'`).
4. **Cache do PHP (OPcache).** No cPanel, **Selecionar versão do PHP → Opções** e salve qualquer opção, ou aguarde alguns minutos.

Para tirar a dúvida, crie uma tarefa cron **única** com o comando abaixo, veja o arquivo e **apague o arquivo e a tarefa**:
```
php -r '$c = require "/home/SEU_USUARIO/app/config.php"; var_dump($c["legal"]["controller"] ?? null);' > /home/SEU_USUARIO/legal-teste.txt
```
Se aparecer `string(...) "Ronaldo Nadaf, Cuiabá/MT"`, o config está certo e o servidor está com o `privacidade.php` antigo ou em cache. Se aparecer `NULL` ou `string(0) ""`, ajuste o `config.php` (itens 1 e 2).

## O que mudou

**Duas abas editando o mesmo registro (concorrência otimista)**
- Registros de humor, alertas personalizados, destinatários e sexualidade agora têm uma **versão**. O app envia a versão que carregou. Se outra aba ou aparelho salvou antes, o servidor responde **409** com: *“Este registro foi alterado em outra aba ou aparelho. Recarregue para ver a versão mais recente.”* e nada é sobrescrito.
- O app mostra a mensagem e oferece **“Recarregar agora”**.
- A troca de versão é atômica no banco: com duas gravações ao mesmo tempo, exatamente uma passa.
- Continua igual o 409 de **mover um registro para um período já ocupado** (e esse erro não consome a versão).
- **Fila offline:** registros guardados sem conexão continuam sendo enviados como antes (sem versão), para nunca perder o que foi anotado offline. O mesmo vale para o registro rápido e clientes antigos ainda em cache.
- Substâncias não entram: elas só são adicionadas ou apagadas, não editadas.

**“Testar chave” mostra a mensagem em português**
- `api/ai.php` agora devolve `erro` junto com `mensagem` quando a chave falha. O `app.js` (e também `passkey.js` e `quick.js`) leem `erro` e, se faltar, `mensagem`. Antes aparecia só “Erro 422”.
- As outras APIs foram conferidas: só o `ai.php` tinha essa diferença. Um teste automático impede que volte.

**Status da IA correto**
- `GET /api/ai.php` devolvia `configured=true` sempre que o servidor aceitava chaves próprias, mesmo sem chave salva e sem chave do operador. Agora `configured` só é `true` quando a IA funciona para a pessoa (chave própria salva ou chave do operador). Novos campos: `server_ready` (antigo significado) e `operator_fallback`.

## Ressalvas
- Testado em PHP 8.4 e MySQL 5.7.44 (o servidor usa PHP 8.3; nenhum recurso exclusivo do 8.4 foi usado).
- Se duas abas **criarem** ao mesmo tempo um registro no mesmo período ainda vazio, continua valendo a última gravação (o caso relatado no QA, editar o mesmo registro, está coberto).
