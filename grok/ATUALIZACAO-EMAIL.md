# Atualização: login com e-mail e senha

Esta atualização adiciona **cadastro e login por e-mail e senha** (com confirmação de e-mail, “Esqueci minha senha” e troca de senha), e **esconde automaticamente** os botões Google/Microsoft/X enquanto as credenciais OAuth não estiverem preenchidas. Assim o site funciona só com e-mail.

> Sua instalação usa domínio adicional (*addon domain*):
> - `/home/USUARIO/bipolar.app.br/` → arquivos que no pacote estão em `public_html/`
> - `/home/USUARIO/app/`, `/home/USUARIO/cron/`, `/home/USUARIO/storage/`, `/home/USUARIO/tools/` → código privado
>
> Troque `USUARIO` pelo seu usuário do cPanel (aparece no canto da tela e no caminho do Gerenciador de Arquivos).

Tempo estimado: 15–20 minutos.

> Este pacote de atualização vale para quem instalou a versão de **14/10/2026 (16h15)**, a que tem apenas os períodos Manhã/Tarde/Noite. Se você instalou a **primeira** versão (com “Madrugada”), envie o **pacote completo** `bipolar-app.zip` (todo o conteúdo de `public_html/` e `app/`, mantendo seu `config.php`), rode antes a migração da seção 13 do `README-INSTALACAO.md` e depois siga daqui a partir do Passo 1.
> Para conferir: se o arquivo `/home/USUARIO/app/lib/validate.php` existe, você está na versão de 16h15.

---

## Passo 0 — Backup (2 minutos)

1. cPanel → **phpMyAdmin** → selecione o banco do app → aba **Exportar** → *Executar*. Guarde o arquivo `.sql`.
2. cPanel → **Gerenciador de Arquivos** → clique com o botão direito em `app` → **Compress** → ZIP. Faça o mesmo com `bipolar.app.br`.

## Passo 1 — Banco de dados (migração)

1. cPanel → **phpMyAdmin** → clique no banco do app (ex.: `USUARIO_bipolar`) na coluna da esquerda.
2. Aba **SQL** → abra o arquivo `migrations/2026-10-14-email-login.sql` (do pacote de atualização) num editor de texto, copie **todo** o conteúdo e cole na caixa → **Executar**.
3. No fim aparece uma tabela com 3 linhas: `password_hash`, `email_verified_at`, `session_epoch`. Pronto.

> **Importante (hospedagem):** o usuário do phpMyAdmin não tem acesso ao `information_schema` (erro `#1044`). Por isso a migração é SQL simples: `ALTER TABLE` e `CREATE TABLE IF NOT EXISTS`.
> **Se aparecer `#1060 Duplicate column name` (ou `#1061 Duplicate key name`)**, aquela linha já tinha sido aplicada: apague do editor as linhas até ela (inclusive) e clique em **Executar** de novo para rodar o restante. `CREATE TABLE IF NOT EXISTS` nunca dá erro se a tabela já existir.

A migração pode ser executada mais de uma vez seguindo a regra acima. Ela não apaga nem altera dados: só adiciona 3 colunas em `users` e a tabela `auth_tokens`.

## Passo 2 — Enviar os arquivos

Extraia `bipolar-update-email.zip` no seu computador. Ele tem a mesma estrutura do pacote completo, **apenas com os arquivos novos/alterados**.

No **Gerenciador de Arquivos**, envie (botão **Carregar**) substituindo os existentes:

| Arquivos do pacote | Pasta no servidor |
|---|---|
| `public_html/index.php`, `public_html/privacidade.php`, `public_html/relatorio.php`, `public_html/_boot.php` | `/home/USUARIO/bipolar.app.br/` |
| `public_html/conta/` — **pasta nova**: `cadastro.php`, `entrar.php`, `esqueci.php`, `redefinir.php`, `reenviar.php`, `verificar.php` | `/home/USUARIO/bipolar.app.br/conta/` (crie a pasta `conta`) |
| `public_html/api/account.php`, `public_html/api/recipients.php` | `/home/USUARIO/bipolar.app.br/api/` |
| `public_html/auth/start.php`, `public_html/auth/callback.php` | `/home/USUARIO/bipolar.app.br/auth/` |
| `public_html/assets/css/app.css` | `/home/USUARIO/bipolar.app.br/assets/css/` |
| `public_html/assets/js/app.js`, `public_html/assets/js/auth.js` (novo) | `/home/USUARIO/bipolar.app.br/assets/js/` |
| `app/bootstrap.php`, `app/config.example.php` | `/home/USUARIO/app/` |
| `app/lib/auth.php`, `app/lib/helpers.php`, `app/lib/oauth.php`, `app/lib/password_auth.php` (novo) | `/home/USUARIO/app/lib/` |
| `app/views/auth.php` (novo) | `/home/USUARIO/app/views/` |
| `cron/send_reminders.php`, `cron/send_reports.php` | `/home/USUARIO/cron/` |
| `migrations/2026-10-14-email-login.sql` | não precisa enviar (é só para colar no phpMyAdmin). Se quiser guardar, use `/home/USUARIO/migrations/`, **nunca** dentro de `bipolar.app.br` |
| `schema.sql`, `README-INSTALACAO.md`, `ATUALIZACAO-EMAIL.md`, `tests/` | não envie (documentação e testes) |

**Não** envie nem substitua o seu `app/config.php` (ele não vem no pacote).

Dica: o jeito mais rápido é carregar o ZIP em `/home/USUARIO/`, clicar em **Extract**, e então mover o conteúdo de `public_html/` para `bipolar.app.br/` (marque *Sobrescrever*). As pastas `app/`, `cron/` e `migrations/` já caem no lugar certo ao extrair em `/home/USUARIO/`. Depois apague a pasta `public_html` extraída se ela não for a do seu domínio principal — **cuidado**: se o seu domínio principal usa `public_html`, extraia o ZIP numa pasta temporária (ex.: `/home/USUARIO/atualizacao/`) e mova os arquivos manualmente.

## Passo 3 — `config.php` (opcional, mas recomendado)

Abra `/home/USUARIO/app/config.php` (botão direito → **Edit**) e adicione, logo abaixo da linha `'dev_login' => false,`:

```php
    'require_email_verification' => true,
```

- `true` (padrão, recomendado): a pessoa precisa clicar no link enviado por e-mail antes do primeiro login.
- `false`: entra direto após o cadastro. Use **só** para um teste rápido enquanto o SMTP não funciona e volte para `true` depois (com `false`, qualquer pessoa pode cadastrar o e-mail de outra).

Se a chave não existir, vale `true`.

**OAuth:** não precisa mexer. Enquanto `client_id`/`client_secret` estiverem vazios ou com os textos de exemplo (`SEU_...`), os botões “Entrar com Google/Microsoft/X” ficam ocultos. Quando você configurar um provedor, o botão aparece sozinho.

## Passo 4 — E-mail (SMTP) — necessário para confirmar cadastro e redefinir senha

1. cPanel → **Contas de e-mail** → **+ Criar**. Domínio `bipolar.app.br`, usuário `nao-responda`, senha forte (anote) → **Criar**.
2. Na lista, clique em **Connect Devices** (Conectar dispositivos) dessa conta. Anote **Servidor de saída (SMTP)** e a **porta SSL/TLS** (normalmente `mail.bipolar.app.br` e `465`).
3. Em `/home/USUARIO/app/config.php`, preencha:

```php
    'smtp' => [
        'host' => 'mail.bipolar.app.br',
        'port' => 465,
        'secure' => 'ssl',            // use 'tls' se a porta for 587
        'user' => 'nao-responda@bipolar.app.br',
        'pass' => 'A_SENHA_QUE_VOCE_CRIOU',
        'from_email' => 'nao-responda@bipolar.app.br',
        'from_name' => 'Bipolar — Diário de Humor',
    ],
```

4. cPanel → **Email Deliverability** (Capacidade de entrega de e-mail) → em `bipolar.app.br`, clique em **Repair**/**Install** para **SPF** e **DKIM** (ajuda a não cair no spam).
5. Alguns provedores bloqueiam a porta 465 para scripts. Se o e-mail não chegar, tente `'port' => 587, 'secure' => 'tls'`, ou `'host' => 'localhost', 'port' => 25, 'secure' => ''` (servidor local da hospedagem). Erros aparecem em `/home/USUARIO/bipolar.app.br/error_log`.

## Passo 5 — Testar

1. Abra **https://bipolar.app.br** em uma janela anônima. Deve aparecer o formulário **Entrar** (e-mail e senha), sem botões de Google/Microsoft/X.
2. Clique em **Criar conta** → preencha nome, seu e-mail, uma senha (ex.: uma frase com 3–4 palavras) → marque o consentimento → **Criar conta**.
3. Abra o e-mail “Confirme seu e-mail” (veja o spam) → clique em **Confirmar e-mail** → na página, **Confirmar meu e-mail**. Você entra no app.
4. Faça um registro de humor. Em **Conta**, teste **Alterar senha** e depois **Sair**.
5. Na tela inicial, clique em **Esqueci minha senha**, informe o e-mail, abra o link (válido por 60 minutos) e crie uma nova senha.

**Sem SMTP ainda?** Duas opções:
- Coloque `'require_email_verification' => false` temporariamente; **ou**
- Cadastre-se normalmente e confirme manualmente no phpMyAdmin (aba SQL):
  ```sql
  UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE email = 'seu-email@exemplo.com';
  ```

## Como funciona (resumo de segurança)

- Senhas guardadas com `password_hash` (**Argon2id** quando o PHP suporta; senão bcrypt) e atualizadas automaticamente se o algoritmo mudar.
- Links de confirmação (24 h) e redefinição (60 min, uso único) são aleatórios de 256 bits; no banco fica só o **SHA-256**.
- Mensagens genéricas: o site nunca diz se um e-mail está ou não cadastrado (no login, cadastro, “esqueci” e “reenviar”). Se alguém tentar cadastrar um e-mail já existente, o dono recebe um aviso.
- Limites: login 10 tentativas/15 min por e-mail e 30/15 min por IP; cadastro 5/h por IP; “esqueci” e “reenviar” 3/h por e-mail.
- Trocar ou redefinir a senha **encerra as sessões nos outros aparelhos**.
- Contas Google/Microsoft/X e contas por e-mail **não são unificadas automaticamente**, mesmo com o mesmo e-mail (evita sequestro de conta). Entre sempre pelo mesmo método. A vinculação explícita de contas ficou para uma versão futura.

## Desfazer (se algo der errado)

Restaure os arquivos do backup do Passo 0. As colunas novas do banco não atrapalham a versão antiga, então não é preciso desfazer a migração.
