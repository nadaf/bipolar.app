## [2.0.4] - 2026-11-01
- Trazer dados do Bipolar 0.5: página `/migrar-dados` (com o `INSTALLER_TOKEN`) e `php artisan bipolar:import-legacy`. Banco antigo só lido (conexão `legado`, usuário só SELECT, sessão READ ONLY), em lotes, pode repetir sem duplicar, mostra só contagens. Nunca roda sozinho. Ver `docs/MIGRATION-FROM-0.5.md` e o LEIA-ME.
- Login aceita as senhas da 0.5 (bcrypt e Argon2id) e regrava em bcrypt no primeiro acesso.
- Energia antiga 0..3 convertida para −3..+3 (valor original guardado em `energy_legacy`).

## [2.0.2] - 2026-11-01
- Atualização automática pelo Cron Jobs do cPanel (`bipolar/deploy/auto-update.php`, sem SSH): busca o release no GitHub, confere sha256, faz backup (arquivos + banco, guarda 3), manutenção, troca os arquivos preservando `.env`, `storage/` e `.htaccess` customizado, roda migrations e restaura tudo se falhar. Log em `storage/logs/auto-update.log`. Desligar: `AUTO_UPDATE_ENABLED=false`.
- CI: release de tag `v*` recebe `cpanel.zip` + `cpanel.zip.sha256`; simulação do cron no CI (`scripts/smoke-auto-update.sh`).

## [2.0.0-beta.1] - 2026-10-31
- Início da reescrita 2.0 (Laravel API-first, multi-tenant). Fatia vertical: registro de humor por período.
- Energia passa a ser escala bipolar -3..+3 (Muito baixa..Muito alta).
- Alertas personalizados: cada paciente escolhe os limiares baixo/alto de humor e energia (padrão −2/+2), na API e em Ajustes > Alertas.
- Registro de dias passados sem limite (período futuro continua bloqueado no fuso da pessoa).
- Clínicas gerenciam os próprios profissionais; contas de profissional solo; profissional só vê quem concedeu acesso.
- Subdomínios opcionais `<clinica>.bipolar.app.br` e `<usuario>.bipolar.app.br`, com nomes reservados; token e sessão amarrados ao tenant; cookie por host.

### Frontend web (beta 2.0)
- Novas telas em pt-BR: Registrar (cartões por bloco, Salvar e continuar, Salvar tudo com resumo), Histórico (calendário e dia), Relatórios (gráfico SVG e dias), Alertas (limiares e grade de lembretes), Conta (perfil, PIN, e-mail, módulos, exportar JSON, excluir), Compartilhar (convites com escopos), visão de quem cuida e administração da Clínica.
- Navegação inferior no celular (360–412px) e lateral no desktop; tema claro/escuro seguindo o aparelho, com botão que lembra a escolha.
- CSP estrita sem script, estilo ou on*= inline (Alpine CSP + HTMX); PWA com Workbox, manifesto e ícones; sem analytics.
- Blocos sensíveis exigem desbloqueio de 10 minutos; recursos de outra pessoa respondem 404.
- Testes: CSP por tela e isolamento (Pest); axe 0 serious/critical em 412px e 1280px, claro e escuro.

### Pacote cpanel.zip (sem npm, composer ou SSH)
- `scripts/build-cpanel-zip.sh` gera o `cpanel.zip` (o mesmo script roda local e no CI, artefato `cpanel.zip`): `bipolar/` com `vendor/` pronto e `lang/`, `public_html/` com `build/`, `sw.js` e ícones, `banco/instalar-banco-novo.sql` e `banco/atualizar-banco.sql`, `cron.txt` e `LEIA-ME.txt` em português simples. Sem `.env`, sem config em cache.
- `atualizar-banco.sql` (phpMyAdmin) aplica só as migrations que faltam e pode ser importado de novo.
- Atualizador web `/atualizar` (e `/instalar`) com código de uso único do `.env`: depois de usado, o mesmo código dá 404.
- CI simula o cPanel (`scripts/smoke-cpanel-zip.sh`): extrai, importa o SQL no MySQL 5.7, sobe `php -S` na `public_html`, cria conta, entra, `/registrar`, assets e atualizador, sem nenhum 500.
- Corrigido (#1050 "Tabela 'tenants' já existe"): `atualizar-banco.sql` criava `migrations` e `tenants` antes de conferir o banco, e falhava num banco do Bipolar 0.5. Agora os dois SQL e o instalador web conferem o banco antes (sem `information_schema`) e **PARAM sem alterar nada** se for banco do 0.5, de outro sistema ou (na instalação) não vazio. A atualização é retomável: o que já existe é pulado.
- O 2.0 sempre usa um banco NOVO e vazio; o banco do 0.5 nunca é alterado (migração dos dados do 0.5 virá num script separado).
- Instalador web cria `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY`/`VAPID_SUBJECT` no `.env` quando vazios (sem SSH); nunca sobrescreve nem mostra a chave privada. Sem VAPID, Alertas mostra "Notificações indisponíveis no servidor".
- Corrigido: o pacote antigo não levava a pasta `lang/` (traduções pt-BR). Agora vai junto.

### Recuperação de senha e logo
- "Esqueci minha senha" em /entrar: link por e-mail assinado, de uso único, vale 60 min, só o hash do token no banco, amarrado ao endereço (host/tenant), limite 3/min por e-mail e 20/h por IP, resposta neutra (não revela se o e-mail existe). Ao trocar, encerra tokens da API e sessões. API: `/auth/password/forgot`, `/auth/password/reset`. Tabela nova `password_resets` (o `atualizar-banco.sql` cria).
- Logo no topo, no menu lateral e nas telas Entrar/Criar conta (antes só aparecia na abertura do app instalado).
- Sem `MAIL_FROM_ADDRESS`, o remetente vira `nao-responda@<domínio do APP_URL>` (antes o envio falhava).
- `cpanel.zip`: a pasta do site agora é `bipolar-2-0-public/` (não `public_html/`), para atualizar só extraindo por cima, sem renomear e sem tocar no site antigo.

### Segurança, exportação e convites
- Verificação em duas etapas (TOTP) opcional em Conta, com QR gerado no servidor, 10 códigos de recuperação de uso único, limite de tentativas e segredos cifrados. Clínica pode exigir 2FA da equipe. Passkeys ficam para depois.
- Exportar registros em CSV (Excel pt-BR) ou PDF, com intervalo de datas; profissional exporta só quem concedeu acesso, dentro do escopo. Toda exportação fica no registro de auditoria; nome de arquivo neutro.
- Convite de profissional por e-mail (link de uso único, 7 dias, só no endereço da clínica); a pessoa cria a conta ou entra com a que já tem. E-mail via SMTP do cPanel (`MAIL_*`).
- API: `/account/mfa*`, `/auth/mfa/totp`, `/export/entries`, `/care/patients/{id}/export`, `/clinic/invites*`, `/clinic-invites/{token}*`, `/clinic/settings`.

### PWA: lembretes, atalhos e offline
- Lembretes reais por Web Push (VAPID, `minishlink/web-push`, sem SSH): ativar/desativar por aparelho em Alertas, grade semanal no fuso da pessoa, comando `bipolar:send-reminders` no agendador (cron do cPanel a cada minuto), uma notificação por período, inscrições expiradas removidas. Texto sempre neutro ("Hora do seu registro"), abre /registrar. Chaves só no `.env` (`php artisan bipolar:vapid`).
- Lembrete por e-mail continua para depois (a tela diz isso).
- Atalhos do manifesto: Registrar agora, Histórico, Relatórios, com ícones da marca.
- Indicador de rede: sem internet mostra quantos registros estão guardados no aparelho; ao voltar, "Sincronizado". Página offline nova, sem script.
- Tabela `push_subscriptions` (isolada por tenant) e `install-mysql57.sql` regenerado.

### Revisão de usabilidade (31/10/2026)
- Alertas: grade de lembretes com dias nas linhas (cabe em 375px) e aviso honesto de que o envio ainda não está ligado.
- Clínica: remover profissional pede confirmação em diálogo acessível (Esc, foco volta ao botão); explica que o paciente é quem convida pelo Compartilhar.
- PWA: aviso "Há uma versão nova" com botão Atualizar (Workbox SKIP_WAITING) e seção "Instalar o app" em Conta (botão nativo + instruções iOS/Android).
- Títulos de aba neutros (sem nome do paciente nem data); familiar vê "Como ajudar" junto aos sinais, com CVV 188 e SAMU 192.
- Campos de data com `lang="pt-BR"`; teste de navegador roda o Chrome em pt-BR (`--lang` + `LANGUAGE=pt_BR`).

### Marca
- Ícones a partir do logo oficial (medidor de humor, redesenhado em SVG fiel ao PNG publicado): favicon.ico 16/32/48 e favicon.svg com modo escuro, apple-touch-icon, PWA 192/512 (any e maskable), monocromático e selo de notificação.
- Abertura: cores no manifesto (Android), telas de abertura do iOS para iPhone e iPad, e abertura breve dentro do app instalado (só CSS, respeita redução de movimento).
- Pasta `brand/` para as lojas: ícone 1024, ícone adaptativo do Android, imagem de destaque do Play, imagem de compartilhamento 1200×630 e BRAND.md. Tudo é gerado por `scripts/build-brand.py`.
