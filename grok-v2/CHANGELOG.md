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

### Marca
- Ícones a partir do logo oficial (medidor de humor, redesenhado em SVG fiel ao PNG publicado): favicon.ico 16/32/48 e favicon.svg com modo escuro, apple-touch-icon, PWA 192/512 (any e maskable), monocromático e selo de notificação.
- Abertura: cores no manifesto (Android), telas de abertura do iOS para iPhone e iPad, e abertura breve dentro do app instalado (só CSS, respeita redução de movimento).
- Pasta `brand/` para as lojas: ícone 1024, ícone adaptativo do Android, imagem de destaque do Play, imagem de compartilhamento 1200×630 e BRAND.md. Tudo é gerado por `scripts/build-brand.py`.
