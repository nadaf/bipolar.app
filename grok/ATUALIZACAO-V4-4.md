# Atualização V4.4: verificação em duas etapas, correções de 404 e guia do login social

Este pacote parte da **V4.3.1 já instalada** e traz **só os arquivos que mudaram**. Seu `app/config.php` não vem no pacote e não é substituído.

Tempo estimado: 15 minutos. **Primeiro rode o SQL (seção 1), depois envie os arquivos (seção 2).**

## 1. SQL (phpMyAdmin → banco `rona3541_bipolargrok` → aba SQL)

Faça antes o backup: phpMyAdmin → **Exportar** → Rápido → SQL → Executar.

Cole e execute:

```sql
ALTER TABLE users ADD COLUMN mfa_totp_secret_enc TEXT NULL;
ALTER TABLE users ADD COLUMN mfa_enabled_at DATETIME NULL;
ALTER TABLE users ADD COLUMN mfa_totp_last_step BIGINT UNSIGNED NULL;
CREATE TABLE IF NOT EXISTS mfa_recovery_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mfa_rc (user_id, code_hash),
  CONSTRAINT fk_mfa_rc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- Se aparecer **#1060 Duplicate column name** (ou **#1061 Duplicate key name**), essa linha já foi aplicada: **pule essa linha** e execute as seguintes. O `CREATE TABLE IF NOT EXISTS` pode rodar de novo sem erro.
- Nada existente é alterado: todas as contas começam com a verificação em duas etapas **desligada**.
- O mesmo SQL está em `migrations/2026-10-27-v4-4-verificacao-duas-etapas.sql`.
- Conferência: `SHOW COLUMNS FROM users LIKE 'mfa%';` deve listar 3 linhas, e `SHOW TABLES LIKE 'mfa_recovery_codes';`, 1 linha.
- **Rode o SQL antes de enviar os arquivos.** Sem as colunas novas, o login por e-mail e senha dá erro 500.

## 2. Envie os arquivos (substituindo os existentes)

| Arquivo(s) do pacote | Enviar para |
|---|---|
| `public_html/index.php`, `privacidade.php`, `sw.js` | `/home1/rona3541/bipolar.app.br/` |
| `public_html/api/account.php`, `custom_alerts.php`, `entries.php`, `media.php`, `mfa.php` (novo), `push.php`, `recipients.php`, `reminders.php`, `webauthn.php` | `/home1/rona3541/bipolar.app.br/api/` |
| `public_html/assets/css/app.css` | `/home1/rona3541/bipolar.app.br/assets/css/` |
| `public_html/assets/js/app.js`, `mfa.js` (novo), `passkey.js` | `/home1/rona3541/bipolar.app.br/assets/js/` |
| `public_html/auth/callback.php` | `/home1/rona3541/bipolar.app.br/auth/` |
| `public_html/conta/entrar.php`, `mfa.php` (novo), `verificar.php` | `/home1/rona3541/bipolar.app.br/conta/` |
| `app/bootstrap.php`, `config.example.php` | `/home1/rona3541/app/` |
| `app/lib/mfa.php` (novo), `qr.php` (novo), `oauth.php` | `/home1/rona3541/app/lib/` |
| `app/views/account_mfa.php` (novo), `layout.php`, `lock.php` | `/home1/rona3541/app/views/` |
| `docs/`, `migrations/`, `schema.sql`, `tests/`, `*.md` | **não** envie (guia, SQL, testes e documentação) |

- **Não substitua o seu `config.php`.** O `config.example.php` só ganhou comentários.
- O service worker mudou (`bp-v10`, com `app.js?v=10`, `app.css?v=10`, `passkey.js?v=4` e o novo `mfa.js?v=1`). Se algo parecer antigo, feche e abra o app de novo.
- Nada muda no cron.

## 3. O que conferir no `config.php`

1. **`ai.secret_key` preenchida.** A verificação em duas etapas cifra a chave do app autenticador com uma subchave derivada dela. Sem `ai.secret_key`, o cartão em Conta mostra “A verificação em duas etapas ainda não está disponível neste servidor…” e ninguém consegue ativar. **Não troque nem apague esse valor depois que alguém ativar**: quem tiver a verificação ligada só conseguiria entrar com um código de recuperação. Se ainda não preencheu, gere o valor sem SSH como explicado em `README-INSTALACAO.md`, seção 8.1 (e confira se a extensão `sodium` está marcada em **Select PHP Version → Extensions**).
2. **Login social (opcional).** A URL de retorno agora é a mesma para os três: **`https://bipolar.app.br/auth/callback.php`** (sem `?provider=`). Siga o guia **`docs/LOGIN-SOCIAL.md`** (cópia também em `LOGIN-SOCIAL.md`). Se já tinha cadastrado a URL antiga no Google ou no X, adicione a nova no console.

## O que mudou

**Verificação em duas etapas (opcional, Conta → 🛡️ Verificação em duas etapas)**
- Para contas de **e-mail e senha**. Ativar pede a senha, mostra um **QR code gerado no próprio servidor** (nenhum serviço externo) e a chave em texto para digitar à mão. Funciona com Google Authenticator, Microsoft Authenticator, Authy, Aegis e outros (TOTP, RFC 6238: 6 dígitos, 30 segundos, SHA-1).
- Ao confirmar o primeiro código, a pessoa recebe **10 códigos de recuperação** (uso único, guardados só como hash), com botões para copiar e baixar. Os outros aparelhos saem da conta.
- **Login:** depois da senha certa, a tela “Verificação em duas etapas” pede o código do app ou um código de recuperação. Aceita o código anterior e o seguinte (±30 s, para relógios um pouco fora), **não aceita o mesmo código duas vezes** e limita as tentativas (5 por login, depois a senha de novo, e 10 por conta a cada 15 minutos). O login pendente expira em 5 minutos.
- Quem já cadastrou a **biometria (passkey)** pode usá-la no lugar do código, na mesma tela.
- **Desativar** ou **gerar novos códigos** exige a senha **e** um código atual (do app ou de recuperação).
- Um **aviso por e-mail** é enviado ao ativar, desativar ou gerar novos códigos, sem a chave nem códigos.
- **Login com Google, Microsoft ou X não pede o código do Bipolar**: a segunda etapa fica a cargo do provedor. Entrar com passkey na página inicial também não pede, porque a passkey já é um fator forte.
- Não há “lembrar este aparelho”: ficou de fora de propósito, para não criar um atalho que contorne a segunda etapa.
- Política de Privacidade, `README-INSTALACAO.md` e `SECURITY.md` atualizados. A exportação de dados não inclui a chave nem os códigos.

**Correções do QA da V4.3.1**
- `DELETE /api/recipients.php` com id de outra pessoa (ou já apagado) agora responde **404 “Destinatário não encontrado.”** em vez de `200 {ok:true}`.
- O mesmo padrão foi revisado em **todas** as rotas de excluir e alterar: registros de humor, mídias, lembretes, aparelhos de notificação (excluir e renomear) e biometria (excluir e renomear) agora respondem **404** quando o item não é da pessoa. As demais já respondiam 404. A UI mostra a mensagem e recarrega a lista.
- **Alerta personalizado:** o dono é conferido **antes** da checagem de duplicidade. Salvar com o `id` de outra pessoa responde 404, nunca 409.

**Login social**
- Novo guia `docs/LOGIN-SOCIAL.md`, passo a passo para Google, Microsoft e X.
- A URL de retorno deixou de usar `?provider=`: a Microsoft **não aceita** parâmetros na URL de apps com contas pessoais (tenant `common`), então o login pela Microsoft não funcionaria com a URL antiga. O provedor agora vem do login iniciado na sessão.
- Os botões ficam ocultos enquanto o `config.php` tiver valores vazios **ou de exemplo** (`SEU_...`, `COLE_AQUI...`, `TROQUE...`, `...`).

## Ressalvas
- Testado em PHP 8.4 e MySQL 5.7.44 (o servidor usa PHP 8.3; nenhum recurso exclusivo do 8.4 foi usado).
- O relógio do servidor precisa estar certo (HostGator usa NTP). Se o celular da pessoa estiver com a hora manual errada em mais de 30 segundos, o código não confere: a tela orienta a deixar a hora automática.
- **X:** contas novas de desenvolvedor não têm mais plano gratuito. Cada login pelo X faz 1 leitura paga do perfil (cerca de US$ 0,01). Veja `docs/LOGIN-SOCIAL.md`, seção 3.
