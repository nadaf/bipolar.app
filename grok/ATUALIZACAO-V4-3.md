# Atualização V4.3: sem área de administração, IA com chave própria, privacidade e código aberto

Este pacote parte da **V4.2 já instalada** e traz **só os arquivos que mudaram**. Seu `app/config.php` não vem no pacote e não é substituído.

Tempo estimado: 20 minutos. **Primeiro rode o SQL (seção 1), depois envie os arquivos (seção 2) e então ajuste o `config.php` (seção 3).**

## 1. SQL (phpMyAdmin → banco `SEU_USUARIO_bipolar` → aba SQL)

Faça antes o backup: phpMyAdmin → **Exportar** → Rápido → SQL → Executar. No Gerenciador de Arquivos, compacte e baixe `bipolar.app.br`, `app`, `cron` e `tools`.

Cole e execute:

```sql
ALTER TABLE users ADD COLUMN ai_provider VARCHAR(20) NULL;
ALTER TABLE users ADD COLUMN ai_model VARCHAR(100) NULL;
ALTER TABLE users ADD COLUMN ai_base_url VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN ai_key_enc TEXT NULL;
ALTER TABLE users ADD COLUMN ai_key_last4 VARCHAR(4) NULL;
ALTER TABLE users ADD COLUMN ai_key_updated_at DATETIME NULL;
```

- Se aparecer **#1060 Duplicate column name**, essa linha já foi aplicada: **pule essa linha** e execute as seguintes.
- Nenhum dado existente é alterado. As colunas novas guardam a escolha de provedor/modelo de IA de cada pessoa e a chave **criptografada**.
- O mesmo SQL está em `migrations/2026-10-23-v4-3-ia-chave-propria.sql`.
- Conferência: `SHOW COLUMNS FROM users LIKE 'ai_%';` deve listar 6 linhas.

## 2. Envie os arquivos (substituindo os existentes)

| Arquivo(s) do pacote | Enviar para |
|---|---|
| `public_html/index.php`, `privacidade.php`, `termos.php` (**novo**), `sw.js` | `/home/SEU_USUARIO/bipolar.app.br/` |
| `public_html/conta/cadastro.php` | `/home/SEU_USUARIO/bipolar.app.br/conta/` |
| `public_html/api/account.php`, `ai.php`, `custom_alerts.php` | `/home/SEU_USUARIO/bipolar.app.br/api/` |
| `public_html/assets/css/app.css` | `/home/SEU_USUARIO/bipolar.app.br/assets/css/` |
| `public_html/assets/js/app.js` | `/home/SEU_USUARIO/bipolar.app.br/assets/js/` |
| `app/lib/ai.php`, `custom_alerts.php`, `helpers.php`, `ai_providers.php` (**novo**), `cron_status.php` (**novo**) | `/home/SEU_USUARIO/app/lib/` |
| `app/views/account_v4.php`, `layout.php` | `/home/SEU_USUARIO/app/views/` |
| `app/config.example.php` | `/home/SEU_USUARIO/app/` (só referência; **não** substitui o `config.php`) |
| `tools/cron_status.php` (**novo**) | `/home/SEU_USUARIO/tools/` |
| `migrations/`, `schema.sql`, `docs/`, `tests/`, `*.md`, `LICENSE`, `.gitignore` | **não** envie (SQL, testes e documentação) |

**Apague do servidor** (foram removidos nesta versão):
- `/home/SEU_USUARIO/bipolar.app.br/loadtest-admin.php` (painel que parecia área de administração);
- `/home/SEU_USUARIO/tools/test_xai.php` (substituído pelo botão “Testar chave” no app).

Atenção:
- Envie **todos** os arquivos de `app/lib/` juntos: eles dependem uns dos outros.
- O service worker mudou (`bp-v8`, com `app.js?v=8` e `app.css?v=8`). Se algo parecer antigo, feche e abra o app de novo.
- A hospedagem precisa das extensões PHP **sodium** (criptografia das chaves) e **zip** (exportação completa). No cPanel: **Selecionar versão do PHP → Extensões** e marque `sodium` e `zip`. Sem `zip`, o botão “Baixar tudo (ZIP)” simplesmente não aparece; o JSON e o CSV continuam funcionando.

## 3. Ajuste o `config.php` (`/home/SEU_USUARIO/app/config.php`)

### 3.1 Chave do servidor para criptografar as chaves de IA (obrigatório para a chave própria)

Gere 32 bytes aleatórios. O jeito mais simples: crie uma tarefa cron **única** no cPanel com
```
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;' > /home/SEU_USUARIO/chave-ia.txt
```
Abra o arquivo, copie o valor e **apague o arquivo e a tarefa**. Depois acrescente ao `config.php` (dentro do `return [ ... ]`):

```php
'ai' => [
    'secret_key' => 'COLE-AQUI-O-VALOR-BASE64',
    'operator_fallback' => ['provider' => '', 'api_key' => '', 'model' => ''],
    'reasoning_effort' => 'low',
    'timeout_seconds' => 60,
    'requests_per_hour' => 20,
],
```

- **Não perca nem troque essa chave**: se ela mudar, as chaves de IA salvas pelas pessoas deixam de abrir e cada uma precisa salvar a sua de novo.
- Sem `secret_key`, a opção “usar minha própria chave” fica escondida.

### 3.2 Chave compartilhada do operador (decisão)

- **Padrão do projeto aberto: nenhuma chave compartilhada.** Cada pessoa usa a própria chave (BYOK) e paga ao provedor que escolher.
- **Opcional:** se você quiser que quem não tem chave ainda use a IA, preencha `operator_fallback` (provedor, chave e modelo). Os limites e o teto de gasto valem só para essa chave.
- **Atenção, compatibilidade:** o bloco antigo `'xai' => [...]` do seu `config.php` ainda é lido como chave do operador. Ou seja, **depois da atualização a IA continua funcionando para todos com a chave xAI atual**. Para seguir o padrão aberto (sem chave compartilhada), **apague o bloco `'xai'`**.

### 3.3 Dados do controlador (aparecem na política de privacidade)

```php
'legal' => [
    'controller' => 'Seu nome (pessoa física), Cuiabá/MT',
    'contact_email' => 'privacidade@bipolar.app.br',
    'hosting' => 'hospedagem compartilhada HostGator (cPanel)',
    'source_url' => 'https://github.com/SEU_USUARIO/bipolar-app',
],
```
Enquanto `controller` estiver vazio, a página mostra “a pessoa responsável pelo site bipolar.app.br”. Preencha antes de divulgar.

## 4. Cron: nada muda nas tarefas, mas o painel saiu

As 3 tarefas continuam iguais. A caixa **Conta → Tarefas automáticas** e o endpoint `api/account.php?action=cron` **foram removidos**: o projeto não tem mais nenhuma área ou função de administração.

Para conferir as tarefas, use:
- o arquivo `/home/SEU_USUARIO/storage/logs/cron.log` (uma linha por execução: data, nome e contagens);
- ou, por SSH ou cron, `php /home/SEU_USUARIO/tools/cron_status.php` (pelo navegador responde 403).

## O que mudou

**Sem área de administração**
- Removidos a caixa de cron da Conta, o `action=cron`, o `loadtest-admin.php` e o `tools/test_xai.php`. Não existe papel nem rota de administrador.
- O status do cron fica só no servidor (`storage/logs/cron.log` e `tools/cron_status.php`).

**IA com a sua chave (BYOK), sem marca de fornecedor**
- O cartão agora se chama **“💭 Reflexões com IA”**.
- Provedores: xAI, OpenAI, Anthropic, Google Gemini, DeepSeek, Mistral, OpenRouter e **URL própria compatível com OpenAI**.
- Lista de modelos (conferida em outubro de 2026) **e** campo livre para digitar outro modelo.
- Adaptadores próprios para as APIs da Anthropic e do Gemini; os demais usam o formato OpenAI.
- A chave é **criptografada** (libsodium secretbox, com a `secret_key` do servidor), nunca volta para o navegador (só os 4 últimos caracteres), nunca vai para os logs e pode ser trocada ou removida.
- Botão **“Testar chave”**, com mensagens claras: chave inválida, sem crédito, limite de uso, tempo esgotado.
- Com chave própria não há limite de gasto do app (quem cobra é o seu provedor).
- URL própria aceita só `https` e recusa endereços internos (proteção contra SSRF).

**Correções de QA**
- Ao salvar um dia passado, a data escolhida **fica selecionada** e o formulário passa para o próximo período.
- Prévia de alerta personalizado: mostra **dias atendidos × dias que avisariam** e explica o **intervalo mínimo entre avisos**.
- Regras duplicadas (mesmas condições, mesmo que em outra ordem) são **avisadas na prévia e bloqueadas ao salvar**.
- Contraste do selo “Alerta forte” no tema escuro.

**Privacidade, termos e exportação**
- `privacidade.php` reescrita (LGPD) e `termos.php` novo, com resumo **“Em linguagem simples”** no começo. Links no rodapé e no cadastro.
- **Conta → Meus dados → “Baixar tudo (ZIP)”**: `dados.json`, `registros.csv`, todas as fotos e áudios e um `LEIA-ME.txt`.
- O JSON agora inclui também alertas personalizados, preferências de destinatários, inscrições de push e a escolha de IA (**sem a chave**).
- Excluir a conta apaga tudo, inclusive as mídias.

**Código aberto (sem publicar nada)**
- `README.md` (pt-BR com resumo em inglês), `LICENSE` (AGPL-3.0), `SECURITY.md`, `CONTRIBUTING.md`, `.gitignore` e `config.example.php` sem segredos.
- **Nenhum repositório foi criado e nada foi publicado.** Antes de publicar, confira o `.gitignore` e nunca envie `app/config.php`.

## Ressalvas
- Testado em PHP 8.4 e MySQL 5.7. O servidor usa PHP 8.3; não há recursos exclusivos do 8.4 no código.
- Os nomes de modelos mudam rápido: revise a lista em `app/lib/ai_providers.php` de tempos em tempos (o campo livre cobre o intervalo).
- A política de privacidade e os termos **não são aconselhamento jurídico**. Revise com um profissional, principalmente local do datacenter e retenção de backups da hospedagem, que não foram verificados.
