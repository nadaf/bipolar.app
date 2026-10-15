# Referências da escala editorial e dos avisos

## Escala usada pelo produto

O diário usa sete níveis descritivos, não um teste clínico:

- `-3 Depressão`
- `-2 Muito baixo`
- `-1 Baixo`
- `0 Estável/eutimia`
- `+1 Elevado`
- `+2 Muito elevado`
- `+3 Euforia`

A escala é uma **simplificação editorial para registro pessoal**. Ela não foi validada, não mede gravidade, não diagnostica transtorno bipolar e não orienta tratamento. `mixed`/sintomas mistos é um marcador separado, não uma média entre polos. A interface deve dizer isso perto da escala e não transformar a pontuação em alerta clínico automático.

A referência de linguagem do Bipolar UK apresenta uma escala de 0 a 10, com 0–3 baixo, 4–6 intermediário e 7–10 alto [1]. O produto não copia essa métrica nem afirma equivalência: os sete níveis acima são uma escolha editorial para permitir registro rápido de depressão a euforia com intermediários.

A Cleveland Clinic descreve mania, hipomania, eutimia e estados mistos no contexto do transtorno bipolar, incluindo que mania pode envolver irritabilidade [2]. Por isso, o frontend pode oferecer descrições acolhedoras e um marcador independente para mistura/irritabilidade, mas não deve inferir diagnóstico a partir de um registro.

Não prescreva, interrompa ou ajuste medicação. O campo de medicação significa apenas o relato opcional da pessoa (`taken`, `not-taken`, `not-applicable` ou vazio), sem recomendação.

## Crise e apoio no Brasil

O Ministério da Saúde orienta buscar ajuda e lista CAPS, unidades básicas, UPA, pronto-socorro/hospital, SAMU 192 e CVV 188. A própria página informa que o CVV oferece apoio emocional e prevenção do suicídio gratuitamente pelo telefone 188 [3]. O site do CVV confirma atendimento voluntário, gratuito, sigiloso e pelo 188 [4].

Em risco imediato, tentativa de suicídio, perigo físico ou sofrimento intenso, a interface deve priorizar ligar para o **SAMU 192** ou procurar uma emergência, sem sugerir que o diário substitui atendimento. O Ministério da Saúde descreve o SAMU 192 como gratuito, 24 horas, e inclui tentativas de suicídio entre situações para chamar o serviço [5]. A disponibilidade local e o tempo de resposta não devem ser inventados pela aplicação.

Os números são apresentados como recursos de crise, não como triagem automática. O aplicativo não avalia risco, não chama serviços sozinho e não promete confidencialidade de redes telefônicas externas.

## OAuth oficial utilizado pelo backend

Os links oficiais usados para conferir o fluxo de autorização são:

- Google: [OAuth 2.0 para aplicações web](https://developers.google.com/identity/protocols/oauth2/web-server) [6]
- Microsoft: [OAuth 2.0 authorization code flow](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow) [7]
- X: [OAuth 2.0 authorization code](https://docs.x.com/fundamentals/authentication/oauth-2-0/authorization-code) [8]

O backend usa a identidade estável do próprio provedor (`sub` Google, `id` Microsoft Graph ou `data.id` X), nunca um email para auto-vincular contas de provedores diferentes.

## Referências

[1]: https://www.bipolaruk.org/get-support/track-your-mood/mood-scale/ "Bipolar UK — Mood scale"
[2]: https://my.clevelandclinic.org/health/diseases/9294-bipolar-disorder "Cleveland Clinic — Bipolar Disorder"
[3]: https://www.gov.br/saude/pt-br/assuntos/saude-de-a-a-z/s/suicidio-prevencao "Ministério da Saúde — Suicídio (Prevenção)"
[4]: https://cvv.org.br/ "Centro de Valorização da Vida — CVV"
[5]: https://www.gov.br/saude/pt-br/composicao/saes/samu-192 "Ministério da Saúde — SAMU 192"
[6]: https://developers.google.com/identity/protocols/oauth2/web-server "Google — OAuth 2.0 Web Server Applications"
[7]: https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow "Microsoft — OAuth 2.0 authorization code flow"
[8]: https://docs.x.com/fundamentals/authentication/oauth-2-0/authorization-code "X — OAuth 2.0 Authorization Code Flow"
