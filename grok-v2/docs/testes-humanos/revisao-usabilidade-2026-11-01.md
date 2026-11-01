# Revisão de usabilidade — 2026-11-01

Revisor: `.claude/agents/usability-reviewer.md` (versão atualizada nesta data). Servidor de teste local (SQLite descartável + `tests/e2e/seed.php`), nunca produção. Captura: `tests/e2e/a11y.mjs` com `MOBILE=375`, 1280 px, tema claro (e escuro em Registrar/Relatórios), axe WCAG 2.2 A/AA + best-practice. Prints fora do repositório em `screens/revisao/2026-11-01/` (antes) e `screens/revisao/2026-11-01/depois/` (reteste).

## 1. Resumo

O app está maduro: 23 telas capturadas, nenhuma S4, axe com 0 violação serious/critical depois das correções, sem erro de JS/CSP e sem dado de saúde em título, URL ou notificação. Os problemas graves estavam nas bordas, fora das telas principais: páginas de erro padrão do Laravel em inglês (inclusive 419 "Page Expired"), o link de redefinição de senha vencido ou adulterado caindo num "403 Invalid signature." sem saída, e o contraste dos dias futuros no calendário do Histórico. Os três S3 e dois S2 baratos foram corrigidos e retestados. Próximo passo: o relatório do profissional em 375 px (lista longa de dias).

## 2. Tabela de telas

| Tela | URL local | Usuário demo | O que dá para fazer | Status | Sev. máx. | Achados |
|------|-----------|--------------|---------------------|--------|-----------|---------|
| Entrar | `/entrar` | visitante | entrar, ir para Esqueci/Criar conta | pronta | 1 | B1 |
| Criar conta | `/criar-conta` | visitante | criar conta (paciente ou profissional) | pronta | — | — |
| Esqueci minha senha | `/esqueci-senha` | visitante | pedir link (resposta neutra) | pronta | — | — |
| Redefinir senha | `/redefinir-senha/{token}` | ana@ (link gerado) | criar senha nova | pronta (corrigida) | 3 | A2, M2 |
| Página de erro | qualquer 4xx/5xx | — | voltar ao início | pronta (nova) | 3 | A1 |
| Registrar | `/registrar` | ana@ | registrar por período, Outro dia, Salvar e continuar/Salvar tudo | pronta | — | — |
| Histórico | `/historico` | ana@ | navegar mês, abrir dia | pronta (corrigida) | 3 | A3 |
| Dia do histórico | `/historico/{data}` | ana@ | ver e editar períodos de dia passado | pronta | — | — |
| Relatórios | `/relatorios` | ana@ | gráfico + tabela, exportar | pronta | — | — |
| Exportar | `/exportar` | ana@ | CSV/PDF por período | pronta | — | — |
| Alertas e lembretes | `/alertas` | ana@ | limites de sinal, lembretes, push | pronta | — | — |
| Conta | `/conta` | ana@ | perfil, PIN, e-mail, instalar PWA, excluir | pronta | — | — |
| Verificação em duas etapas | `/conta/verificacao` | ana@ | ativar 2FA (3 passos) | pronta com ressalvas | 1 | B2 |
| Compartilhar | `/compartilhar` | ana@ | convidar, ver acessos | pronta (corrigida) | 2 | M1 |
| Detalhe do acesso | `/compartilhar/{id}` | ana@ | mudar escopo, revogar | pronta (corrigida) | 2 | M1 |
| Pacientes | `consultorio-demo` `/cuidado` | pro@ | lista de pacientes | pronta | — | — |
| Relatório do paciente | `/cuidado/{id}` | pro@ | tendências, alertas, dias | pronta com ressalvas | 2 | M3 |
| Exportar do paciente | `/cuidado/{id}/exportar` | pro@ | CSV/PDF dos escopos liberados | pronta | — | — |
| Clínica | `consultorio-demo` `/clinica` | admin@ | convidar/remover profissional, exigir 2FA | pronta | — | — |

Familiar: só convite pendente no seed (sem login); revisado pelo código e por `tests/Feature/UsabilityReviewTest.php` (A6).

## 3. Achados por severidade

Contagem: **S4 0 · S3 3 · S2 3 · S1 2** (corrigidos: 3 S3 + 2 S2).

### S3

- **[S3] A1 — Páginas de erro padrão do Laravel, em inglês e sem saída** — todas as telas (404, 403, 419, 429, 500, 503)
  - Onde: não existia `resources/views/errors/`; `bootstrap/app.php:46` só renderiza tenancy como 404. Print: `screens/revisao/2026-11-01/redefinir-senha-invalido-375.png` (mesmo visual padrão).
  - Persona: paciente em crise que deixou o formulário aberto recebe "419 Page Expired" em inglês, sem botão e sem saber que o registro não foi salvo; suporte recebe chamado.
  - Critério: Nielsen 9 (mensagens de erro) e 2 (linguagem do usuário); WCAG 3.1.1 (`lang` errado: página padrão vem com `lang="en"`); DoD "textos em pt-BR".
  - Correção feita: `resources/views/errors/minimal.blade.php` (independente de auth/banco, classes `card`, `btn-primary`, `crisis-line`) + `403/404/419/429/500/503.blade.php`. 419 explica que o que não foi salvo precisa ser preenchido de novo. Teste em `tests/Feature/UsabilityReviewTest.php`. Print depois: `depois/erro-404-375.png`.
- **[S3] A2 — Link de redefinir senha vencido/adulterado dá "403 Invalid signature."** — `/redefinir-senha/{token}`
  - Onde: `routes/web.php:24` (middleware `signed`), `app/Http/Controllers/Web/PasswordResetController.php:31`.
  - Persona: quem abre o e-mail depois de 60 min (comum em quem está deprimido ou sem dormir) cai numa página em inglês sem saída; a tela pt-BR de "link expirou" em `auth/reset.blade.php:23` nunca aparecia nesse caso.
  - Critério: Nielsen 9; tarefa crítica 6 travava.
  - Correção feita: assinatura conferida no controller com `$request->hasValidSignature()`; inválido → 410 com a tela pt-BR, botão "Pedir link novo" e "Voltar para Entrar". Teste em `tests/Feature/PasswordResetTest.php`. Print depois: `depois/redefinir-senha-invalido-375.png`.
- **[S3] A3 — Contraste insuficiente nos dias futuros do calendário** — `/historico`
  - Onde: `resources/css/app.css:100` (`.cal-day.futuro { opacity-50 }`), usado em `resources/views/history/index.blade.php:27`. axe `color-contrast` serious em 375 e 1280.
  - Critério: WCAG 1.4.3.
  - Correção feita: fundo `bg-slate-50`/`dark:bg-slate-800` e texto `text-slate-600`/`dark:text-slate-400` em vez de opacidade. axe no reteste: 0.

### S2

- **[S2] M1 — ID interno visível: "Convite #2"** — `/compartilhar`, `/compartilhar/{id}`
  - Onde: `resources/views/share/index.blade.php:13`, `resources/views/share/show.blade.php:6`.
  - Critério: Nielsen 2 (nada de IDs visíveis). Correção feita: "Convite de dd/mm/aaaa".
- **[S2] M2 — Tela de senha nova sem caminho de volta** — `/redefinir-senha/{token}`
  - Onde: `resources/views/auth/reset.blade.php:20`. Critério: Nielsen 3. Correção feita: link "Voltar para Entrar" nos dois estados.
- **[S2] M3 — Relatório do profissional muito longo em 375 px** — `/cuidado/{id}`
  - Onde: lista de dias em `resources/views/reports/index.blade.php` (print `cuidado-relatorio-375.png`: mais de 25 cartões de dia depois do resumo).
  - Persona: profissional no celular antes da consulta rola muito até o fim. Sugestão (não feita): recolher os dias em `<details>` por semana ou mostrar só dias com alerta por padrão, reaproveitando `card`.

### S1

- **[S1] B1 — Logo duplicado em Entrar/Criar conta no celular** — `partials/brand` no topo + ícone grande acima do `h1` empurram o formulário ~90 px (`entrar-375.png`). Sugestão: esconder o ícone grande abaixo de `sm`.
- **[S1] B2 — Promessa de recurso futuro em `/conta/verificacao`** — "Chave de acesso (passkey): planejada para uma próxima versão." (`conta-verificacao-375.png`). Sugestão: remover até existir.

## 4. Tarefas críticas

| # | Tarefa | Concluível? | Passos/toques | Onde trava |
|---|--------|-------------|---------------|------------|
| 1 | Registrar humor/energia/sono do período (375 px) | sim | abre `/registrar`, 1 toque no bloco, 1 na escala, "Salvar e continuar" (≈3 por bloco) | — (fluxo automatizado OK: "Salvo: Humor: Elevado leve.") |
| 2 | Completar dia passado | sim | Histórico → dia → "Editar manhã" (3) ou Registrar → "Outro dia" | — |
| 3 | Ver alerta e ajustar limites | sim | Conta/menu → Alertas → selects → "Salvar alertas" (4) | — |
| 4 | Exportar CSV/PDF | sim | Relatórios → Exportar → formato → "Baixar arquivo" (3–4) | — |
| 5 | Ativar 2FA | sim | Conta → Verificação → senha → QR → código (3 passos numerados) | não testado com app autenticador real |
| 6 | Esqueci a senha | sim (após A2) | Entrar → Esqueci → e-mail → link → senha nova → Entrar | travava com link vencido (A2, corrigido) |
| 7 | Convidar familiar e definir o que vê | sim | Compartilhar → quem é → escopos → "Criar convite" (≈4) | — |
| 8 | Profissional vê paciente e exporta | sim | Pacientes → paciente → Exportar (3) | rolagem longa no celular (M3) |
| 9 | Clínica convida profissional | sim | Clínica → e-mail → "Enviar convite" (2); aceite em `/convite-clinica/{token}` | — |
| 10 | Visitante instala PWA | sim | Conta → Instalar (instruções iOS/Android) | visitante sem login só vê o prompt do navegador |
| 11 | Usar offline | sim (pelo código) | registro sem rede → "guardado só neste aparelho" → envio automático (`resources/js/app.js:175`) | bloco privado não vai para a fila, avisa "não enviado" (correto) |

## 5. Pontos fortes

- `crisis-line` em todas as telas, inclusive públicas e agora nas de erro; texto deixa claro que o app não avisa ninguém.
- Títulos neutros (sem nome do paciente nem dado de saúde); push com payload fixo (`app/Domain/Push/PushSender.php:10`).
- Escala de energia de fonte única (`app/Domain/Mood/Scales.php:23`) com rótulos e setas coerentes em registro, histórico e alertas.
- Exportação com nome neutro e aviso de dado sensível; profissional só exporta o que foi liberado.
- Esqueci a senha com resposta neutra e prazo explícito (60 min).
- Datas `dd/mm/aaaa`, alvos de toque grandes, uma ação principal por tela.

## 6. Não verificado

- Familiar logado (o seed só tem convite pendente) — coberto por teste de feature.
- Leitor de tela real (NVDA/VoiceOver) e teclado manual; só axe e revisão de código.
- Instalação do PWA em aparelho real e sincronização offline ponta a ponta (sem `pwa.mjs` nesta rodada).
- 2FA com app autenticador e envio real de e-mail (log apenas).
- Tema escuro só em Registrar e Relatórios.
