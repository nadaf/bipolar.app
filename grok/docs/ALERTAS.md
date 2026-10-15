# Alertas automáticos (V4)

> O motor **não faz diagnóstico**. Ele compara os registros recentes com o **padrão da própria pessoa** e mostra, em português simples, quais dados chamaram atenção. Serve para a pessoa se cuidar mais cedo e conversar com a equipe.

## Como funciona

- **Quando roda:**
  - `cron/analyze_signals.php` roda **de hora em hora** e analisa todos os usuários com registros nos últimos 60 dias.
  - Abrir o Histórico também dispara uma análise, no máximo a cada 10 minutos por sessão e **sem** notificar.
- **Dados usados:**
  - humor por período (manhã, tarde, noite);
  - variação de humor dentro do dia;
  - sono (deitar e acordar, ou horas informadas) e horário de deitar;
  - energia e irritabilidade;
  - pensamentos e fala acelerados;
  - gastos (escala e valor);
  - sol, cafeína e álcool;
  - estresse e acontecimentos; fase do ciclo menstrual;
  - doses esquecidas (`doses_json` ou "tomou a medicação? não");
  - sinais pessoais marcados;
  - frequência de notas em áudio, vídeo ou imagem;
  - dias sem registro.
  - Sexualidade e Substâncias **só entram se o módulo estiver ligado**.
- **Linha de base pessoal:**
  - mediana e MAD (desvio absoluto mediano × 1,4826) dos **28 dias anteriores**;
  - o escore é um "z robusto", (valor − mediana) / MAD, com um piso de escala por variável para não exagerar em quem é muito regular;
  - mediana e MAD resistem a dias fora da curva (Leys et al., 2013).
- **Níveis:** 1 = Atenção, 2 = Alerta, 3 = Alerta forte.
  - Humor −3 sempre gera nível 3 e mostra CVV 188 e SAMU 192.
- **Dedupe e intervalo:**
  - uma regra só gera um novo alerta depois de **72 h**, a menos que a severidade suba.
- **Ajuste por feedback:**
  - "👎 Não faz sentido" deixa a regra **menos sensível** para aquela pessoa (até −2);
  - "👍 Faz sentido" pode deixá-la um pouco mais sensível (até +1);
  - só o primeiro clique em cada alerta conta;
  - em Conta → Alertas é possível voltar ao padrão.
- **Banner:** quando há alerta do motor ativo, o banner antigo ("cuidado gentil") é escondido, para não haver avisos duplicados.

## Regras

| Regra | Dispara quando (padrão) | Por quê |
|---|---|---|
| **(Hipo)mania** | Nos últimos 3 dias há humor elevado (máximo ≥ +2, ou média ≥ +1 em 2 dias) **ou** sono ≥ 1,5 MAD e ≥ 1,5 h abaixo do habitual, **e** pelo menos 4 pontos. Valem 2 pontos: humor elevado, sono reduzido. Vale 1 ponto cada: energia 3; pensamentos acelerados; fala acelerada; gastos; irritabilidade; estimulante; desejo/comportamento sexual acima do habitual; cafeína, mídia, gasto em R$ ou cannabis com z ≥ 2; deitar muito mais tarde; sinal pessoal. | Sono reduzido é um dos pródromos mais precoces e frequentes da mania; mudanças de sono costumam preceder a mudança de humor em ~1 dia. |
| **Depressão** | Humor ≤ −1 em ≥ 4 dos últimos 7 dias, **ou** ≤ −2 em 2 dos últimos 3 dias, **ou** −3. O texto inclui sono muito acima ou abaixo do habitual, energia nula e pouco sol. | Pródromos depressivos: humor, sono, energia, interesse. |
| **Estado misto** | Em ≥ 2 dos últimos 3 dias há humor ≤ −1 **com** pensamentos ou fala acelerados, irritabilidade ≥ 2, energia ≥ 2 ou humor ≥ +1 no mesmo dia; **ou** variação de ≥ 3 pontos no mesmo dia com irritabilidade. | Sintomas de polo oposto em níveis modestos já mudam o curso (força-tarefa ISBD). |
| **Instabilidade / ciclagem** | Em 14 dias: ≥ 4 viradas entre dias altos e baixos; **ou** MSSD ≥ 1,5 e ≥ 2× o MSSD das 4 semanas anteriores; **ou** ≥ 3 dias com variação ≥ 3. | O MSSD capta instabilidade melhor que média ou desvio padrão. Mais viradas por período estão ligadas a pior curso. |
| **Lacuna** | ≥ 3 dias seguidos sem registro (e nada hoje), depois de ≥ 20 de 28 dias registrados. 7+ dias: nível 2. | Parar de registrar costuma acompanhar mudança de humor. Só vale para quem registrava com regularidade. |
| **Adesão** | ≥ 2 doses marcadas como não tomadas em 7 dias (4+: nível 2). | A não adesão é comum e está associada a recaída e internação. |
| **Substâncias** | ≥ 2 registros "acima do habitual" na semana; qualquer estimulante; beber pesado (≥ 5 doses num dia); ou cannabis com z ≥ 2. Nível 2 se combinado com sono reduzido. | Ver docs/SUBSTANCIAS.md. |
| **Sinais pessoais** | ≥ 2 sinais pessoais marcados hoje ou ontem (3+: nível 2). | Sinais combinados com a equipe são os mais específicos para cada pessoa. |
| *Contexto* | Fase pré-menstrual ou menstrual e estresse ≥ 3 aparecem como **anotação**; sozinhos, não disparam alertas. | As evidências sobre o ciclo menstrual são heterogêneas. Eventos de vida podem desencadear episódios. |

## Entrega e privacidade

- **No app (sempre):**
  - banner em Registrar;
  - cartão "Alertas automáticos" em Histórico, com a explicação e os botões de feedback.
- **Push (opcional, ligado por padrão):**
  - texto **genérico**, "Seu diário tem um novo aviso para você. Toque para ver.";
  - nada sobre humor, sono ou tipo de alerta aparece na tela bloqueada.
- **E-mail para a própria pessoa (opcional, desligado por padrão):** só "Há um novo aviso no seu diário", o link e os contatos de crise.
- **Pessoas de apoio:**
  - recebem somente se forem confirmadas **e** marcadas como "Receber alertas de apoio" (desligado por padrão);
  - só a partir do nível 2, **no máximo 1 aviso por semana**;
  - o conteúdo é apenas "<Nome> pode precisar de apoio", sugestões gerais de acolhimento e CVV 188 / SAMU 192;
  - **nunca** o motivo ou dados.

## Referências (verificadas no PubMed em 14/10/2026)

1. Bauer M, et al. Temporal relation between sleep and mood in patients with bipolar disorder. *Bipolar Disord* 2006;8(2):160-7. PMID 16542186. doi:10.1111/j.1399-5618.2006.00294.x. Mudanças de sono, sobretudo de mais de 3 h, precederam grandes mudanças de humor, em geral com 1 dia de antecedência.
2. Jackson A, Cavanagh J, Scott J. A systematic review of manic and depressive prodromes. *J Affect Disord* 2003;74(3):209-17. PMID 12738039.
3. Perry A, et al. Randomised controlled trial of efficacy of teaching patients with bipolar disorder to identify early symptoms of relapse and obtain treatment. *BMJ* 1999;318:149-53. PMID 9888904. Base dos "sinais pessoais" e do plano de ação.
4. Leibenluft E, et al. Relationship between sleep and mood in patients with rapid-cycling bipolar disorder. *Psychiatry Res* 1996;63(2-3):161-8. PMID 8878312.
5. Ebner-Priemer UW, Eid M, Kleindienst N, Stabenow S, Trull TJ. Analytic strategies for understanding affective (in)stability and other dynamic processes in psychopathology. *J Abnorm Psychol* 2009;118(1):195-202. PMID 19222325. Uso do MSSD.
6. Bonsall MB, et al. Nonlinear time-series approaches in characterizing mood stability and mood instability in bipolar disorder. *Proc Biol Sci* 2012;279:916-24. PMID 21849316.
7. Swann AC, et al. Bipolar mixed states: an International Society for Bipolar Disorders task force report. *Am J Psychiatry* 2013;170(1):31-42. PMID 23223893.
8. Carvalho AF, et al. Rapid cycling in bipolar disorder: a systematic review. *J Clin Psychiatry* 2014;75(6):e578-86. PMID 25004199.
9. Scott J, Pope M. Nonadherence with mood stabilizers: prevalence and predictors. *J Clin Psychiatry* 2002;63(5):384-90. PMID 12019661.
10. Scott J, Pope M. Self-reported adherence to treatment with mood stabilizers, plasma levels, and psychiatric hospitalization. *Am J Psychiatry* 2002;159(11):1927-9. PMID 12411230.
11. Johnson SL. Life events in bipolar disorder: towards more specific models. *Clin Psychol Rev* 2005;25(8):1008-27. PMID 16129530.
12. Teatero ML, Mazmanian D, Sharma V. Effects of the menstrual cycle on bipolar disorder. *Bipolar Disord* 2014;16(1):22-36. PMID 24467469.
13. Faurholt-Jepsen M, et al. Smartphone data as an electronic biomarker of illness activity in bipolar disorder. *Bipolar Disord* 2015;17(7):715-28. PMID 26395972.
14. Faurholt-Jepsen M, et al. Daily electronic self-monitoring in bipolar disorder using smartphones — the MONARCA I trial. *Psychol Med* 2015;45(13):2691-704. PMID 26220802. **Ressalva importante:**
    - o automonitoramento diário com retorno clínico **não reduziu** sintomas depressivos nem maníacos em 6 meses;
    - houve tendência a mais sintomas depressivos persistentes no grupo intervenção.
    - Por isso os alertas são um apoio, não um tratamento.
15. Leys C, Ley C, Klein O, Bernard P, Licata L. Detecting outliers: do not use standard deviation around the mean, use absolute deviation around the median. *J Exp Soc Psychol* 2013;49(4):764-6. doi:10.1016/j.jesp.2013.03.013 (não indexado no PubMed). Uso de mediana e MAD.
16. Lagerberg TV, et al. Excessive cannabis use is associated with earlier age at onset in bipolar disorder. *Eur Arch Psychiatry Clin Neurosci* 2011;261(6):397-405. PMID 21267743. Demais referências sobre substâncias estão em docs/SUBSTANCIAS.md.

## Alertas personalizados (V4.2)

Além das regras automáticas acima, a pessoa pode criar até 30 alertas próprios em **Histórico → Meus alertas personalizados**.

- **O que dá para usar:** humor (por período, mais alto, mais baixo, médio, variação no dia), sono (horas e qualidade), energia, irritabilidade, ansiedade, pensamentos/fala acelerados, gastos (escala e valor), estresse, sinais pessoais, café, álcool, sol, exercício, doses esquecidas e adesão, se registrou o dia, notas de mídia. Com os módulos ligados, também sexualidade e cada substância.
- **Como combinar:** até 6 condições, ligadas por **E** (todas) ou **OU** (qualquer uma). Cada condição vale de três jeitos:
  - **no dia**;
  - **em pelo menos K dos últimos N dias** (N de 2 a 30);
  - **por N dias seguidos** (2 a 14).
- **Intervalo entre avisos:** de 1 a 14 dias (padrão 3), e no máximo um aviso por regra por dia.
- **Prévia:** antes de salvar, mostra em quais dias dos últimos 14 a 180 dias (padrão 90) a regra teria valido e em quais teria avisado.
- **Quando roda:** logo depois de cada registro salvo (para o dia salvo e para hoje) e no cron de hora em hora (`analyze_signals.php`).
- **Avisos:** sempre no app. Push e e-mail são opcionais e levam só um texto genérico, nunca o nome da regra nem os dados.
- **Privacidade:**
  - regras com Sexualidade ou Substâncias só são vistas, editadas, testadas ou apagadas com as seções privadas desbloqueadas;
  - com o bloqueio ativo, os avisos dessas regras aparecem sem título nem motivo;
  - destinatários só recebem a **quantidade** de alertas personalizados, e só se a caixa “Incluir no relatório quantos alertas personalizados foram atendidos” estiver marcada. Alertas privados entram na conta só se esse destinatário também puder receber Sexualidade/Substâncias.
- “Registrou o humor no dia” não conta o dia de hoje como “não registrado” antes de ele terminar.

## Limitações honestas

- Os limiares são **heurísticos**, escolhidos a partir da literatura e não validados prospectivamente neste app. Espere falsos positivos e falsos negativos. O feedback existe para isso.
- Com menos de 7 dias de dados, a linha de base de sono não existe. Nesse caso usa-se um limite absoluto (≤ 5 h).
- Registros feitos depois do dia (retroativos) entram na próxima análise.
- O app não substitui o plano de crise combinado com a equipe nem serviços de emergência.
