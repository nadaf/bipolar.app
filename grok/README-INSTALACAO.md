# Bipolar — Diário de Humor · Guia de instalação (cPanel)

Aplicativo web (PWA) para registro de humor por período do dia (manhã, tarde e noite — sem “madrugada”, para não incentivar ficar acordado para registrar), com escala inspirada no NIMH Life Chart Method, gráficos, relatórios por e‑mail para profissionais/familiares, lembretes e conformidade com a LGPD.

- **PHP 8.0 ou superior** (testado em 8.1, 8.2, 8.3 e 8.4; recomendado 8.2+), extensões: `pdo_mysql`, `curl`, `openssl`, `mbstring`, `fileinfo`
- **MySQL 5.7 ou superior** (testado no MySQL 5.7.44; também roda em MariaDB 10.3+). utf8mb4, sem colunas JSON, CTEs, funções de janela ou CHECK; colunas indexadas com no máximo 191 caracteres.
- Sem Composer, sem Node: é só enviar os arquivos.

## 1. Estrutura de pastas

```
/home/USUARIO/
├── app/            ← código PHP privado (config.php, bibliotecas) — FORA do public_html
├── cron/           ← scripts das tarefas agendadas
├── storage/media/  ← áudios/vídeos enviados (privado)
├── tools/          ← gerador de chaves VAPID
└── public_html/    ← raiz do site https://bipolar.app.br
```

**Domínio adicional (addon domain):** se `bipolar.app.br` é um domínio adicional, a pasta raiz dele costuma ser `/home/USUARIO/bipolar.app.br/` em vez de `public_html`. Nesse caso coloque nela o **conteúdo** de `public_html/` do pacote e mantenha `app/`, `cron/`, `storage/` e `tools/` em `/home/USUARIO/`:

```
/home/USUARIO/
├── app/  cron/  storage/  tools/
└── bipolar.app.br/   ← conteúdo de public_html/ do pacote
```

Funciona igual, porque o app procura `../app` a partir da pasta do site.

> As pastas `app`, `cron`, `storage` e `tools` precisam ficar **ao lado** da pasta do site (não dentro). O arquivo `public_html/_boot.php` procura `../app`. Cada uma tem `.htaccess` com `Require all denied` como segunda proteção.

## 2. Banco de dados

1. cPanel → **Bancos de dados MySQL** (MySQL® Databases).
2. *Criar novo banco*: ex. `bipolar` → vira `USUARIO_bipolar`.
3. *Adicionar novo usuário*: ex. `bipolar` com senha forte (use o gerador) → `USUARIO_bipolar`.
4. *Adicionar usuário ao banco* → marque **TODOS OS PRIVILÉGIOS**.
5. cPanel → **phpMyAdmin** → selecione o banco → aba **Importar** → escolha `schema.sql` → *Executar*.

## 3. Envio dos arquivos

> Não envie a pasta `tests/` nem a pasta `screenshots/`.

1. Compacte/extraia: cPanel → **Gerenciador de Arquivos** → vá para `/home/USUARIO` → **Carregar** o `bipolar-app.zip` → clique com o botão direito → **Extract**.
2. Mova o conteúdo de `bipolar-app/public_html/` para o seu `public_html/` (inclua arquivos ocultos: *Configurações → Mostrar arquivos ocultos* para ver `.htaccess` e `.user.ini`).
3. Mova `bipolar-app/app`, `cron`, `storage`, `tools` para `/home/USUARIO/`.
4. Permissões: pastas 755, arquivos 644; `storage/media` 750. `app/config.php` 600.
5. Copie `app/config.example.php` para `app/config.php` e edite (banco, OAuth, SMTP, VAPID). Mantenha `'dev_login' => false`.

## 4. Versão do PHP

cPanel → **Selecionar versão do PHP** (Select PHP Version / MultiPHP Manager) → escolha **8.2** para o domínio. Escolha 8.0 ou superior. Em *Extensões*, marque `pdo_mysql`, `curl`, `mbstring`, `fileinfo`, `openssl`. Em *Opções*: `upload_max_filesize = 100M`, `post_max_size = 110M`, `max_execution_time = 120` (o `.user.ini` já sugere esses valores).

## 5. HTTPS (obrigatório)

cPanel → **SSL/TLS Status** → selecione `bipolar.app.br` e `www.bipolar.app.br` → **Run AutoSSL**. O `.htaccess` redireciona HTTP→HTTPS. Câmera, microfone, service worker e push **só funcionam com HTTPS**.

## 6. Login

### Login por e-mail e senha (funciona sem OAuth)
Já vem ativo. Exige SMTP configurado (seção 7) para os e-mails de confirmação e de “Esqueci minha senha”. A opção `'require_email_verification' => true` (padrão) exige confirmar o e-mail antes do primeiro acesso. Use `false` só para testes rápidos.

### Login OAuth (opcional)
Os botões Google/Microsoft/X só aparecem quando `client_id` (e `client_secret`, exceto no X) estão preenchidos com valores reais. Com campos vazios ou com os textos de exemplo `SEU_...`, ficam ocultos.

Contas OAuth e contas por e-mail **não são unificadas** automaticamente, mesmo com o mesmo e-mail (proteção contra sequestro de conta).


As URIs de redirecionamento devem ser **exatamente**:

| Provedor | Redirect URI |
|---|---|
| Google | `https://bipolar.app.br/auth/callback.php` |
| Microsoft | `https://bipolar.app.br/auth/callback.php` |
| X | `https://bipolar.app.br/auth/callback.php` |

A mesma URI vale para os três (V4.4: sem `?provider=`, porque a Microsoft não aceita parâmetros na URI de apps com contas pessoais). O passo a passo completo, para quem não é desenvolvedor, está em `docs/LOGIN-SOCIAL.md`.

### Passo a passo (Google, Microsoft e X)
O guia completo, para quem não é desenvolvedor, está em **`docs/LOGIN-SOCIAL.md`**: criação das credenciais nos três consoles, tela de consentimento do Google, tipos de conta e validade do segredo na Microsoft, app confidencial no X, onde colar cada valor no `config.php`, lista de testes e erros comuns (`redirect_uri_mismatch`, `AADSTS50011`…).

Resumo: Google e Microsoft usam `openid email profile` (a Microsoft também `User.Read`), o X usa `users.read tweet.read` e **não fornece e-mail** (quem entra pelo X informa o e-mail em *Conta*). Todos usam PKCE (S256) e `state`. Na Microsoft, `tenant` = `common` e tipo de conta **Any Entra ID Tenant + Personal Microsoft accounts**. No X, tipo **Web App, Automated App or Bot** (cliente confidencial).

> Contas de provedores diferentes **não são unificadas** automaticamente por e‑mail (proteção contra sequestro de conta). Oriente o usuário a sempre entrar com o mesmo provedor.

## 7. E‑mail (SMTP)

1. cPanel → **Contas de e‑mail → Criar**: `nao-responda@bipolar.app.br`.
2. Clique em **Connect Devices** para ver o servidor SMTP (geralmente `mail.bipolar.app.br`, porta **465 SSL**).
3. Preencha `smtp` no `config.php`.
4. Entregabilidade: cPanel → **Email Deliverability** → corrija/instale **SPF** e **DKIM**; adicione um registro DMARC (`_dmarc` TXT `v=DMARC1; p=none; rua=mailto:...`).

O app traz um cliente SMTP próprio (`app/lib/smtp.php`) — não é preciso PHPMailer. Se preferir PHPMailer, coloque-o em `app/vendor/` e adapte `send_mail()`.

**Falhas de envio** aparecem para a pessoa como mensagens simples (ex.: “o endereço recusou o convite — confira o e-mail”). Os detalhes técnicos vão para o **log de erros do PHP** (cPanel → *Errors*/`error_log` na pasta do site), com a etapa e a resposta do servidor, por exemplo:
`SMTP [convite] DESTINATÁRIO RECUSADO te***@bipolar.app.br pelo servidor mail.bipolar.app.br: 550 5.1.1 ... (o endereço pode não existir)`.
O endereço do destinatário é mascarado no log. Se a conexão falhar, o log sugere a combinação correta (465 = `ssl`, 587 = `tls`).

## 8. Notificações push (VAPID)

**Sem SSH:** cPanel → **Cron Jobs** → adicione uma tarefa *a cada minuto* com
`/usr/local/bin/php /home/USUARIO/tools/generate_vapid.php >> /home/USUARIO/cron/vapid.log 2>&1`,
espere 1–2 minutos e **apague a tarefa**. O script grava `storage/vapid.php` (permissão 600) e o app passa a usar as chaves automaticamente; o trecho para o `config.php` (opcional) fica em `storage/vapid-config.txt`. Ele **nunca sobrescreve** chaves existentes — trocar as chaves invalida as inscrições dos aparelhos. Com Terminal/SSH: `php ~/tools/generate_vapid.php`.

**Sem as chaves VAPID** o app esconde a opção de notificação: lembretes são só por e-mail.

O texto da notificação é **criptografado de ponta a ponta** (RFC 8291, aes128gcm, implementado em PHP com OpenSSL) e é sempre genérico (“Como está seu humor nesta tarde?”), com os botões “Registrar agora” e “Lembrar em 30 min”. Nenhum dado de saúde vai no texto. No iPhone, push funciona apenas com o app instalado na tela de início (iOS 16.4+).

## 8.1 Extensões do PHP e chave da IA (sem SSH)

- **Extensões:** cPanel → **Select PHP Version** → aba **Extensions** → marque `sodium` (obrigatória para a IA com chave própria e para a verificação em duas etapas) e `zip` (botão "Baixar tudo").
- **`ai.secret_key`:** no cron do cPanel, `php` sozinho chama o *php-cgi*, que não aceita `-r`. Gere pelo navegador:
  1. Crie `gera.php` na pasta do site com `<?php echo base64_encode(random_bytes(32));`
  2. Abra `https://SEU-DOMINIO/gera.php`, copie o valor (44 caracteres, termina com `=`) e **apague o arquivo na hora**.
  3. Cole em `'ai' => ['secret_key' => '...']` no `config.php`. **Nunca troque essa chave**: as chaves de IA salvas deixariam de abrir e, a partir da V4.4, quem ativou a verificação em duas etapas só entraria com um código de recuperação. Sem ela, a verificação em duas etapas fica indisponível.
- **Bloco `legal`:** preencha `controller` (nome e cidade do responsável) e `contact_email` no `config.php`; a Política de Privacidade usa esses dados.
- **Cron em geral:** use sempre o caminho completo `/usr/local/bin/php` (o mesmo da tabela abaixo).

## 9. Tarefas agendadas (cPanel → Cron Jobs)

| Frequência | Comando |
|---|---|
| A cada 5 minutos (`*/5 * * * *`) | `/usr/local/bin/php /home/USUARIO/cron/send_reminders.php >> /home/USUARIO/cron/reminders.log 2>&1` |
| A cada hora (`0 * * * *`) | `/usr/local/bin/php /home/USUARIO/cron/send_reports.php >> /home/USUARIO/cron/reports.log 2>&1` |
| A cada hora, minuto 7 (`7 * * * *`) | `/usr/local/bin/php /home/USUARIO/cron/analyze_signals.php >> /home/USUARIO/cron/signals.log 2>&1` (alertas automáticos, V4) |

Confira o caminho do PHP CLI (pode ser `/usr/local/bin/ea-php82` ou `/opt/cpanel/ea-php82/root/usr/bin/php`). Os relatórios saem às 08:00 no fuso do usuário; lembretes têm tolerância de 15 min.

## 10. Teste final

1. Acesse https://bipolar.app.br → *Entrar com Google*.
2. Registre um humor; abra *Histórico*.
3. Em *Relatórios*, adicione seu próprio e‑mail → confirme pelo link → aguarde o cron (ou rode o comando manualmente no Terminal).
4. Em *Lembretes*, ative notificações e crie um lembrete para daqui a 5 minutos.
5. Chrome/Edge → menu → *Instalar app*. DevTools → Lighthouse → PWA.
6. Verifique que `https://bipolar.app.br/../app/config.php` **não** é acessível e que `https://bipolar.app.br/schema.sql` dá 403/404.

## 11. Segurança e LGPD — resumo do que já está implementado

- Sessão `HttpOnly`, `Secure`, `SameSite=Lax`, regeneração de ID no login; CSRF em todas as requisições de escrita; `state` + PKCE (S256) em todos os provedores.
- PDO com *prepared statements*; CSP restritiva, HSTS, `X-Frame-Options`, `nosniff`, `Permissions-Policy`.
- Limite de tentativas (login, registros, uploads, convites, códigos da verificação em duas etapas).
- **Verificação em duas etapas (V4.4)**, opcional, para contas de e-mail e senha: app autenticador (TOTP, RFC 6238) com janela de ±1 passo e bloqueio de reuso, 10 códigos de recuperação de uso único (só o hash fica guardado) e, se a pessoa tiver, a biometria (passkey) como segunda etapa. A chave do autenticador fica cifrada com uma subchave derivada de `ai.secret_key`: sem ela, a ativação fica bloqueada. Login por Google, Microsoft ou X não pede o código do Bipolar: a segunda etapa fica a cargo do provedor.
- Mídias fora do `public_html`, nome aleatório, validação de MIME por `finfo`, limites por tipo, entregues somente ao dono via `/api/media.php` (com suporte a *Range* para vídeo).
- Exportação JSON/CSV, exclusão total da conta, política de privacidade, opt‑in duplo dos destinatários e link de cancelamento em todo relatório.
- **Backups**: cPanel → *Backup* (ou JetBackup). Dados de saúde são sensíveis — guarde backups com criptografia e restrinja acesso.

## 12. cPanel ou VPS?

| | cPanel compartilhado | VPS |
|---|---|---|
| Custo | Baixo (R$ 15–40/mês) | Médio (R$ 30–150/mês) |
| Manutenção | Provedor cuida de SO, SSL, e‑mail | Você cuida de atualizações, firewall, backups |
| Mídia (áudio/vídeo) | Limitada por cota de disco e `upload_max_filesize` | Armazenamento escalável (S3/R2 compatível) |
| Cron | Mínimo 1–5 min, limites de CPU | Livre (systemd timers, filas) |
| Push/e‑mail em massa | Limites de envio por hora do provedor | SMTP transacional (SES, Postmark, Brevo) |

**Recomendação:** comece no **cPanel** — o app foi feito para isso e atende tranquilamente uso pessoal ou algumas centenas de usuários. Migre para **VPS** quando houver muitos usuários, muito vídeo, necessidade de filas ou limites de e‑mail. Stack sugerida para VPS: Ubuntu 24.04 LTS, Nginx + PHP‑FPM 8.3, MariaDB 10.11 (ou MySQL 8), Certbot, mídia em armazenamento S3‑compatível com criptografia, e‑mail transacional (Amazon SES/Postmark), Redis para sessões/limites, backups automáticos criptografados (restic), fail2ban + UFW, monitoramento (Uptime Kuma). O código roda sem alterações; troque apenas `config.php` e as tarefas cron por timers do systemd.

## 13. Atualizações

- **Correções do QA 1 (outubro/2026):** só arquivos, sem mudança no banco. Passo a passo em `ATUALIZACAO-QA1.md`.
- **Login por e-mail (outubro/2026):** execute `migrations/2026-10-14-email-login.sql` no phpMyAdmin (SQL simples, compatível com MySQL 5.7; se aparecer `#1060 Duplicate column`, pule aquela linha). Passo a passo completo em `ATUALIZACAO-EMAIL.md`.
- **V2 — marcadores diários, Sexualidade (privado) e Indicadores de mania (outubro/2026):** execute `migrations/2026-10-15-v2-marcadores.sql` no phpMyAdmin (SQL simples; `#1060 Duplicate column` = linha já aplicada, pule-a). Pacote cumulativo (inclui o QA1). Passo a passo em `ATUALIZACAO-V2.md`; contexto em `docs/SEXUALIDADE.md` e `docs/COMPARATIVO-ESTABILIZA.md`.
- **V3 — Substâncias (redução de danos) (outubro/2026):** execute `migrations/2026-10-16-v3-substancias.sql` depois da V2. O pacote é cumulativo (QA1 + V2 + V3). Passo a passo em `ATUALIZACAO-V3.md`, com o SQL único; o contexto está em `docs/SUBSTANCIAS.md`.
- **V4 — alertas automáticos, biometria, push com texto e IA opcional (outubro/2026):** execute `migrations/2026-10-17-v4-alertas-biometria-ia.sql`. Passo a passo em `ATUALIZACAO-V4.md`; contexto em `docs/ALERTAS.md` e `docs/IA-E-BIPOLARIDADE.md`.
- **Todas as migrações são SQL simples** (o phpMyAdmin da hospedagem não lê `information_schema`): se uma linha der `#1060 Duplicate column`/`#1061 Duplicate key`, ela já foi aplicada — apague-a e rode o restante.

### Instalação antiga com período “madrugada”

Se você instalou uma versão anterior, migre antes de enviar os novos arquivos (phpMyAdmin → SQL):
```sql
UPDATE entries e LEFT JOIN entries n ON n.user_id = e.user_id AND n.entry_date = DATE_SUB(e.entry_date, INTERVAL 1 DAY) AND n.period = 'noite'
  SET e.entry_date = DATE_SUB(e.entry_date, INTERVAL 1 DAY), e.period = 'noite'
  WHERE e.period = 'madrugada' AND n.id IS NULL;
DELETE FROM entries WHERE period = 'madrugada'; -- sobra apenas quando já havia registro da noite anterior
ALTER TABLE entries MODIFY period ENUM('manha','tarde','noite') NOT NULL;
```
(registros da madrugada passam a contar como “noite” do dia anterior).

## 14. Testes automatizados

A pasta `tests/` **não deve ser enviada ao servidor**. Ela contém PHPUnit (133 testes unitários e de integração/segurança), PHPStan, varredura de padrões inseguros, ESLint, axe-core e Lighthouse. Veja `tests/RELATORIO-TESTES.md` para resultados e como executar (`tests/run-all.sh`).

### Teste de carga

O kit fica fora do pacote do site (`loadtest/`, script k6). Ele usa duas chaves **opcionais e desativadas por padrão** no `config.php`:

| Chave | Efeito | Depois do teste |
|---|---|---|
| `loadtest_bypass_ratelimit_token` | Com 32+ caracteres, requisições com o cabeçalho `X-Loadtest-Bypass: <valor>` ignoram os limites de tentativas | **apague o valor** (`''`) |
| `loadtest_admin_secret` | Ativa `/loadtest-admin.php` (criar/apagar contas `loadtest+NNN@`); sem ele, a página dá 404 | **apague o valor** (`''`) |

Contas de teste também podem ser criadas/apagadas por Cron: `tools/seed_loadtest.php` e `tools/cleanup_loadtest.php`. Detalhes em `loadtest/README.md`.

## 15. Desenvolvimento local

```bash
cp app/config.example.php app/config.php   # ajuste banco; 'app_url' => 'http://127.0.0.1:8080', 'dev_login' => true
mysql -u root -p bipolar < schema.sql
cd public_html && php -S 127.0.0.1:8080
```
Também é possível apontar outro arquivo de configuração com a variável de ambiente `BIPOLAR_CONFIG`.
`/auth/dev.php` faz login de teste só se `dev_login` estiver ativo **e** o acesso vier de 127.0.0.1. **Nunca** ative em produção.

## Aviso

Este aplicativo é uma ferramenta de auto‑observação e **não substitui** acompanhamento psiquiátrico e psicológico. Em crise: **CVV 188** (24h, gratuito, também em cvv.org.br), **SAMU 192**, CAPS ou UPA mais próximos.
