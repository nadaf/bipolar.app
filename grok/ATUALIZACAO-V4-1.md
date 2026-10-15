# Atualização V4.1: correções do QA ao vivo

Este pacote parte da **V4 já instalada** e traz **só os arquivos que mudaram** desde a V4. Seu `app/config.php` não está no pacote e não é substituído.

Tempo estimado: 15 minutos. Há **uma migração curta** (seção 2) e **uma conferência na configuração da xAI** (seção 4).

## O que mudou

- **Editar** abre sempre o registro do período clicado (agora por número do registro). Salvar nunca sobrescreve outro período: o servidor recusa (erro 409). Ao abrir o app, nada entra em edição sozinho: o período atual abre vazio ou, se já tem registro, aparece só para leitura com o botão **Editar**.
- **IA (“Reflexões com IA”)**: o erro 502 vinha do tempo limite de 20 s. O modelo grok-4.7 raciocina em modo “high” por padrão e pode passar disso. Agora o modelo, o esforço de raciocínio e o tempo limite são configuráveis. Os erros ficam em `storage/logs/ai.log`, sem a chave. Há também uma ferramenta de diagnóstico (seção 4).
- **Destinatários**: o mesmo e-mail não pode ser cadastrado duas vezes. As 3 caixas de confirmação viraram uma janela única e acessível.
- **Alertas**: o Histórico não mostra mais “Nenhum alerta” quando o indicador está em Alerta.
- **Relatório**: o sono médio é calculado por noite, igual ao Histórico, e a adesão à medicação usa as doses marcadas.
- **Biometria**: é possível cadastrar a primeira biometria confirmando com o PIN ou a senha da conta.
- **Interface**:
  - calendário próprio em português, com teclado (setas, Enter, Esc);
  - “Data inválida: esse dia não existe” para datas como 31/02;
  - plurais corretos;
  - “−” no registro rápido;
  - fusos horários em português;
  - nome dos arquivos exportados no seu fuso;
  - o álcool em Meu dia atualiza na hora;
  - a mensagem “PIN incorreto” some quando o PIN dá certo;
  - Sexualidade e Substâncias voltam a ficar desfocadas do mesmo jeito ao sair do app;
  - botões “Apagar todos” em vermelho;
  - números do gráfico maiores;
  - campo de PIN legível em telas de 340 px.
- **Conta → Tarefas automáticas**: mostra a data da última análise de sinais e do último envio de lembretes. Se passar de 2 dias, aparece “atrasada”.
- **Servidor**:
  - página 404 com a cara do app;
  - www → endereço sem www (301);
  - HTTPS obrigatório;
  - cache e compressão para `text/javascript`;
  - nova página pública **Como funcionam os alertas**.

## 0. Backup
1. cPanel → **Gerenciador de Arquivos** → selecione `bipolar.app.br`, `app`, `cron` e `tools` → **Compactar** → baixe o `.zip`.
2. cPanel → **phpMyAdmin** → banco do app → **Exportar** → Rápido → SQL → Executar.

## 1. Envie os arquivos (substituindo os existentes)

| Arquivo(s) do pacote | Enviar para |
|---|---|
| `public_html/.htaccess`, `index.php`, `escala.php`, `registro-rapido.php`, `offline.html`, `sw.js`, `404.php` (**novo**), `como-funcionam-os-alertas.php` (**novo**) | `/home1/rona3541/bipolar.app.br/` |
| `public_html/api/account.php`, `ai.php`, `day.php`, `entries.php`, `recipients.php`, `webauthn.php` | `/home1/rona3541/bipolar.app.br/api/` |
| `public_html/assets/css/app.css` | `/home1/rona3541/bipolar.app.br/assets/css/` |
| `public_html/assets/js/app.js`, `passkey.js` | `/home1/rona3541/bipolar.app.br/assets/js/` |
| `app/config.example.php` (só referência; **não** renomeie) | `/home1/rona3541/app/` |
| `app/lib/ai.php`, `helpers.php`, `markers.php`, `report.php`, `signals.php` | `/home1/rona3541/app/lib/` |
| `app/views/account_modules.php`, `day_panels.php`, `layout.php`, `lock.php` | `/home1/rona3541/app/views/` |
| `cron/analyze_signals.php`, `send_reminders.php`, `send_reports.php` | `/home1/rona3541/cron/` |
| `tools/test_xai.php` (**novo**) | `/home1/rona3541/tools/` |
| `migrations/`, `schema.sql`, `*.md` | **não** envie (documentação e SQL para o phpMyAdmin) |

Atenção:
- **Rode a migração (seção 2) antes de enviar os arquivos.**
- Envie **todos** os arquivos de `app/lib/` juntos: `helpers.php` tem funções usadas pelos outros.
- O `.htaccess` agora redireciona `www.bipolar.app.br` → `bipolar.app.br`. Se o certificado SSL não cobrir o `www`, o navegador mostra um aviso só nesse endereço. Confira em cPanel → **SSL/TLS Status** se `www.bipolar.app.br` está incluído (o AutoSSL costuma incluir).
- O service worker mudou (`bp-v6`): quem já usa o app recebe a versão nova na próxima abertura.
- Nunca coloque `app/`, `cron/`, `tools/` ou `storage/` dentro de `bipolar.app.br/`.

## 2. Migração (phpMyAdmin → banco do app → aba SQL)

Cole e execute:

```sql
-- V4.1: um mesmo e-mail só uma vez por pessoa na lista de destinatários.
-- 1) Remove duplicatas já existentes (mantém o cadastro mais antigo).
DELETE r1 FROM recipients r1 JOIN recipients r2 ON r1.user_id = r2.user_id AND r1.email = r2.email AND r1.id > r2.id;
-- 2) Cria a regra de unicidade.
ALTER TABLE recipients ADD UNIQUE KEY uq_recipient_user_email (user_id, email);
-- 3) Conferência: deve listar 2 linhas (user_id e email).
SHOW INDEX FROM recipients WHERE Key_name = 'uq_recipient_user_email';
```

- Se aparecer **#1061 Duplicate key name** (ou #1060 Duplicate column name), essa linha já foi aplicada antes: **pule essa linha** e rode as seguintes.
- A linha 1 pode apagar destinatários repetidos (mesmo e-mail na mesma conta). É o objetivo: fica o cadastro mais antigo.
- O mesmo SQL está em `migrations/2026-10-19-v4-1-destinatarios-unicos.sql`.

## 3. Cron: nada muda
As tarefas continuam as mesmas. Elas agora gravam um “batimento” (só data/hora e contadores) em `/home1/rona3541/storage/heartbeat-*.json`, mostrado em **Conta → Tarefas automáticas**. Antes da primeira execução depois da atualização, aparece “ainda não registrada”.

## 4. IA: conferir a configuração da xAI (resolve o erro 502)

**Causa provável do 502:** o app esperava no máximo 20 s pela xAI. O modelo `grok-4.7` usa raciocínio “high” por padrão e muitas vezes passa disso. A conexão era cortada e o app respondia 502.

1. cPanel → Gerenciador de Arquivos → `/home1/rona3541/app/config.php` → **Editar**. No bloco `'xai' => [ ... ]`, confira:
   ```php
   'api_key' => 'xai-...',                                // chave válida, com créditos, do console.x.ai
   'model' => 'grok-4.7',                                 // precisa aparecer na lista do teste abaixo
   'endpoint' => 'https://api.x.ai/v1/chat/completions',  // endereço completo (ou só https://api.x.ai/v1)
   'reasoning_effort' => 'low',                           // NOVO: respostas em poucos segundos
   'timeout_seconds' => 60,                               // NOVO: 10 a 120
   ```
   Sem as duas linhas novas, o app já usa `low` e 60 s. Se o teste disser que o modelo não aceita `reasoning_effort`, use `'reasoning_effort' => ''`.
2. **Teste de diagnóstico.** Ele não mostra a chave e faz no máximo 1 chamada pequena a cada 10 minutos.
   - cPanel → **Cron Jobs** → “Uma vez por minuto” → comando:
     `/usr/local/bin/php /home1/rona3541/tools/test_xai.php`
   - Espere 2 minutos e abra `/home1/rona3541/storage/logs/xai-test.log`. Ele mostra:
     - se a chave existe;
     - os modelos liberados para a chave, e se o configurado está na lista;
     - o resultado de uma chamada real, com o tempo gasto;
     - uma dica em português se algo falhar (chave, modelo, limite/créditos, tempo).
   - **Apague esse cron job** depois de ler o resultado.
3. Erros futuros da IA ficam em `/home1/rona3541/storage/logs/ai.log`, com o código e a mensagem da xAI e sem a chave.

## 5. Conferência rápida depois de enviar
- `https://www.bipolar.app.br/` → vai para `https://bipolar.app.br/`.
- `https://bipolar.app.br/nao-existe` → página “Página não encontrada” do app.
- Registrar: salve a Manhã e depois a Noite. Em Hoje → **Editar** na Manhã, o formulário mostra a Manhã.
- Conta → Destinatários: cadastrar o mesmo e-mail de novo mostra “Esse e-mail já está na sua lista”.
- Reflexões com IA: responde, ou mostra uma mensagem clara com o tipo de problema.
