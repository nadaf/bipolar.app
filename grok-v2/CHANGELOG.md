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
