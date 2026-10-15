# Atualização “QA 1” — correções do teste ao vivo (14/10/2026)

Para o servidor de **bipolar.app.br** (cPanel, PHP 8.3, MySQL 5.7), na organização atual:

| Pasta no servidor | Conteúdo |
|---|---|
| `/home1/rona3541/bipolar.app.br/` | arquivos do site (equivale a `public_html/` do pacote) |
| `/home1/rona3541/app/` | código interno e `config.php` |
| `/home1/rona3541/cron/` | tarefas agendadas |
| `/home1/rona3541/tools/` | ferramentas de linha de comando (crie a pasta se ainda não existir) |

**Banco de dados: nenhuma mudança.** Não há migração nesta atualização.
**Seu `config.php` não é substituído.** As chaves novas são opcionais (seção 3).
Tempo estimado: 10 minutos.

## 0. Antes de começar
1. cPanel → **Gerenciador de Arquivos** → selecione `bipolar.app.br`, `app`, `cron` (e `tools`, se existir) → **Compactar** → baixe o `.zip` (backup).
2. Baixe e descompacte `bipolar-update-qa1.zip` no seu computador. Dentro há a pasta `bipolar-update-qa1/` com `public_html/`, `app/`, `cron/`, `tools/` e esta documentação.

## 1. Envie os arquivos (substituindo os existentes)

| Arquivo(s) do pacote | Enviar para |
|---|---|
| `public_html/index.php`, `public_html/privacidade.php`, `public_html/offline.html`, `public_html/sw.js` | `/home1/rona3541/bipolar.app.br/` |
| `public_html/loadtest-admin.php` (**novo** — fica desativado, responde 404) | `/home1/rona3541/bipolar.app.br/` |
| `public_html/api/entries.php`, `public_html/api/recipients.php`, `public_html/api/reminders.php`, `public_html/api/account.php` | `/home1/rona3541/bipolar.app.br/api/` |
| `public_html/assets/css/app.css` | `/home1/rona3541/bipolar.app.br/assets/css/` |
| `public_html/assets/js/app.js` | `/home1/rona3541/bipolar.app.br/assets/js/` |
| `app/config.example.php` (só referência; **não** renomeie para config.php) | `/home1/rona3541/app/` |
| `app/lib/helpers.php`, `auth.php`, `oauth.php`, `smtp.php`, `report.php`, `validate.php`, `password_auth.php`, `loadtest.php` (**novo**) | `/home1/rona3541/app/lib/` |
| `app/views/layout.php` | `/home1/rona3541/app/views/` |
| `cron/send_reminders.php`, `cron/send_reports.php` | `/home1/rona3541/cron/` |
| `tools/seed_loadtest.php`, `tools/cleanup_loadtest.php` (**novos**) | `/home1/rona3541/tools/` |
| `README-INSTALACAO.md`, `ATUALIZACAO-QA1.md`, pasta `loadtest/` | **não** envie: são documentação e o kit de teste de carga, que roda no seu computador |

Atenção:
- Os arquivos de `public_html/` vão para **`bipolar.app.br/`** (a pasta do domínio adicional), **não** para `/home1/rona3541/public_html/`.
- **Nunca** coloque `app/`, `cron/` ou `tools/` dentro de `bipolar.app.br/`.
- Envie **todos** os arquivos da lista juntos: `index.php`, `app.js`, `app.css` e `layout.php` dependem uns dos outros.

## 2. Confira no navegador
O app atualiza o cache sozinho (o service worker mudou para a versão 2). Se ainda aparecer o visual antigo, recarregue a página **duas vezes** (ou Ctrl+F5).
No app instalado no celular: feche e abra de novo.

1. **Tema escuro**: botão ◐ → a faixa “Precisa de ajuda agora? CVV 188 · SAMU 192” fica com texto claro sobre fundo escuro.
2. **Registrar → Hoje**: cada período registrado tem **✎ Editar**. Clique: o formulário abre com humor, sono, medicação, escalas, etiquetas e anotação preenchidos; o botão vira **Salvar alterações**. Escolher no formulário uma data + período já registrado também carrega o registro salvo.
3. **Data** aparece como **dd/mm/aaaa** (digite ou use o botão 📅).
4. **Celular**: as 5 abas ficam numa barra fixa embaixo; em Histórico, o gráfico já abre mostrando os dias mais recentes; o calendário tem meses no topo e uma coluna por semana.
5. **Lembretes**: sem chaves VAPID só aparece “E-mail”; a mensagem explica que push ainda não está disponível. Lembretes “push” criados antes passam a ser enviados por e-mail.
6. **Relatórios**: convite para um endereço inexistente mostra uma mensagem simples (sem “SMTP”). O motivo técnico fica no log (seção 4).
7. **Conta → Fuso horário**: fusos do Brasil primeiro (Brasília, Cuiabá, Manaus, …), depois “Outros países”.
8. **Exportar CSV**: abre direto no Excel em português (colunas separadas, `6,5` horas, datas `dd/mm/aaaa`).
9. **Política de privacidade**: cita só as formas de login ativas (hoje: “e-mail e senha”).

## 3. Chaves novas no `config.php` (opcionais — só para teste de carga)
Não precisa mexer em nada para usar o app. Para o teste de carga (veja `loadtest/README.md`), adicione **temporariamente**:

```php
    'loadtest_bypass_ratelimit_token' => '',   // 32+ caracteres aleatórios ativa; vazio = desativado
    'loadtest_admin_secret' => '',             // 32+ caracteres ativa /loadtest-admin.php; vazio = 404
```

**Depois do teste, volte as duas para `''`** e apague as contas de teste (`tools/cleanup_loadtest.php --yes`).

## 4. Onde ver erros de e-mail
Falhas de SMTP vão para o **log de erros do PHP**, com a etapa e a resposta do servidor, por exemplo:

```
SMTP [convite] DESTINATÁRIO RECUSADO te***@bipolar.app.br pelo servidor mail.bipolar.app.br: 550 5.1.1 ... (o endereço pode não existir)
```

No cPanel: Gerenciador de Arquivos → arquivo `error_log` dentro de `bipolar.app.br/api/` (ou da pasta do script). Também pode estar em cPanel → **Métricas → Errors**.
Sobre o convite que falhou no QA (`teste.grok+dest@bipolar.app.br`): a versão anterior não registrava o motivo. Depois desta atualização, reenvie o convite (remova e cadastre de novo) e veja a linha `SMTP [convite] ...` no `error_log`:
- `DESTINATÁRIO RECUSADO ... 550` → o endereço não existe no cPanel. Crie a conta ou um **Encaminhador**.
- `falha na etapa auth` → usuário ou senha SMTP. `falha na conexão` → host, porta ou `secure` (465 = `ssl`, 587 = `tls`).

Esta versão também corrige o envio por SSL (porta 465) de mensagens maiores: antes, parte da mensagem podia não ser gravada, por exemplo em relatórios grandes. Agora o envio repete a gravação até mandar tudo.

## 5. Se algo der errado
Restaure o backup da etapa 0 (extraia o `.zip` no mesmo lugar). Como não houve mudança no banco, voltar os arquivos basta.
