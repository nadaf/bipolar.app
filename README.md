# bipolar.app — um experimento de *vibecoding* com 3 IAs

Este repositório reúne três tentativas de criar o mesmo aplicativo: um **diário de humor para pessoas com transtorno bipolar**, publicado em [bipolar.app.br](https://bipolar.app.br). O código foi escrito por inteligência artificial, a partir de pedidos em linguagem natural, o chamado *vibecoding*.

Quem conduziu o experimento foi Ronaldo Nadaf, que tem transtorno bipolar tipo 1 e, por orientação da psicóloga, registra o humor por período do dia. O registro no papel não funcionou para ele, e daí veio a ideia.

## As três versões

| Pasta | Ferramenta | Status | Resultado |
|---|---|---|---|
| [`grok/`](grok/) | Grok | **beta 0.1** | É a versão que está no ar em bipolar.app.br. Foi a primeira a dar um resultado satisfatório. |
| [`vscode_copilot/`](vscode_copilot/) | VS Code + GitHub Copilot | alfa | Ficou insatisfatória. Está guardada só para comparação. |
| [`manus/`](manus/) | Manus | alfa | Ficou insatisfatória. Está guardada só para comparação. |

## O que a versão `grok/` faz

- Registro do humor de manhã, à tarde e à noite, numa escala de −3 a +3. Não existe período de madrugada, porque quem tem bipolaridade não deve ficar acordado até tarde para registrar.
- Sono, medicação, sintomas, notas em áudio e vídeo, e um gráfico do histórico.
- **Alertas automáticos** que cruzam todos os dados, e **alertas personalizados** que a própria pessoa monta (por exemplo, humor alto junto com muita vontade de sexo).
- Módulos opcionais e privados, **Sexualidade** e **Substâncias**, com uma abordagem de redução de danos e sem julgamento. Eles são protegidos por biometria ou PIN.
- Lembretes por notificação do celular ou do computador e por e-mail.
- Relatórios para psicólogo, psiquiatra ou família, que mostram só o que a pessoa autorizar.
- Uma IA opcional, em que cada pessoa usa a **própria chave** do provedor que preferir.
- Privacidade e controle: não existe área de administração, a pessoa pode exportar ou apagar todos os seus dados, e a política de privacidade está escrita em linguagem simples.
- Testes automáticos (PHPUnit, PHPStan, ESLint, axe e Lighthouse) e acessibilidade no padrão WCAG 2.2 AA.

Para instalar e saber mais, veja o [README da versão grok](grok/README.md).

## O que aprendemos

- Programar com IA funciona quando a pessoa sabe **o que quer**: ela testa no servidor de verdade, devolve os erros exatos e insiste na qualidade, inclusive no português.
- Fez diferença a IA testar o próprio trabalho (testes automáticos e testes no site publicado) em vez de só entregar o código.
- Não é dispositivo médico e não substitui acompanhamento profissional. Em crise, ligue para o **CVV 188** ou o **SAMU 192**.

## Licença

A versão `grok/` usa a licença [AGPL-3.0](grok/LICENSE). As outras pastas são guardadas só como registro do experimento.
