# Bipolar — implementação aprovada

- [x] Diário de humor por data e manhã, tarde ou noite, com seleção rápida; consultar e editar registros anteriores. Escala pesquisada de euforia a depressão com níveis intermediários, linguagem descritiva não diagnóstica e sintomas mistos separados.
- [x] Detalhes opcionais de texto, sintomas, contexto, sono, medicação e fatores relevantes; nenhum obrigatório no registro básico.
- [x] Áudio e vídeo: captura, anexos, consentimento explícito, reprodução e persistência associada ao registro, com estados de permissão negada e limites de tamanho.
- [x] Histórico com calendário, linha do tempo, gráfico de variação por período e filtros. Separar inequivocamente dados ilustrativos e registros reais; exportação voluntária.
- [ ] OAuth2 Google, X e Microsoft com sessão segura e área pessoal protegida no backend PHP; credenciais ausentes devem manter visitante desautenticado e explicar configuração, nunca simular login.
- [x] PWA com manifesto, ícones, favicon, service worker de interface e instalação com fallback iOS; experiência móvel e desktop.
- [x] Frontend refinado, português brasileiro, teclado, leitores de tela, contraste, alvos confortáveis, preferências de movimento, foco de diálogos e estados vazios/erro/carregamento.
- [x] Privacidade explícita, consentimento de mídias, avisos de diário não substitutivo, recursos de crise brasileiros, sem avaliação clínica automatizada nem recomendações de medicação.
- [x] Backend Apache/PHP/MySQL 5, instruções completas cPanel, banco, variáveis, OAuth2, domínio, HTTPS, uploads e operações, análise cPanel versus VPS. Compatibilidade de esquema não implica recomendar MySQL obsoleto.
- [x] Revisão independente somente leitura da ligação entre os componentes, compilação, verificação de tipos e correção de problemas confirmados.

## Estrutura e decisões da implementação

PWA React/TypeScript estática em `client`, adequada ao Apache sem Node em produção. `client/src/lib/diary.ts` contém modelo, escala e IndexedDB no modo local; `client/src/lib/api.ts` contém transporte para PHP. Componentes do diário em `client/src/components/diary`. Backend distribuído em `backend-php`, com API de sessão/OAuth2/CRUD/mídia e esquema SQL compatível com MySQL 5.7. Documentação em `docs`. Não há acesso autorizado ao cPanel nem credenciais OAuth neste ambiente: entrega de pacote implantável e instruções, não alegação de implantação do domínio.

Design: minimalismo orgânico editorial; verde-sálvia profundo, fundos marfim, contornos finos, formas suaves, densidade confortável. Tipografia Outfit e DM Sans; assinatura de marca em duas curvas que convergem. Sidebar discreta, diário como foco principal e coluna auxiliar contextual; navegação inferior no celular. Sem imagens decorativas de banco. Interações com transições breves e opção de movimento reduzido. Voz acolhedora sem gamificar sofrimento: “Um momento para olhar para você.”, “Cada registro conta. No seu ritmo.”

## Evidências e limites de entrega

Interface e PWA implementadas com prévia HTTP 200. Build estático e TypeScript sem erros. 14 testes JS/TS passaram, sendo 8 novos do diário/API e 6 do template. PHP lint passou e 28 verificações de integração PHP com MariaDB local/usuários sintéticos passaram. Revisão independente confirmou três riscos concretos de gravação/carga/anexos; corrigidos com bloqueio durante gravação, bloqueio de escrita após carga falha, exclusão somente de anexos persistidos, idempotência de mídia e aviso antes de fechar com pendências. Rascunhos não salvos recebem confirmação de descarte.

O código OAuth2 e as sessões/proteções PHP estão implementados; a aceitação operacional Google/X/Microsoft permanece aberta até registrar aplicativos, preencher credenciais privadas e testar os fluxos no domínio. Não há usuário simulado. cPanel, DNS e seu MySQL 5.7 não foram acessados; teste em MariaDB não certifica essa hospedagem. Gravação/captura multipart e codecs em dispositivos reais não foram testados. Pendências de mídia no modo servidor são em memória e não formam fila durável offline; encerramento forçado pode perdê-las. Esses limites aparecem no guia.

## Ampliação solicitada em 14/10/2026

- [x] Envio de relatórios para psicólogos, psiquiatras e familiares, com destinatários escolhidos pelo usuário; frequência diária, semanal, mensal ou personalizada. Oferecer envio manual e programação por e-mail; selecionar período e conteúdo, pré-visualizar, consentir explicitamente, pausar/revogar e não enviar exemplos ou mídias automaticamente.
- [x] Notificações opcionais para as anotações: manhã, tarde, noite e horários personalizados, dias da semana, fuso, período de silêncio e opção de não lembrar períodos já registrados. Diferenciar lembrete dentro do app aberto, push com app fechado e e-mail, sem prometer agendamento durável apenas com timer JavaScript.
- [x] Backend PHP com preferências cifradas, SMTP com TLS, Web Push/VAPID, inscrições por usuário, cron CLI cPanel, prevenção de duplicidade, pausa, tratamento de falhas e migração de banco sem apagar registros. E-mails, push e cron só serão efetivos após configuração real; não simular entrega.
- [x] Corrigir a frase para “Você é mais que o seu humor de hoje.”, sem palavras coladas ao remover quebra de linha; revisar textos novos e existentes em português brasileiro.
- [x] Atualizar guias e pacotes completos; testar a lógica de períodos, frequências, consentimento, silêncio e integração. Revisão somente leitura das novas partes.

Automação pertence ao aplicativo: PHP/cron na hospedagem do usuário, não tarefas recorrentes do Manus. Nenhum destinatário real será cadastrado ou contatado nesta tarefa. Relatórios por e-mail incluem apenas conteúdo autorizado, sem links públicos para o diário. Lembretes usam texto discreto, sem humor ou diagnóstico na tela bloqueada. No modo local, envio manual é permitido mediante ação explícita e timers só funcionam com o app aberto; envio recorrente/push exige backend configurado.

### Evidências da ampliação 1.1

29 testes JavaScript/TypeScript passaram, incluindo 15 novos. PHP: 28 verificações do diário, 16 de automação e 25 de proteção passaram com usuários sintéticos e MariaDB local, sem rede SMTP/push. TypeScript e lint PHP passaram. Revisão somente leitura identificou riscos de corrida de cancelamento, inscrição de outra conta, rascunhos, formas de salvar, cota por IP e tamanho de relatório; corrigidos com reivindicação atômica, reconhecimento por conta/dispositivo, guard de rascunhos, serialização dos formulários, cota autenticada por usuário e limite explícito de 8 MiB.

[ ] Verificação operacional de SMTP, Web Push, provedores OAuth e cron na hospedagem real: pendente de configuração e contas de teste autorizadas. O código e a migração estão entregues, mas nenhuma entrega externa nem implantação no domínio foi realizada.
