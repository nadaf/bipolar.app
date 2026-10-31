# Histórico de versões (versão grok)

## 0.5.3-beta — V5.3.1 (Blog do Bipolar e páginas informativas)

- Rodapé: "Criar conta" e "Blog" (blog.bipolar.app.br); imagem de compartilhamento (og-bipolar.png) regenerada com a frase "Você no controle dos seus dados".
- /profissionais.php e /comecar.php passam a ser só informativas, iguais no site e no código aberto.

## 0.5.3-beta — V5.2.1 (correção do aviso de estatísticas)
- **Corrigido: "o aviso não some depois de Aceitar".** O aviso usava a classe `.consent`, que já existia no checkbox LGPD da página de cadastro. O CSS novo (`position: fixed`) transformava esse checkbox numa caixa flutuante no rodapé da tela, igual ao aviso, que continuava lá depois do aceite. Agora o aviso é estilizado pelo ID `#consent-banner` e pela classe própria `.ga-consent`; o checkbox voltou a ficar dentro do formulário.
- O aviso é escondido pelos dois caminhos (atributo `hidden` + classe `is-hidden` com `display: none !important`), antes de qualquer outra ação e de novo num `finally` — mesmo se o Google falhar ou for bloqueado.
- Sem `localStorage` (cookies bloqueados, modo privado, WebView): a escolha também fica no cookie primário `bp_consent` (só `granted`/`denied`, 1 ano, SameSite=Lax, Secure), e o aviso não volta a cada página. Recusar continua apagando só os cookies `_ga`.
- Funciona mesmo com HTML e JS de versões diferentes (HTML antigo `class="consent"` + JS/CSS novos).
- Celular, dentro do app: o aviso fica acima da navegação inferior (as abas continuam clicáveis). Enquanto aberto, a página ganha espaço embaixo para nada ficar escondido atrás dele. Em telas largas fica no canto inferior direito, fora do formulário de login.
- Política de privacidade cita o cookie `bp_consent`.
- Service worker bp-v18, app.css v16, analytics.js v2 (app.js segue v15). Sem migração de banco.

## 0.5.2-beta — V5.2 (estatísticas de uso com privacidade)
- **Google Analytics 4 opcional e com consentimento (LGPD)**. Desligado por padrão no app público. Liga por `'analytics' => ['ga4_id' => 'G-…']` no config.php ou, só no site principal, pelo arquivo `app/site-main.php` (fora do repositório público), que vale apenas quando o endereço é `bipolar.app.br`/`www.bipolar.app.br`. Subdomínios de clínicas e quem hospeda por conta própria nunca herdam o ID. `'ga4_id' => 'off'` desliga.
- **Aviso Aceitar/Recusar** acessível (não bloqueia a tela, não rouba o foco, botões de 44 px), escolha lembrada no aparelho, reaberto pelo rodapé ("Cookies e estatísticas de uso") ou em Conta › Estatísticas de uso. Consent Mode v2 com tudo negado; o gtag.js só é baixado depois de "Aceitar". Recusar depois apaga os cookies `_ga` e para o envio.
- **CSP**: só quando o GA está ativo na página, `script-src` ganha `https://www.googletagmanager.com` e `connect-src`/`img-src` os domínios `*.google-analytics.com`/`*.analytics.google.com`. Sem script inline (novo `assets/js/analytics.js`).
- **Sem dados de saúde**: `allow_google_signals` e `allow_ad_personalization_signals` desligados, `page_referrer` vazio, telas como page_view virtual (`/app/registrar`, `/app/historico`, `/app/relatorios`, `/app/lembretes`, `/app/conta`) sem query string, números ou datas, títulos genéricos. Eventos com nomes fixos e só o parâmetro `bloco` (lista fechada) ou `formato`. Pensamentos, sexualidade, substâncias e religiosidade aparecem só como `bloco=privado`; o módulo de pensamentos de morte não gera evento. Funil: `sign_up`, `login`, `first_record` (uma vez).
- GA nunca carrega em `paciente.php`, `relatorio.php`, convites, confirmações, redefinição de senha, MFA ou qualquer URL com parâmetro além de `?aba=`.
- Política de privacidade: seção "Estatísticas de uso" (só aparece onde o GA está ligado).
- Página inicial e rodapé apontam para o Blog do Bipolar (blog.bipolar.app.br); imagem de compartilhamento atualizada.
- Service worker bp-v17, app.css v15, app.js v15. Sem migração de banco.

## 0.5.1-beta — V5.1 (segurança + usabilidade)
### Segurança e privacidade
- **Relatório e visão do profissional** checam o bloqueio do app antes de qualquer consulta: bloqueado = só a tela de desbloqueio. Parâmetros na URL não liberam seções privadas. `paciente.php` revalida o compartilhamento a cada acesso (revogado = 404).
- **Troca de e-mail em duas etapas**: pede senha/PIN (ou login recente), guarda o novo endereço como pendente e manda um link de uso único (token com hash). A confirmação atualiza o e-mail, a verificação e a identidade "email" numa transação e encerra as outras sessões. Convites de profissional só usam e-mail comprovado.
- **Fila offline (bp-queue)** presa à sessão (dono), não envia dados de outra conta, é limpa ao sair. Cada item tem `client_uuid` e o servidor ignora repetições (`entry_client_ids`). Estados pendente/enviado/falhou. Seções íntimas, áudio e vídeo nunca ficam no aparelho: o formulário continua na tela com o aviso "não enviado".
- **Service worker bp-v16**: não guarda páginas logadas (HTML com `Cache-Control: no-store, private`). A tela offline com CVV 188 e SAMU 192 continua. Sair apaga cache e fila antes de navegar.
- **Fila de envio dos lembretes/relatórios (outbox)**: estados, reserva com token, até 5 tentativas com espera crescente (5 min·2^(n-1), no máximo 6 h, + 0–60 s), próximo envio em UTC, heartbeat no início e no fim do cron.
- **Exportação** só por POST com CSRF, confirmação de identidade e token de uso único, com aviso em português. Exclusão de conta confere cada arquivo apagado e reconcilia órfãos. `media.php` só serve tipos confirmados pelos bytes iniciais (415 nos demais).
- **Pensamentos** saem do compartilhamento padrão e viram categoria sensível (opt-in com lista de campos). Compartilhamentos existentes não são ampliados.
- Migração v4.1 de duplicados mantém a linha mais completa e para em conflito; `subject_hash` nas identidades; `.htaccess` sem confiar em X-Forwarded-Proto (opcional: `trusted_proxies`); `entries.php` usa o fuso da conta e responde 422 para intervalo invertido; `run-all.sh` com pré-checagem e `SKIP_BROWSER=1`.
### Usabilidade
- **Período travado no registro**: data e período não mudam sozinhos pelo relógio. Indicador fixo no topo ("Registrando: Manhã de hoje (22/10)"). Se o relógio passar para outro período, um diálogo acessível pergunta se quer continuar ou trocar.
- **"Salvar e continuar"** em cada bloco (salva e leva ao próximo, mesma data/período) e **"Salvar tudo"** no fim, com resumo do que foi salvo e do que falhou.
- **Tema claro/escuro** corrigido: segue o sistema por padrão (e acompanha a mudança ao vivo), a escolha manual fica salva, `aria-pressed` correto, ícone ☀/☾. Causa: service worker antigo servia JS/HTML em cache e a cor da marca era aplicada também no tema escuro do sistema.
- **Lembretes em grade semanal** (Dom–Sáb × horários): toque/Enter liga e desliga o dia, setas navegam, alvos de 44 px, ✓/– além da cor, cabe na largura do Android. Novo `PATCH /api/reminders.php` (sem mudança de tabela).
- **Nova categoria "Religiosidade"** por período (Nenhuma / Normal / Sensível ao tema / Muito sensível ao tema), opcional. Aparece no Histórico (✦ no gráfico de humor para "sensível" ou mais), no relatório ao lado do humor, no CSV/JSON. No compartilhamento com profissional é uma categoria sensível própria, desligada por padrão. Não vai para a IA.
### Migração
- `migrations/2026-11-17-v5-1-seguranca.sql` (só acrescenta colunas/tabelas; segura com o app no ar).

## 0.5.0-beta — V4.8
- **Pensamentos por período** (Manhã/Tarde/Noite), escala Não/Leve/Moderado/Intenso: positivos, negativos, acelerados, grandiosidade, ruminação/culpa.
  Com o módulo ligado, "acelerados" do dia passa a ser o maior valor dos períodos (alertas e relatórios antigos continuam funcionando).
- **Pensamentos de morte ou suicídio**: módulo privado, desligado por padrão, protegido pelo mesmo PIN/biometria (Revelar/Ocultar).
  Ao marcar qualquer nível ≥ 1 abre na hora um painel de apoio acessível (CVV 188, SAMU 192, contato de confiança), que funciona sem internet.
  Ninguém é avisado automaticamente; só alertas personalizados criados pela pessoa (modelo "Pensamentos de morte", desligado). Categoria própria na IA (desligada),
  relatórios só com seleção explícita. Política de privacidade cita como categoria sensível.
- Modelo de alerta **"Sinais de hipomania"** (acelerados alto + grandiosidade + sono baixo), desligado por padrão.
- **Nicotina** em Hábitos, ao lado do café: cigarros (0–100, inteiro) e "outra forma" (vape/pod, palha, charuto/cachimbo, narguilé, adesivo/chiclete).
  Histórico, relatórios, CSV/JSON e alerta "cigarros acima de X por N dias".
- **Compartilhar com profissional de saúde** (controlado pelo paciente): convite por e-mail (link de uso único, 14 dias, preso ao e-mail; copiar/WhatsApp,
  e-mail só se o SMTP estiver configurado) ou por código do profissional (24 h). Categorias escolhidas (sensíveis desligadas), prazo opcional,
  revogação imediata, registro de acessos visível ao paciente. Profissional autodeclarado (CRP/CRM informado, não verificado) vê só leitura em "Meus pacientes".
- Migração `migrations/2026-11-10-v4-8-pensamentos-nicotina-profissionais.sql`. Service worker `bp-v15`. Testes `V48Test`, `V48CareTest`, `tests/js/regress-v48.mjs`.

## 0.4.2-beta — V4.7.2
- **Correção:** o bloco "Meu dia" podia mostrar (e salvar) os dados de outra data depois de trocar a data — por exemplo, registrar hoje e escolher ontem.
  Agora uma resposta atrasada de outra data é descartada, toda troca de data feita pelo app ("Editar", "Novo registro", após salvar, IA) recarrega o bloco,
  e sem conexão o bloco fica vazio com aviso e não permite salvar por cima de um dia que não foi carregado.
- Testes: `tests/Integration/V472Test.php` e `tests/js/regress-v472.mjs`. Service worker `bp-v14`.

## 0.4.1-beta — V4.7.1
- Valores fora da escala (estresse, alimentação, sono, sinais do dia…) são recusados com erro 422 e mensagem em português.
- Limpar o estresse/alimentação de um período apaga a linha vazia; dias sem nenhum dado deixam de existir no banco.
- Migração `migrations/2026-11-04-v4-7-1-limpeza-vazios.sql` (remove linhas vazias antigas).

## 0.4.0-beta — V4.7
- Aviso "salvo" some sozinho (~4 s) e não cobre a barra de abas.
- **Estresse por período** (Manhã/Tarde/Noite) e novo módulo **Alimentação** por período (doces, ultraprocessados, comer fora de hora/compulsão),
  presente no histórico, relatórios, exportação, alertas personalizados (modelo "Doces e ansiedade") e, com autorização, na IA.
- Botões "Ocultar/Revelar" com rótulo e `aria-label` corretos; termos citam a verificação em duas etapas.
- Ponto de extensão genérico `app/lib/extensions.php`, desligado sem `app/ext/` (ver `docs/EXTENSOES.md`). O app público funciona completo sem ele.

## 0.3.x — V4.5 e V4.6
- Páginas informativas `/profissionais.php` e `/comecar.php`, imagens Open Graph, `sitemap.xml` e ajustes de SEO.
- Módulos exclusivos do site oficial **não fazem parte deste repositório**.
