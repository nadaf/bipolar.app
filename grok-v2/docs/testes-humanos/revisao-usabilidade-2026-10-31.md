# Revisão de usabilidade — 31/10/2026

Revisor: subagente `usability-reviewer` (`.claude/agents/usability-reviewer.md`). Servidor de teste local
(`php artisan serve` em `bipolar.test:8123`, banco `bipolar_test`, `php tests/e2e/seed.php`). Nenhum código foi alterado.
Captura: `tests/e2e/a11y.mjs` (412 px e 1280 px, claro/escuro) e uma cópia temporária fora do repositório com viewport 375 px.
Prints ficaram fora do repositório (`/tmp/e2erun/screens/` e `/tmp/e2erun/screens375/`).

## 1. Resumo

O núcleo do diário está maduro: axe não acusou nenhuma violação (nem séria nem menor) em 14 telas × 2 larguras × 2 temas, sem erro de JS/CSP,
e o fluxo "Salvar e continuar"/"Salvar tudo" funcionou nas duas larguras. A linguagem é acolhedora e o caminho de ajuda (CVV 188 / SAMU 192)
está presente em todas as telas. O que atacar primeiro: a grade de lembretes em `/alertas` estoura a largura em 375 px (único achado bloqueante),
a remoção de profissional sem confirmação e a falta de aviso de nova versão / orientação de instalação do PWA.

Contagem: **S4: 1 · S3: 3 · S2: 4 · S1: 1**.

## 2. Tabela de telas

| Tela | URL local | Usuário demo | O que dá para fazer | Status | Sev. máx. | Achados |
|------|-----------|--------------|---------------------|--------|-----------|---------|
| Entrar | `/entrar` | — | entrar | pronta | — | — |
| Criar conta | `/criar-conta` | — | criar conta de paciente | pronta | — | — |
| Registrar | `/registrar` | ana@demo.test | registrar período, salvar e continuar, salvar tudo, trocar período/dia | pronta | — | — |
| Histórico | `/historico` | ana@demo.test | calendário do mês, abrir dia passado | pronta | S1 | A9 |
| Dia | `/historico/{date}` | ana@demo.test | ver/completar dia passado | pronta com ressalvas | S2 | A5 |
| Relatórios | `/relatorios` | ana@demo.test | período, gráfico, tabela, sinais, imprimir | pronta | — | — |
| Alertas e lembretes | `/alertas` | ana@demo.test | limiares de sinal, grade de lembretes | não pronta | S4 | A1, A7 |
| Conta | `/conta` | ana@demo.test | perfil, PIN, e-mail, módulos, exportar, excluir | pronta | — | — |
| Compartilhar | `/compartilhar` | ana@demo.test | convidar com escopos, ver acessos | pronta com ressalvas | S1 | A9 |
| Acesso compartilhado | `/compartilhar/{id}` | ana@demo.test | editar escopos, revogar | pronta | — | — |
| Pacientes | `/cuidado` | pro@demo.test (consultorio-demo) | lista de pacientes vinculados | pronta | — | — |
| Relatório do paciente | `/cuidado/{patient}` | pro@demo.test | relatório somente leitura, imprimir | pronta com ressalvas | S2 | A5, A6 |
| Clínica | `/clinica` | admin@demo.test (consultorio-demo) | vincular/remover profissional | pronta com ressalvas | S3 | A2, A8 |
| Aceitar convite | `/convite/{token}` | (sem usuário de familiar no seed) | aceitar convite | revisada sem imagem | S2 | A6 |

## 3. Achados por severidade

### S4

- **[S4] A1 — Grade de lembretes estoura a largura em 375 px** — Alertas e lembretes
  - Onde: `resources/views/settings/alerts.blade.php:46` (tabela `grid-rem`), `resources/css/app.css:120-122`; print `screens375/alertas-375.png` (página com 398 px de largura, coluna de sábado cortada).
  - Persona: Paciente no celular pequeno precisa rolar para o lado e pode não ver o último dia.
  - Critério: "Sem rolagem horizontal em 375 px" (checklist de tela pronta); WCAG 1.4.10 Reflow.
  - Correção: envolver a tabela num contêiner com `overflow-x-auto` e rótulo, ou reduzir `.rem-cell` para `size-10` só em telas < 400 px mantendo 44 px de área de toque via `p-0.5` do `td`; alternativa: um dia por linha (cartões) no celular.

### S3

- **[S3] A2 — Remover profissional sem confirmação** — Clínica
  - Onde: `resources/views/clinic/index.blade.php:12`.
  - Persona: Gestor da clínica pode tirar uma profissional da clínica com um toque acidental.
  - Critério: "confirmação antes de excluir" (checklist); Nielsen — prevenção de erro.
  - Correção: diálogo de confirmação no mesmo padrão do diálogo de período do `/registrar` (sem `confirm()` nativo), botão `btn-danger` dentro do diálogo.

- **[S3] A3 — Sem aviso de nova versão do PWA** — layout/global
  - Onde: `resources/js/app.js:236-237` (só `register('/sw.js')`, sem tratar `waiting`/`controllerchange`).
  - Persona: Paciente fica com versão antiga em cache sem saber; correções não chegam.
  - Critério: checklist Offline/PWA; Nielsen — visibilidade do status.
  - Correção: ouvir `updatefound`/`waiting` e mostrar faixa `info` com botão `btn-secondary` "Atualizar agora".

- **[S3] A4 — Instalação do PWA não é explicada** — global / Visitante
  - Onde: nenhum `beforeinstallprompt` nem instrução de instalação em `resources/views` ou `resources/js/app.js`; não há site público neste repositório.
  - Persona: Visitante/Paciente não sabe que pode instalar (sobretudo no iOS, onde não há prompt).
  - Critério: tarefa crítica 8; checklist Offline/PWA.
  - Correção: card `info` em `/conta` (e no site público, quando existir) com passos Android/iOS e botão de instalar quando `beforeinstallprompt` estiver disponível.

### S2

- **[S2] A5 — Nome do paciente e data no `<title>`** — Relatório do paciente, Dia
  - Onde: `resources/views/reports/index.blade.php:2` ("Relatório de {nome}"), `resources/views/history/day.blade.php:2`.
  - Persona: Profissional/Paciente — o título aparece no alternador de abas/apps e no histórico do navegador, associando o nome a um relatório de saúde.
  - Critério: checklist Privacidade (nada sensível em título).
  - Correção: título genérico "Relatório — Bipolar"; manter o nome só no `h1`.

- **[S2] A6 — Familiar não recebe orientação sobre o que fazer diante de um sinal** — Relatório do paciente
  - Onde: `resources/views/reports/index.blade.php:67` (bloco "Sinais" só conta registros); print `cr-top` de `cuidado-relatorio-375.png`.
  - Persona: Familiar vê "Depressivo 15 · Mania/hipomania 14" sem saber como ajudar.
  - Critério: tarefa crítica 5; checklist Saúde mental (caminho de ajuda, sem alarmismo).
  - Correção: para `grantee_kind = familiar`, texto `hint` curto "Como apoiar" (conversar sem julgamento, combinar com a pessoa, CVV 188 / SAMU 192 em emergência) abaixo dos sinais.

- **[S2] A7 — Opções de lembrete que ainda não funcionam** — Alertas e lembretes
  - Onde: `resources/views/settings/alerts.blade.php:77` ("Beta: … o envio dos lembretes ainda está sendo ligado").
  - Persona: Paciente configura lembrete e espera recebê-lo; se não chega, perde confiança.
  - Critério: Nielsen — correspondência com o mundo real / visibilidade do status.
  - Correção: deixar o aviso acima da grade, em `warn`, ou ocultar "Como avisar" até o envio existir.

- **[S2] A8 — Gestor não consegue vincular paciente (por desenho) e a tela não diz isso no fluxo** — Clínica
  - Onde: `resources/views/clinic/index.blade.php:28` (só "Vincular profissional"; "Convite por e-mail: em breve").
  - Persona: Gestor procura "vincular paciente" e não acha.
  - Critério: tarefa crítica 7.
  - Correção: frase `hint` explicando que o paciente é quem convida pelo próprio app (`/compartilhar`), com o passo a passo para repassar ao paciente.

### S1

- **[S1] A9 — Campos de data mostram `mm/dd/yyyy`** — Compartilhar, Relatórios
  - Onde: prints `compartilhar-375.png`, `cuidado-relatorio-375.png`.
  - O formato vem do idioma do Chrome headless (en-US), não do app; num navegador pt-BR aparece `dd/mm/aaaa`. Registrar só para conferir num aparelho real.

## 4. Tarefas críticas

1. **Paciente registra o período atual em 375 px** — concluível. `/registrar` abre no período atual; 1 toque na escala + "Salvar e continuar" (2 toques). Fluxo automatizado: "Salvo: Humor: Elevado leve." Barra `save-bar` alcançável pelo polegar.
2. **Paciente completa um dia passado** — concluível. `/historico` → dia no calendário → completar (3 toques). Sem limite de data.
3. **Ajusta limites de alerta** — concluível (4 selects + "Salvar alertas"); trava na grade de lembretes em 375 px (A1).
4. **Convida familiar e define o que vê** — concluível. `/compartilhar` → "Quem é" → escopos (privados desmarcados por padrão) → "Criar convite" → link para enviar. Escopos claros.
5. **Familiar vê alerta e entende o que fazer** — parcial: vê os sinais, falta orientação (A6). Revisado pelo código (seed não tem familiar com login).
6. **Profissional abre paciente e gera relatório do mês** — concluível. `/cuidado` → Ana Souza → "30 dias" → Imprimir (3 toques).
7. **Gestor adiciona profissional e vincula paciente** — adicionar: concluível (e-mail + "Vincular"). Vincular paciente: não existe por desenho (A8).
8. **Visitante instala o PWA** — não concluível com orientação do app (A4); site público não está neste repositório.
9. **Registro offline sincroniza** — implementado pelo código: fila em `localStorage` e reenvio em `online` (`resources/js/app.js:134,171-173`), com mensagem "guardado no aparelho". Não testado com rede desligada.
10. **Master verifica saúde de um tenant** — telas de Master não existem neste repositório público; pulada.

## 5. Pontos fortes

- axe: 0 violações em todas as telas e larguras, nos dois temas; sem erro de JS nem de CSP.
- Linguagem acolhedora ("Você não está sozinho(a)."), CVV 188 / SAMU 192 com `tel:` em todas as telas e "o app nunca avisa ninguém automaticamente".
- Escala de humor/energia com símbolo + cor + texto (▼/▲, "Depressão moderada", "Hipomania"), zero no centro.
- Categorias sensíveis (sexualidade, pensamentos de morte, religiosidade) marcadas como "privado" e desmarcadas por padrão no compartilhamento.
- Relatório com "Ver dados do gráfico em tabela" e versão de impressão.
- Isolamento: a clínica vê só profissionais; o texto da `/clinica` deixa claro que o vínculo não dá acesso a pacientes.

## 6. Não verificado

- Registro realmente offline e aviso de atualização em produção (service worker só registra em build de produção).
- Fluxo do familiar com login (seed só tem convite pendente).
- Leitor de tela real (NVDA/TalkBack) e teclado manual; apenas axe e leitura de código.
- Acesso cruzado entre clínicas pela interface (coberto por `tests/Feature/Isolation/`, não reexecutado aqui).
- Site público e blog (fora deste repositório).
