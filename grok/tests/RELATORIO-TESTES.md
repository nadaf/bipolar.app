# Relatório de testes — Bipolar · Diário de Humor

Data da execução: 14/10/2026 (atualizado após as correções do QA 1) · Resultado final: **tudo verde** (`tests/run-all.sh`).

## V4.5: páginas públicas e pedidos de contato (15/10/2026)

Ambiente desta rodada: **PHP 8.4.26** (é o único PHP instalado nesta máquina; PHP 8.3, o de produção, coberto pela análise estática do PHPStan com `phpVersion` 8.0–8.4), **MySQL 5.7.44**, Chrome headless.

| Etapa | Resultado |
|---|---|
| PHPUnit (suíte inteira) | ✅ 290 testes, 2727 asserções, 0 falhas |
| `Unit/LeadsUnitTest.php` | subdomínio (21 casos: tamanho 3–30, `a-z0-9-`, maiúsculas, acento, `_`, `.`, espaço, hífen no início/fim, `--`, reservados), honeypot, carimbo de tempo (mínimo, expiração, outra sessão, adulterado, tipos inválidos), validação completa dos 2 formulários (inclusive injeção de cabeçalho no e-mail), preço 49,90 nos 2 planos, hash do IP, CSV com proteção contra fórmulas e aspas, limpeza de 12 meses que preserva `fechado` |
| PHPStan nível 6 | ✅ 0 erros |
| `php -l` | ✅ |
| Varredura de padrões inseguros | ✅ 0 ocorrências |
| ESLint | ✅ 0 problemas |
| `js/regress-v45.mjs` (axe-core WCAG 2.2 AA com a **CSP real**, sem bypass) | ✅ 24 auditorias, 0 violações: `/profissionais.php`, `/comecar.php`, `/`, `/privacidade.php`, formulário com erros e indicação enviada × claro/escuro × 1280/390 px; 0 violações de CSP no console |
| Lighthouse (12 auditorias: 6 páginas × celular/desktop) | ✅ Acessibilidade, Boas práticas e SEO **100** em todas, inclusive `/profissionais.php` e `/comecar.php`; PWA instalável e offline |

Capturas de tela: `/workspace/qa/v45-local/` (1280 e 390 px, claro e escuro, mais erro e sucesso).

## Ambientes realmente testados

| Componente | Versões |
|---|---|
| PHP | **8.1.34, 8.2.34, 8.3.35, 8.4.26** (pacotes Sury no Debian 13). A suíte inteira roda em cada versão; o servidor `php -S` usa a mesma versão do PHPUnit. |
| Banco | **MySQL 5.7.44** (binários oficiais genéricos da Oracle, `sql_mode` padrão do 5.7 com `ONLY_FULL_GROUP_BY` e `STRICT_TRANS_TABLES`). Schema também importado com `ROW_FORMAT=COMPACT`. Execução extra em MariaDB 11.8 (também verde). |
| Navegador | Google Chrome headless (axe-core 4.10, Lighthouse 12) |
| PHP 8.0 | não disponível no ambiente; verificado por análise estática (PHPStan com `phpVersion` 8.0–8.4). |

## Resumo

| Etapa | Resultado |
|---|---|
| PHPUnit — 133 testes, 1057 asserções × 4 versões de PHP (8.1–8.4) | ✅ 532/532 aprovados, 0 falhas |
| `php -l` em todos os arquivos | ✅ |
| PHPStan nível 6 (PHP 8.0–8.4) | ✅ 0 erros |
| Varredura de padrões inseguros (`tools/security_scan.sh`) | ✅ 0 ocorrências |
| ESLint (recommended + regras de segurança) | ✅ 0 problemas |
| axe-core WCAG 2.0/2.1/2.2 A+AA + best practices — 18 telas/estados (inclui login, cadastro com erros, esqueci, reenviar, verificar, redefinir) × claro/escuro × desktop/celular (72 auditorias) + tema escolhido no botão contra o tema do sistema (4 combinações × 2 telas: Registrar, edição com data inválida, privacidade = 24 auditorias) + **V2**: “Meu dia” com todos os módulos abertos + Sexualidade visível + alerta precoce, e Sexualidade bloqueada por PIN, × claro/escuro × desktop/celular (8 auditorias) + faixa de crise CVV/SAMU com contraste **AAA** (8 verificações) + teste de teclado | ✅ 0 violações |
| Lighthouse (8 auditorias: 4 páginas × celular/desktop) | ✅ Acessibilidade 100 em todas; Boas práticas 96–100; SEO 100 |
| PWA: instalável, manifest sem erros, service worker, funciona offline | ✅ |

## O que os testes cobrem

**Unitários (`Unit/`)**
- Escala de humor: 7 níveis simétricos, rótulos, ícones distintos (cor não é o único indicador), **somente manhã/tarde/noite** (sem madrugada no schema, JS, relatórios e README), contraste WCAG AA de todos os chips (CSS e e-mail).
- Agendamento: diário/semanal/mensal/personalizado, fusos (Cuiabá, São Paulo, Lisboa), viradas de mês/ano.
- CSRF (token por sessão, comparação em tempo constante, tipos inválidos), `e()` com UTF-8 inválido, base64url, fusos.
- Validação de registros e lembretes (datas impossíveis, período inválido, humor fora da faixa/booleano/decimal, limites de texto/etiquetas, UUID).
- OAuth: vetor oficial do PKCE (RFC 7636), URL de autorização dos 3 provedores (state ≥ 32 caracteres, verifier ≥ 43, S256, redirect_uri exato), provedor desconhecido, state forjado, state em array, troca de provedor, expiração (10 min), uso único/replay, erro do provedor.
- VAPID: JWT ES256 verificado com a chave pública, `exp` ≤ 24 h, conversão DER→r||s com preenchimento.
- SMTP: cabeçalhos MIME, assunto UTF-8 codificado, somente CRLF, linhas ≤ 998, dot-stuffing, bloqueio de injeção de cabeçalho no destinatário e no assunto.
- Compatibilidade MySQL 5.7: ausência de CTE, window functions, JSON_TABLE, colunas JSON, colação 0900, CHECK, DEFAULT com expressão, `AS new`; VARCHAR indexados ≤ 191.

**V2: marcadores, Sexualidade e indicador (`Unit/MarkersUnitTest.php`, `Integration/V2MarkersTest.php`, `Unit/SchemaCompatTest.php`)**
- Sono por horário de deitar/acordar atravessando a meia-noite; validação de cada marcador (faixas, horários inválidos, doses e sinais); só os campos enviados são gravados (edição parcial sem perda).
- Sexualidade: escala −2..+2, códigos de atividade inválidos ignorados, “sem atividade” exclusiva, tempo online só com a atividade online.
- Preferências: módulos com padrão (Sexualidade **desligada**), limite de 10 sinais, medicações ordenadas por horário e com id preservado.
- Indicador: a tríade sono↓ + humor↑ + desejo↑ gera **alerta**; a linha de base pessoal (mediana de 28 dias) evita alarme falso para quem sempre dorme pouco; a depressão não dispara alerta de mania; o módulo desligado não entra na conta.
- API: isolamento entre usuários, 401 sem login, datas inválidas → 422; Sexualidade → 403 com o módulo desligado; **PIN** bloqueia leitura e escrita (423), o desbloqueio fica limitado a 5 tentativas em 15 min (429), a troca exige o PIN atual; exportação sem o hash do PIN; apagar tudo.
- Relatório por e-mail (cron real): marcadores para todos; resumo de sexualidade **só** para o destinatário com opt-in `true` (a string "1" não conta), nunca a anotação nem as atividades; PATCH desliga o opt-in.
- Lembretes e service worker não mencionam sexualidade. A UI mostra/oculta módulos conforme as preferências.
- Migração `2026-10-15-v2-marcadores.sql` aplicada **duas vezes** sobre o schema do servidor; colunas (tipo, nulo, padrão) idênticas ao `schema.sql`; destinatários existentes ficam com sexualidade = 0. Agora são 11 tabelas.

**V3: Substâncias (`Unit/SubstancesUnitTest.php`, `Integration/V3SubstancesTest.php`)**
- Validação: lista fechada de substâncias; formas por substância; “Outra” exige o nome; período, motivo e efeito (−2..+2) validados; até 6 favoritos sem repetição.
- O módulo vem desligado (403); vários registros por dia; isolamento entre usuários (remover registro de outra pessoa → 404); exportação; “apagar tudo” exige confirmação.
- Álcool consistente: a soma dos registros de Álcool vira `alcohol_units` do dia, recalculada ao remover; o campo do “Meu dia” fica somente leitura.
- O PIN é compartilhado com Sexualidade (423 até desbloquear); salvar os módulos em Conta mantém os favoritos.
- Indicador: estimulante/psicodélico +2; acima do habitual +1; cannabis acima do habitual com sono reduzido +1; uso habitual 0.
- Resumo de humor com uso, sem uso e no dia seguinte. O relatório por e-mail só inclui substâncias com opt-in, e só contagens (sem quantidade, motivo ou período). Lembretes e service worker não mencionam substâncias.
- Migrações V2 → V3 rodadas duas vezes: o texto livre vira “Outra” uma única vez e o campo antigo é limpo; o resultado bate com o `schema.sql` (12 tabelas).

**Login por e-mail e senha (`Integration/EmailAuthTest.php`, `Unit/PasswordAuthTest.php`)**
- Cadastro → e-mail de confirmação → login bloqueado até confirmar → GET não confirma (só POST) → confirmação entra na conta → link de uso único; e-mail normalizado; hash Argon2id/bcrypt; token guardado só como SHA-256.
- Senha errada, e-mail inexistente e e-mail inválido produzem **a mesma página**; cadastro com e-mail já existente responde igual a um novo e avisa o dono por e-mail; “esqueci” e “reenviar” idênticos para contas existentes/inexistentes.
- Redefinição: expiração, senhas diferentes/fracas, sucesso, uso único, invalidação de outros links pendentes, **encerramento das outras sessões**, senha antiga deixa de valer.
- Limites: login por e-mail (10/15 min, bloqueia até com senha certa) e por IP (30/15 min); cadastro 5/h por IP; reenviar 3/h por e-mail.
- CSRF em todos os formulários (cadastro, entrar, esqueci, reenviar, redefinir, verificar) e na troca de senha.
- Troca de senha em Conta: senha atual obrigatória, política de senha, outros aparelhos saem, novo token CSRF; exportação LGPD não contém o hash.
- Conta OAuth com mesmo e-mail **não** é unificada; “esqueci” não transforma conta OAuth em conta com senha; tela Conta sem formulário de senha para contas OAuth.
- Botões OAuth ocultos com credenciais vazias ou placeholders `SEU_...` (servidor “produção” só com Google); `start.php` recusa provedores não configurados; páginas de conta carregam sem OAuth.
- Política de senha (mínimo 8, lista de comuns, variedade, diferente do e-mail, acentos ok), rehash automático, `require_email_verification = false`.
- Migração `migrations/2026-10-14-email-login.sql` aplicada **duas vezes** sobre o schema anterior no MySQL 5.7.44, preservando dados.

**Integração HTTP contra `php -S` (`Integration/`)** — dois servidores: "dev" e "produção" (`dev_login` desligado), SMTP falso e storage temporário.
- Cabeçalhos: CSP (`frame-ancestors 'none'`, sem `unsafe-eval`/`unsafe-inline` em scripts), `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS sob HTTPS, `Cache-Control: no-store` na API.
- Cookie de sessão: `HttpOnly`, `SameSite=Lax`, `Secure` atrás de HTTPS; ID regenerado no login (anti-fixação); ID forjado rejeitado (strict mode).
- CSRF em **todos** os 11 endpoints de escrita (sem token, token errado, token de outra sessão) e no logout.
- 401 em todos os endpoints sem login.
- IDOR: usuário B não lê/edita/exclui registros, mídias, destinatários e lembretes de A; não anexa mídia à entrada de A; relatório e exportação mostram só os próprios dados.
- SQL injection em datas, período, IDs, fuso, token de confirmação — rejeitados ou tratados como texto; tabelas intactas.
- XSS em anotação, etiquetas, nome do usuário e nome do destinatário: escapado no app, no JSON embutido, na página imprimível, no **e-mail de convite** e no **e-mail de relatório** gerado pelo cron.
- Path traversal na mídia (inclusive com `stored_name` adulterado no banco).
- Upload: PHP disfarçado de PNG/WebM, SVG, HTML como JPEG, GIF, executável como MP4 → 415; PNG real com nome `.php` salvo como `.png` com nome aleatório; limite de tamanho → 413; requisições Range (206/416).
- Open redirect: start/callback/logout/dev só redirecionam para o provedor ou para `/`.
- Rate limiting: login OAuth (20/5 min) e convites (10/h) → 429.
- Login de desenvolvimento retorna 404 com configuração de produção.
- Opt-in do destinatário: token forjado, GET não confirma (proteção contra scanners de link), expiração em 14 dias, cron só envia para confirmados, cancelamento.
- Exclusão de conta remove dados de todas as tabelas e a pasta de mídia, encerra a sessão e preserva outros usuários.
- Lembretes por e-mail: enviados uma vez na janela, sem repetir no mesmo dia.
- Aviso gentil após 3 registros extremos seguidos (e desativável).

**Correções do QA 1 (`Integration/QaFixesTest.php`, `Unit/QaUnitTest.php`)**
- Edição: reenviar um registro (mesma data + período) **sem** etiquetas/anotação/sono/escalas mantém os valores salvos; mandar o campo vazio apaga de propósito; o id é o mesmo.
- Tela Registrar: aviso de edição, “Cancelar edição”, data dd/mm/aaaa, esqueleto em “Hoje”.
- Sem VAPID (servidor “produção”): sem opção push/ambos e sem botão de ativar; a API recusa push/ambos (422) e usa e-mail como padrão. Com VAPID (dev), push continua disponível.
- Convite para endereço recusado (fake SMTP responde 550): mensagem amigável, sem “SMTP”; no log `SMTP [convite] DESTINATÁRIO RECUSADO te***@...` com o código 550 e o e-mail mascarado.
- SMTP com **SSL implícito (465)** contra servidor TLS local: mensagem de ~400 KB chega íntegra (escrita parcial corrigida); RCPT recusado → `recipient_rejected`, etapa `rcpt`, código 550; porta/modo trocados → falha com dica.
- CSV Excel-BR (BOM, `;`, `6,5`, `02/10/2026`, texto com `;` entre aspas) e relatório com vírgula decimal (`6,8 h`, `−0,50`) e espaçamento entre seções.
- Fusos: os 16 do Brasil primeiro, na ordem pedida, depois “Outros países”, sem duplicar.
- Privacidade: lista só os logins ativos (produção: “e-mail e senha ou conta Google”; dev: Google, Microsoft ou X).
- `last_login_at` gravado no login dev/OAuth e no login por e-mail (todas as entradas passam por `login_user()`).
- Teste de carga: bypass de limite só com config de 32+ caracteres **e** cabeçalho igual; token errado ou config vazio continuam limitados; `/loadtest-admin.php` dá 404 sem segredo; seed idempotente e limpeza que só apaga `loadtest+NNN@domínio` (contas-isca preservadas).

## Problemas encontrados e corrigidos

1. **Contraste insuficiente** (WCAG 1.4.3): nível −2 (4,43:1) e +3 (3,96:1) com texto branco → cores escurecidas (`#4f69b8`, `#c0392b`).
2. **Conflito de nome `http_request()`** com a extensão PECL `pecl_http` (erro fatal "cannot redeclare" em hosts que a tenham) → renomeada para `bp_http_request()` (detectado pelo PHPStan).
3. **Convites sem validade** → token de opt-in expira em 14 dias.
4. **Erros detalhados podiam vazar** → `display_errors` desligado fora do modo dev e handler global que devolve mensagem genérica (JSON na API).
5. **Ordenação com aspas duplas em SQL** (`FIELD(period,"...")`) quebraria com `ANSI_QUOTES` → substituída por ordenação nativa do ENUM.
6. **Envio de e-mail sem validação do destinatário** → `send_mail()` rejeita endereços inválidos; montagem MIME isolada e com dot-stuffing.
7. **Acessibilidade**: abas sem `<h1>` → título h1 visualmente oculto; regiões roláveis (gráfico e tabela) sem acesso por teclado → `tabindex="0"` e rótulos; botão "Excluir" com contraste baixo no tema escuro; relatório imprimível ilegível no tema escuro.
8. **`fopen`/`parse_url` sem checagem de falha** (PHPStan nível 8) na entrega de mídia e no push.
9. **ESLint**: variáveis não usadas e blocos `catch` vazios.
10. **SEO**: faltava `robots.txt` (agora bloqueia `/api/`, `/auth/`, relatórios e confirmações).

11. **(login por e-mail)** PHPStan: tipos de retorno em `password_auth.php`; testes antigos ajustados (9 tabelas; README pode citar “madrugada” na seção de migração).

12. **(QA 1)** Ao montar o calendário em colunas, os textos `.sr-only` (posição absoluta) aumentavam a largura da página no celular. Corrigido com `position: relative` na área rolável; a coluna de dias da semana fica fixa à esquerda.
13. **(QA 1)** No modo push desativado, o teste unitário antigo esperava `push` como canal padrão; o padrão agora é `email`.
14. **(V2)** PHPStan: as views novas viraram funções com parâmetro tipado (`view_day_panels(array $user)`), sem variável global implícita.

## Limitações / não testado

- Login real com Google, Microsoft e X e entrega real de push (FCM/Mozilla/Apple) exigem credenciais e domínio público — testados apenas state/PKCE/URLs, assinatura VAPID e o fluxo até a chamada externa.
- Apache/`.htaccess` e `.user.ini` não foram exercitados (testes usam `php -S`); os bloqueios do `.htaccess` são uma segunda camada — o código já fica fora do `public_html`.
- SMTP real não testado: SSL implícito (465) foi testado contra servidor TLS local com certificado autoassinado; STARTTLS (587) só por análise.
- Teste de carga: kit validado só localmente (`php -S`, até 6 usuários); **não** foi executado contra bipolar.app.br.
- Gravação real de áudio/vídeo (MediaRecorder) depende de câmera/microfone físicos.
- PHP 8.0 apenas por análise estática.
- Lighthouse 12 não tem mais a categoria "PWA"; a instalabilidade foi verificada pelo próprio Chrome (`Page.getInstallabilityErrors`).

## Como executar

```bash
# 1) MySQL 5.7 de teste (exemplo com binários genéricos)
mysqld --initialize-insecure --datadir=./data && mysqld --datadir=./data --port=3307 &
mysql -uroot -h127.0.0.1 -P3307 -e "CREATE DATABASE bipolar_test CHARACTER SET utf8mb4; CREATE USER 'bp'@'%' IDENTIFIED BY 'bp'; GRANT ALL ON bipolar_test.* TO 'bp'@'%';"
# 2) dependências (somente na máquina de testes)
cd tests && composer install && (cd js && npm install)
# 3) tudo
./run-all.sh            # variáveis opcionais: TEST_DB_HOST, TEST_DB_PORT, TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASS, CHROME
```

## V4.4 — verificação em duas etapas e correções do QA (15/10/2026)

Execução de `tests/run-all.sh` em PHP 8.4.26 + MySQL 5.7.44: **tudo verde**.

| Etapa | Resultado |
|---|---|
| PHPUnit — 250 testes, 2546 asserções (inclui `Unit/MfaUnitTest.php` com vetores RFC 6238/4226 e QR lido pelo jsQR, e `Integration/V44Test.php`) | ✅ |
| `php -l`, PHPStan nível 6, ESLint | ✅ 0 erros |
| Varredura de padrões inseguros | ✅ (linhas revisadas manualmente listadas em `tests/tools/scan-allowlist.txt`) |
| Regressão V4.4 (`js/regress-v44.mjs`): ativação com QR, códigos de recuperação, login em 2 etapas em 1280px e 390px, CSP sem violações, axe claro/escuro | ✅ 23 verificações |
| Regressões V4.1, axe-core, Lighthouse + PWA | ✅ 0 violações, acessibilidade 100 |

Cobertos também: exclusão/renomeação de item de outra conta responde 404 em destinatários, registros, mídia, lembretes, push e passkeys; alerta personalizado confere o dono antes da checagem de duplicado.
