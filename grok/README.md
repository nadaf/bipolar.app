# Bipolar — Diário de Humor

PWA em PHP puro para pessoas com transtorno bipolar registrarem humor, sono, medicação e sinais do dia, com gráficos inspirados no NIMH Life Chart Method, alertas automáticos e personalizados, lembretes, relatórios para pessoas de apoio e, se a pessoa quiser, reflexões com IA usando a **própria chave** de um provedor à escolha.

Site oficial: https://bipolar.app.br · Licença: **AGPL-3.0** · Idioma da interface: português do Brasil.

> Não é um dispositivo médico e não substitui acompanhamento profissional. Em crise: **CVV 188** · **SAMU 192**.

## Princípios

- **Sem área de administração.** Não existe papel de administrador, painel ou rota para ver dados de outras pessoas, nem para o mantenedor. A situação das tarefas cron existe só em arquivos no servidor (`storage/heartbeat-*.json`, `storage/logs/cron.log`) e pode ser lida com `php tools/cron_status.php`.
- **Privacidade por padrão.** Sexualidade e Substâncias são módulos opcionais, protegidos por biometria (passkey) ou PIN. Push e e-mails de aviso nunca contêm conteúdo sensível. Pessoas de apoio recebem só o que a pessoa autorizar e, dos alertas, apenas a quantidade.
- **Seus dados são seus.** Exportação completa (ZIP com JSON, CSV e mídias) e exclusão imediata da conta inteira em Conta.
- **IA opcional e BYOK.** Cada pessoa escolhe o provedor (xAI, OpenAI, Anthropic, Google Gemini, DeepSeek, Mistral, OpenRouter ou URL compatível com OpenAI), o modelo, e cola a própria chave, que fica cifrada com libsodium. Nada vai para a IA sem consentimento.
- **Honestidade sobre os limites.** Os registros não são cifrados de ponta a ponta: quem opera o servidor tem acesso técnico ao banco. Veja a [Política de Privacidade](public_html/privacidade.php).

## Requisitos

- PHP 8.1+ (testado em 8.4; produção em 8.3) com `pdo_mysql`, `curl`, `openssl`, `mbstring`, `fileinfo`, `sodium` (IA com chave própria) e `zip` (exportação completa; opcional).
- MySQL 5.7+ ou MariaDB 10.3+.
- Sem Composer, sem Node, sem etapa de build: é só enviar os arquivos (cPanel funciona).

## Instalação rápida

1. Copie `app/config.example.php` para `app/config.php` e preencha banco, SMTP, VAPID (`php tools/generate_vapid.php`) e `ai.secret_key` (`php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'`).
2. Importe `schema.sql` no banco.
3. Aponte o domínio para `public_html/` e mantenha `app/`, `cron/`, `storage/` e `tools/` **fora** da pasta pública.
4. Agende os 3 crons (`cron/analyze_signals.php`, `cron/send_reminders.php`, `cron/send_reports.php`).

Guia completo para cPanel: [README-INSTALACAO.md](README-INSTALACAO.md). Atualizações: `ATUALIZACAO-*.md` (SQL sempre primeiro).

## Testes

```bash
cd tests && composer install && (cd js && npm install)
./run-all.sh      # PHPUnit, php -l, PHPStan nível 6, ESLint e verificações de navegador
```

Os testes de integração precisam de um MySQL 5.7 local (porta 3307, banco `bipolar_test`, usuário `bp`/`bp`) — veja `tests/RELATORIO-TESTES.md`.

## Por que AGPL-3.0?

Este app guarda dados de saúde mental muito sensíveis. A AGPL garante que **qualquer pessoa que rode uma versão modificada como serviço na internet precise publicar o código dessa versão** para os seus usuários. Assim, quem usa uma cópia hospedada por outra pessoa sempre pode conferir o que é feito com os seus dados — inclusive que não existe área de administração escondida. Uma licença permissiva (MIT, Apache) permitiria fechar o código de uma versão hospedada; a GPL comum não cobre o uso pela rede.

## Contribuindo e segurança

- Como contribuir: [CONTRIBUTING.md](CONTRIBUTING.md)
- Como relatar vulnerabilidades (em privado): [SECURITY.md](SECURITY.md)

---

## English summary

**Bipolar — Mood Diary** is a plain-PHP (no build step) progressive web app for people living with bipolar disorder. It tracks mood per period of the day (inspired by the NIMH Life Chart Method), sleep, medication and daily markers, raises automatic and user-defined alerts, sends reminders and opt-in reports to support people, and offers optional AI reflections where **each user brings their own API key** (xAI, OpenAI, Anthropic, Google Gemini, DeepSeek, Mistral, OpenRouter or any OpenAI-compatible URL); keys are encrypted at rest with libsodium and never shown again.

Design commitments: **no admin area at all** (no admin role, panel or route; cron status lives only in server-side files), sensitive modules (sexuality, substances) are opt-in and behind passkey/PIN, push and notification e-mails never carry sensitive content, full export (ZIP) and immediate full-account deletion. Data is not end-to-end encrypted: the server operator technically has database access, and this is stated plainly in the privacy policy.

Licensed under **AGPL-3.0** so that anyone hosting a modified version must share its source with their users. The UI and docs are in Brazilian Portuguese. Not a medical device.
