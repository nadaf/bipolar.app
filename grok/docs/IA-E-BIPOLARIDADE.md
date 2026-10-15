# IA e bipolaridade: brainstorm com os pés no chão

O "Reflexões com IA" é um companheiro **opcional** de reflexão que, desde a V4.3, usa o provedor de IA e a chave de API escolhidos pela própria pessoa (xAI, OpenAI, Anthropic, Google, DeepSeek, Mistral, OpenRouter ou um endereço compatível com OpenAI). Este documento reúne ideias, riscos e limites. Ele **não é** uma promessa de eficácia clínica.

## O que já existe na V4

| Recurso | O que faz | Limite |
|---|---|---|
| Resumo da semana | Narrativa curta sobre humor, sono e energia, um padrão, algo que foi bem e uma pergunta para reflexão | Usa só as categorias autorizadas. Pode errar números, por isso o app sempre mostra os dados originais no Histórico. |
| Explicar um alerta | Traduz o alerta do motor de regras (que é determinístico) em linguagem simples | A IA **não decide** se há alerta; quem decide são as regras de docs/ALERTAS.md. |
| Preparar consulta | 4 a 7 perguntas ou observações objetivas, com datas e números, para levar ao psiquiatra ou psicólogo | Dúvidas sobre remédio viram **pergunta para o médico**, nunca sugestão. |
| Check-in por conversa ou voz | Conversa curta que **propõe** um registro (humor, sono, energia…). A pessoa confirma antes de salvar. | Voz via SpeechRecognition do navegador: no Chrome, o áudio pode passar pelos servidores do Google. A tela avisa. |

## Salvaguardas implementadas

- **Desligado por padrão.**
  - Sem `xai.api_key` no config, o recurso fica escondido.
  - Com a chave, cada pessoa precisa dar **consentimento explícito** e escolher categorias. Base legal: LGPD art. 11, I, consentimento específico e destacado para dado sensível de saúde.
  - Revogação a qualquer momento (art. 8º §5º).
- **Minimização:**
  - por padrão vão humor, sono, energia e irritabilidade/ansiedade, marcadores, medicação (sem nomes de remédios) e alertas;
  - **anotações, sexualidade e substâncias ficam fora** e só vão se marcadas, com confirmação extra;
  - não enviamos e-mail nem nome completo, só o primeiro nome.
- **Prompt de sistema:**
  - proíbe diagnóstico e qualquer sugestão de iniciar, parar ou mudar medicação ou dose;
  - manda usar só os dados fornecidos e reconhecer quando faltam.
- **Crise:**
  - expressões de risco (suicídio, autolesão, "não aguento mais viver", overdose…) são detectadas **localmente, antes** de chamar a API;
  - a conversa é pausada e aparecem CVV 188 e SAMU 192;
  - o texto **não** é enviado ao provedor de IA.
  - A detecção por palavras-chave é imperfeita: pode deixar passar frases indiretas e disparar em frases ambíguas.
- **Custo e abuso:**
  - limite por hora (padrão 20 pedidos);
  - **teto mensal em US$ por pessoa** (padrão 2,00), calculado pelos tokens que a API informa e pelos preços do config;
  - histórico de uso em `ai_usage` e no export LGPD.
- **Transparência:** cada resposta mostra "Gerado por IA (modelo). Pode errar; não é orientação médica."

## Ideias para o futuro (com ressalvas)

1. **Detecção de mudança na linguagem.** Comparar escrita e fala com o padrão da própria pessoa, por exemplo velocidade, quantidade de texto, mudanças de assunto e pronomes. Há pesquisa nessa linha, mas os resultados variam entre estudos e idiomas. Seria preciso validação em português e consentimento separado.
2. **Plano de crise conversacional.** Ajudar a montar (não substituir) o plano combinado com a equipe: sinais, o que fazer, quem chamar. O texto final fica com a pessoa e com o terapeuta.
3. **Psicoeducação sob demanda.** Respostas citando material curado, como os docs do app e diretrizes, em vez de "conhecimento" livre do modelo, para reduzir alucinações.
4. **Sumário para o profissional.** Só com autorização por destinatário, igual aos relatórios. Nunca envio automático.
5. **Rotina social (IPSRT).** Lembretes conversacionais de horários regulares (acordar, refeições, contato social). A terapia interpessoal e de ritmos sociais tem evidência, mas a versão "por chatbot" não foi testada.
6. **Modelo local.** Rodar um modelo pequeno no aparelho evita enviar dados a terceiros. Hoje a qualidade em pt-BR e o custo de hardware ainda limitam.

## Riscos e por que ir devagar

- **Automonitoramento não é tratamento.** No ensaio MONARCA I, o automonitoramento diário por smartphone com retorno clínico **não reduziu** sintomas depressivos nem maníacos em 6 meses, e houve tendência a mais sintomas depressivos no grupo intervenção (Faurholt-Jepsen et al., *Psychol Med* 2015, PMID 26220802). Uma camada de IA não muda isso por si só.
- **Alucinações e excesso de confiança.** Modelos de linguagem podem afirmar coisas erradas com convicção. Por isso a IA nunca gera alertas nem valores; ela só comenta dados já calculados.
- **Mania e hiperengajamento.** Na hipomania, a pessoa pode conversar demais com o app, de madrugada, e a IA pode reforçar ideias grandiosas. Mitigações:
  - limite por hora;
  - prompt que evita validar planos impulsivos.
  - Pendente: um aviso de "já é tarde, que tal descansar?" depois das 23h.
- **Dependência e substituição de cuidado.** O texto sempre devolve a pessoa para a equipe e para a rede de apoio.
- **Privacidade e transferência internacional.** O provedor escolhido pela pessoa geralmente fica fora do Brasil (LGPD art. 33). Retenção e uso para treinamento seguem os termos de cada provedor e podem mudar; a tela de Conta mostra o link dos termos do provedor escolhido.
- **Custos.** Com chave própria, o provedor cobra direto da pessoa. O teto mensal e os preços do config valem só para a chave compartilhada opcional do operador.
- **Responsabilidade.** Não há aprovação regulatória (Anvisa/CFM) para uso clínico. O recurso é de bem-estar e reflexão, e deve ser apresentado assim.

## Configuração (resumo, V4.3)

```php
'ai' => [
    'secret_key' => '',     // base64 de 32 bytes: php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
    'operator_fallback' => ['provider' => '', 'api_key' => '', 'model' => ''], // opcional; vazio = sem chave compartilhada
    'requests_per_hour' => 20, 'max_output_tokens' => 900, 'timeout_seconds' => 60,
],
```

Cada pessoa escolhe o provedor e o modelo e cola a própria chave em **Conta → Reflexões com IA**. A chave é cifrada com libsodium (secretbox), nunca volta para o navegador (só os 4 últimos caracteres) e nunca vai para os logs. O botão **Testar chave** faz uma pergunta curtíssima e explica o erro em português (chave inválida, sem crédito, limite de uso, tempo esgotado, modelo recusado).

Adaptadores: formato OpenAI (Chat Completions) para xAI, OpenAI, DeepSeek, Mistral, OpenRouter e URL própria; Messages API para Anthropic; generateContent para Google Gemini. URL própria só com https e para endereços públicos (não aceita rede interna).
