# Reteste de usabilidade — 31/10/2026

Reteste dos 9 achados de `revisao-usabilidade-2026-10-31.md`, seguindo `.claude/agents/usability-reviewer.md`:
banco de teste semeado com `php tests/e2e/seed.php`, `php artisan serve` em `bipolar.test:8123`,
`tests/e2e/a11y.mjs` (412/1280 px, claro/escuro, agora com Chrome em pt-BR via `--lang=pt-BR` e `LANGUAGE=pt_BR`) e cópia temporária em 375 px fora do repositório.
Mais um roteiro pontual (Puppeteer + axe) para abrir o diálogo de remoção na `/clinica`.

## 1. Resumo

Os 9 achados foram corrigidos. axe segue sem nenhuma violação em 14 telas × 2 larguras × 2 temas, sem erro de JS/CSP, nas duas rodadas (412 e 375 px).
Todas as telas em 375 px agora têm exatamente 375 px de largura (antes `/alertas` tinha 398 px). Pest: 149 testes verdes (7 novos em `tests/Feature/UsabilityReviewTest.php`), Pint e PHPStan verdes, `scripts/check-public-tree.sh` ok.

Contagem atual: **S4: 0 · S3: 0 · S2: 0 · S1: 0** entre os achados originais. Pendências fora do escopo listadas em "Não verificado".

## 2. Tabela de telas

| Tela | URL local | Usuário demo | Status | Sev. máx. |
|------|-----------|--------------|--------|-----------|
| Entrar / Criar conta | `/entrar`, `/criar-conta` | — | pronta | — |
| Registrar | `/registrar` | ana@demo.test | pronta | — |
| Histórico / Dia | `/historico`, `/historico/{date}` | ana@demo.test | pronta | — |
| Relatórios | `/relatorios` | ana@demo.test | pronta | — |
| Alertas e lembretes | `/alertas` | ana@demo.test | pronta | — |
| Conta | `/conta` | ana@demo.test | pronta | — |
| Compartilhar / detalhe | `/compartilhar`, `/compartilhar/{id}` | ana@demo.test | pronta | — |
| Pacientes / relatório | `/cuidado`, `/cuidado/{patient}` | pro@demo.test | pronta | — |
| Clínica | `/clinica` | admin@demo.test | pronta | — |

## 3. Achados — situação

| # | Antes | Correção | Verificação |
|---|-------|----------|-------------|
| A1 [S4] | Grade de lembretes 398 px em 375 px | Dias nas linhas, períodos nas colunas (`settings/alerts.blade.php`) | print 375 px com 375 px de largura; teste `grade de lembretes tem dias nas linhas…` |
| A2 [S3] | Remover profissional sem confirmação | `<dialog>` modal via `confirmarEnvio` (`resources/js/app.js`); sem JS envia direto | roteiro: diálogo abre, foco em "Cancelar", Esc fecha e foco volta a "Remover Dra. Beatriz Lima", ninguém removido; axe 0 |
| A3 [S3] | Sem aviso de versão nova | Faixa `#sw-nova-versao` + botão "Atualizar" → `SKIP_WAITING`; `skipWaiting: false` explícito no `workbox.config.cjs` | `public/sw.js` contém o ouvinte `SKIP_WAITING`; teste de marcação |
| A4 [S3] | Instalação não explicada | Seção "Instalar o app" em `/conta`: botão via `beforeinstallprompt`, instruções iOS/Android, "já instalado" em modo standalone | teste de marcação; print `depois-conta-375.png` |
| A5 [S2] | Nome do paciente/data no `<title>` | "Relatório — Bipolar", "Dia do histórico — Bipolar" | testes de título |
| A6 [S2] | Familiar sem orientação | Bloco "Como ajudar" (sem alarmismo, CVV 188, SAMU 192) só para `grantee_kind = familiar` | teste: familiar vê, profissional não |
| A7 [S2] | "Beta" ambíguo | Aviso `warn` acima da grade: salva, mas ainda **não envia** | teste; print |
| A8 [S2] | Gestor não sabe como vincular paciente | Seção "E os pacientes?" com o passo a passo do convite pelo paciente | teste; print `depois-clinica-375.png` |
| A9 [S1] | Data `mm/dd/yyyy` | `lang="pt-BR"` nos `type="date"`; Chrome de teste em pt-BR (só `--lang` não bastou no Linux: foi preciso `LANGUAGE=pt_BR`). O formato nativo segue o idioma do navegador da pessoa | print `depois-compartilhar-375.png` com `dd/mm/aaaa` |

## 4. Tarefas críticas

1–4, 6: concluíveis como antes; 3 agora sem rolagem lateral. 5 (familiar): concluível, com orientação. 7: adicionar profissional concluível; vincular paciente continua sendo do paciente, agora explicado. 8: concluível pela seção "Instalar o app". 9: fila offline inalterada (não testada sem rede). 10: telas de Master não estão neste repositório.

## 5. Pontos fortes

Mantidos: axe 0, linguagem acolhedora, CVV/SAMU em todas as telas, categorias sensíveis privadas por padrão. Novo padrão reutilizável: `confirmarEnvio` para qualquer ação destrutiva.

## 6. Não verificado

- Aviso de versão nova e botão de instalar em navegador real com build de produção servido por HTTPS (o SW só registra em produção; não há HTTPS local).
- Registro realmente offline; leitor de tela real (NVDA/TalkBack).
- Envio de lembretes (ainda não implementado — agora dito na tela).
