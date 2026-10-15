# Comparativo: Bipolar — Diário de Humor × Estabiliza

*Levantamento de outubro de 2026 com base em fontes públicas: site appestabiliza.com, páginas nas lojas (App Store/Google Play) e matérias (Estado de Minas, jul/2026; Saúde Digital News, 08/09/2026; Medicina S/A). Não tivemos acesso à versão Pro por dentro; itens marcados “?” não foram confirmados publicamente.*

## O Estabiliza em resumo

- Criado pelo psiquiatra Dr. Renato Silva (PLANO DE VOO TAB LTDA). Apps nativos para iOS (exige iOS 16.4+) e Android.
- **Registro diário em 4 telas:** humor (6 opções), energia (5 níveis), cognição, hábitos (atividade física, **sol pela manhã — sim/não**, refeições ultraprocessadas), horas e qualidade do sono, anotação livre.
- **Painel** com médias e gráficos. **“Meus tratamentos”:** medicamentos, lembretes e agenda de consultas.
- **Biblioteca de conteúdos** educativos. Integração com **Apple Health** (passos e sono). Monitoramento passivo com IA anunciado para a v2.
- **Compartilhamento** por link + código de acesso enviado por WhatsApp/e-mail.
- **Grátis + Pro (R$ 14,90/mês)**, com histórico maior no Pro. Nota 4,6 na App Store; mais de 25 mil downloads.
- Pontos fortes reconhecidos nas avaliações: visual simples, rapidez do registro diário, credibilidade do autor e conteúdo em português.

## Tabela de recursos

| Recurso | Estabiliza | Bipolar (esta versão) |
|---|---|---|
| Humor por **período** (manhã/tarde/noite), 1 toque | Não (1 registro/dia) | **Sim**, escala −3..+3 (Life Chart/NIMH) |
| Energia, irritabilidade, ansiedade | Energia | **Sim** (as três, por período) |
| Sono | Horas + qualidade | **Horário de deitar e acordar** (cálculo automático) + qualidade + horas por período |
| Sol / luz | Sol pela manhã (sim/não) | **Minutos + momento do dia** (manhã/meio-dia/tarde/vários) |
| Cafeína, álcool, substâncias | Não | **Sim**: módulo privado de redução de danos (13 substâncias, formas, quantidade, período, motivo, efeito no humor, favoritos de 1 toque, correlação com humor) |
| Exercício | Sim/não | **Minutos** |
| Pensamentos e fala acelerados | “Cognição” genérica | **Sim**, escalas separadas |
| Gastos impulsivos | Não | **Sim** (intensidade + valor em R$) |
| Sexualidade como marcador de mania | Não | **Sim**, módulo privado opcional (PIN, borrão, inclusivo) |
| Ciclo menstrual | Não | **Sim** (opcional) |
| Medicação | Lista + lembretes | **Adesão por dose** (cada horário) + lembretes por e-mail/push |
| Estresse / acontecimentos de vida | Não | **Sim** |
| Sinais de alerta pessoais combinados com o terapeuta | Não | **Sim** (até 10) + **plano de ação** |
| Indicador de (hipo)mania + alerta precoce | Não (?) | **Sim**: linha de base pessoal de sono (28 dias), tríade sono↓ + humor↑ + desejo↑, aviso gentil + CVV/SAMU |
| Gráficos | Médias e gráficos | Life Chart, mapa de calor, tabela acessível, indicadores por dia |
| Áudio / vídeo / imagem no diário | Não | **Sim** (privado, nunca enviado) |
| Relatório para profissional | Link + código | **E-mail periódico com confirmação (opt-in duplo)** + PDF imprimível; controle por destinatário do que entra (anotações, marcadores, sexualidade) |
| Plataforma | iOS 16.4+ / Android | **Web/PWA instalável** em qualquer aparelho, **funciona offline** |
| Acessibilidade | ? | WCAG 2.1 AA verificada (axe 0 violações; Lighthouse 100), modo escuro, teclado |
| Privacidade / LGPD | Política padrão de app | **Auto-hospedado no Brasil**, exportação JSON/CSV, exclusão total, push sem conteúdo |
| Preço | Grátis + Pro R$ 14,90/mês | **Sem paywall**: histórico completo para todos |
| Biblioteca de conteúdos | **Sim** | Parcial (página da escala + textos de apoio) |
| Integração Apple Health / sensores | **Sim** (passos, sono) | Não (web) |
| Agenda de consultas | **Sim** | Não |

## Onde superamos

1. **Granularidade clínica:** humor por período captura ciclagem rápida e estados mistos que um registro diário perde.
2. **Marcadores de mania que ninguém pergunta:** sexualidade, gastos, pensamentos/fala acelerados, álcool/substâncias, cafeína — todos sinais citados no DSM-5 e em escalas como YMRS/ASRM.
3. **Prevenção ativa:** indicador diário com linha de base **pessoal** (quem dorme 5,5 h sempre não recebe alarme falso) e alerta precoce com o **plano de ação da própria pessoa** — abordagem com evidência em ensaio clínico (Perry et al., BMJ 1999).
4. **Adesão por dose**, não “tomei/não tomei”.
5. **Privacidade de verdade:** módulos opcionais, opt-in por destinatário, resumo sem detalhes íntimos, PIN, sem anúncios, sem paywall.
6. **Acesso universal:** funciona no navegador de qualquer celular ou computador, inclusive offline e em aparelhos antigos (o Estabiliza exige iOS 16.4+).

## Onde o Estabiliza ainda leva vantagem (próximos passos sugeridos)

- **Biblioteca de conteúdo** curada por psiquiatra → sugerimos uma seção “Aprender” com textos curtos revisados pela equipe.
- **Integração com sensores** (Apple Health/Google Fit) → em PWA é limitada; possível importar arquivo exportado.
- **Agenda de consultas** → simples de adicionar como lembrete com data única.
- **Marca e lojas** (presença nas lojas, avaliações) → empacotar o PWA como TWA na Google Play.

## Fontes

- appestabiliza.com (site oficial) — recursos, planos e preço.
- App Store e Google Play — páginas do app “Estabiliza” (nota, requisitos, descrição).
- Estado de Minas (em.com.br), julho de 2026 — matéria sobre o lançamento.
- Saúde Digital News, 08/09/2026 — matéria sobre a v2 e monitoramento passivo.
- Medicina S/A (medicinasa.com.br) — perfil do app e do criador.
- Evidências clínicas citadas: ver `docs/SEXUALIDADE.md` (DSM-5; Young 1978; Altman 1997; Jackson 2003; Perry 1999; Sit 2018; Kopeykina 2016; Dolman 2024).
