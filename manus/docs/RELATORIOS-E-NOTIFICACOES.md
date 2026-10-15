# Relatórios e notificações no cPanel

## Escopo e limites

O backend implementa preferências, destinatários autorizados, assinatura Web Push, renderização de relatório, outbox e worker CLI. **Não é um sistema de monitoramento de crise ou alarme de emergências.** Os lembretes são genéricos e não incluem humor, diagnóstico, sintomas ou notas.

O agendamento não promete o minuto exato: o cron do cPanel pode atrasar e o worker considera até cinco minutos de atraso, nunca antecipando o horário escolhido. Uma execução perdida não faz catch-up de todos os períodos anteriores. O endpoint de configurações informa o último heartbeat UTC; a interface informa que aguarda o cron quando ele estiver ausente ou antigo, sem alegar que a rotina está ativa.

E-mails de relatório não são criptografia ponta a ponta: o conteúdo passa pelo destinatário e pelo provedor SMTP. Revogar uma autorização não apaga mensagens que já chegaram.

## Antes da migração

1. Faça backup verificável do banco inteiro e teste a restauração.
2. Preserve o `config.php` privado existente. Não sobrescreva chaves SecretBox, credenciais OAuth, banco, SMTP ou VAPID; mantenha cópia protegida fora do `public_html`.
3. Confirme PHP **8.2 ou superior** (o ambiente de desenvolvimento foi testado em PHP 8.3.6), `curl`, `mbstring`, `openssl`, `sodium`, `pdo_mysql` e Composer. A série atual de `minishlink/web-push` exige PHP 8.2+; não há fallback seguro para PHP 8.1.
4. A partir de `backend-php/`, instale exatamente o lockfile sem dev dependencies:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

O `composer.json` usa `private/vendor` como diretório de dependências. O pacote contém PHPMailer 7.1.1 e Minishlink Web Push 10.1.0 no lockfile. Mantenha `composer.lock` no pacote de release e não altere os arquivos em `private/vendor` manualmente.

5. Aplique `migration-v2.sql` ao banco correto. O arquivo é **somente aditivo**: cria tabelas novas e não executa `DROP` ou `ALTER` em tabelas existentes. Para instalação nova, `schema.sql` já contém as mesmas tabelas.
6. Verifique permissões: `config.php`, chave de cifragem, credenciais SMTP/VAPID e `private/` não podem ser servidos por HTTP; o diretório de armazenamento deve ser privado e `0700`.

## Configuração SMTP cPanel

Use a seção `smtp` de `private/config.php`, inicialmente desabilitada. O backend rejeita configurações incompletas e **nunca usa SMTP sem TLS**:

- porta 465: SMTPS explícito;
- porta 587: STARTTLS explícito;
- host, usuário, senha, remetente e endereço do remetente são obrigatórios;
- portas diferentes de 465/587 são recusadas;
- o remetente deve ser uma conta autorizada no domínio.

Configure no cPanel os registros SPF, DKIM e DMARC alinhados ao domínio do remetente. Faça a confirmação de entrega apenas com uma conta de teste controlada; este projeto não cadastra destinatários reais nem envia mensagens durante os testes.

## VAPID e Push

Gere as chaves apenas no ambiente privado de produção ou em um local temporário fora do projeto. Nunca coloque uma chave privada no `config.example.php`, no repositório, no frontend ou em logs. A resposta `GET?action=automation` expõe somente a chave pública quando a capacidade está realmente disponível.

A assinatura aceita apenas endpoints HTTPS na porta 443 (implícita ou explícita), com domínios conhecidos de FCM, Mozilla, Windows ou Apple; endpoints locais, redirecionamentos e hosts arbitrários são recusados. O endpoint e as chaves ficam cifrados; a busca usa apenas SHA-256 do endpoint. A inscrição é vinculada ao usuário, e um usuário não pode desinscrever a assinatura pertencente a outro. Uma resposta 410 desativa a assinatura sem registrar a resposta bruta do provedor.

O payload de lembrete Push é sempre genérico:

- título: `Um momento para você`;
- corpo: `Se quiser, faça uma anotação no seu diário.`;
- URL: `/`;
- tag estável do dia e slot.

O canal Push não transmite registros do diário.

## Cron cPanel

Configure **uma única vez**, com o caminho real do usuário e o PHP 8.2+ do cPanel:

```cron
* * * * * /usr/local/bin/php /home/USER/bipolar-private/cron.php >> /home/USER/bipolar-private/cron.log 2>&1
```

Ajuste o binário PHP, o usuário e os caminhos ao ambiente real. O arquivo `cron.php` possui guarda `PHP_SAPI === 'cli'`, carrega o bootstrap privado por caminho relativo, obtém `GET_LOCK` no MySQL e limita a execução a no máximo 100 entregas. Não o publique como rota do servidor. Não crie uma segunda entrada de cron para o mesmo usuário; pausar os usuários é feito desabilitando suas preferências e pode ser revertido.

O cron faz duas fases: agenda jobs elegíveis no outbox e entrega jobs prontos. O outbox é idempotente por usuário, evento e alvo. Antes de tentar uma entrega, o worker grava uma marca durável de tentativa (`unknown`). Só depois de confirmação do provedor marca `sent`. Uma interrupção ou resposta inconclusiva não é repetida automaticamente. Isso evita repetição cega, mas pode deixar uma entrega sem confirmação: não promete exatamente uma entrega nem chegada garantida à caixa de entrada. O histórico nunca expõe erros crus do provedor.

## Consentimento e conteúdo

A interface mostra seletores de destinatários, conteúdo e período. Envios manuais exigem uma confirmação explícita; programação automática exige consentimento separado. O e-mail manual não exige habilitar a rotina automática. Um fingerprint do conteúdo e os endereços confirmados impedem enviar uma alteração posterior à prévia sem nova revisão. Há limite de dez destinatários por usuário. Nome e e-mail dos destinatários, e-mail próprio de lembrete, preferências, payload congelado de relatório, endpoints e chaves ficam protegidos por SecretBox com a chave já existente.

Um relatório contém somente registros do intervalo pedido, data, período (manhã/tarde/noite), humor de -3 a +3 com rótulos, contagem de dias/registros e distribuição de níveis. Não calcula média, não diagnostica, não inclui anexos, mídias ou links públicos. Notas e detalhes (sono, medicação, misto, sintomas e fatores) só entram quando autorizados separadamente. HTML usa escape UTF-8 e há uma versão de texto alternativo.

- diário: período do dia anterior;
- semanal: os sete dias anteriores no `weekday` selecionado (0 domingo a 6 sábado);
- mensal: mês-calendário anterior; `monthDay` é ajustado para o último dia do mês curto;
- customizado: blocos anteriores de `intervalDays`, ancorados no `startDate` (1–90 dias).

A desativação de relatórios requer apenas salvar a preferência desligada e funciona sem SMTP. Jobs automatizados enfileirados são cancelados; um job manual autorizado permanece, exceto quando seu destinatário é removido, situação em que é cancelado antes do envio. Nenhuma revogação remove e-mails já recebidos.

Lembretes Push e e-mail usam apenas canal explicitamente habilitado. E-mail de lembrete usa exclusivamente `reminders.email`, que pertence ao usuário e precisa ser informado/validado; nunca usa a lista de contatos do relatório. Slots e até seis slots customizados respeitam os dias, janela de silêncio (inclusive a que cruza meia-noite), `skipRecorded` por período e o caso sem período quando os três períodos do dia já têm registro. `quietStart == quietEnd` desativa o silêncio. `in-app` é responsabilidade do timer do frontend e não é enfileirado pelo cron.

No iOS, Push depende de instalação na Tela Inicial e permissão direta do usuário (iOS/iPadOS 16.4+). O produto não deve apresentar Push ou e-mail como alarme de emergência.

## API implementada

As rotas usam a sessão e o mesmo cookie/CSRF do backend existente. Todas as rotas abaixo exigem usuário autenticado; POST exige `X-CSRF-Token` e a verificação de origem existente.

- `GET?action=automation` retorna `{settings, capabilities}`. `capabilities` contém `smtp`, `push`, `vapidPublicKey` pública e `cronLastRun` ISO/`null`.
- `POST?action=automation` recebe `{settings}`, valida, cifra e retorna a mesma estrutura.
- `POST?action=push_subscribe` recebe `{subscription: PushSubscription.toJSON()}`.
- `POST?action=push_unsubscribe` recebe `{endpoint}`.
- `POST?action=send_report` valida datas (não futuras, no máximo 366 dias), consentimento e no máximo dez IDs. Com SMTP válido retorna somente `{status:"queued",queued:n}`; nunca afirma `sent` ao colocar no outbox.
- `GET?action=delivery_history` retorna até 30 entregas próprias, com estados `queued`, `sent`, `failed` ou `unknown`, rótulo controlado e mensagem genérica.

Quando o recurso necessário não está configurado, a resposta usa o código genérico `automation_not_configured`; credenciais e erros crus não chegam ao cliente.

## Testes executados

Executado no sandbox, somente no banco local descartável `bipolar_test` e com usuários fictícios:

- `tests/integration.php`: **28 verificações passaram**;
- `tests/automation.php`: **16 verificações passaram**;
- `tests/automation-safeguards.php`: **25 verificações passaram**, incluindo consentimento manual, fingerprint, destinatários alterados, horários não antecipados, silêncio, mês curto e tentativa inconclusiva não repetida;
- frontend: **29 testes JavaScript/TypeScript passaram**, dos quais 15 são novos das regras de relatórios e lembretes;
- `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction` com lockfile;
- nenhum SMTP, endpoint Push real, conta real, cron cPanel ou cPanel foi acessado.

A entrega real SMTP/Push permanece **não testada por falta de credenciais e destinatários de teste autorizados**. Antes da produção, valide o cron e a entrega usando contas de teste autorizadas, sem conteúdo sensível, e então pause/desabilite as preferências após a verificação.

## Uso no aplicativo

Abra **Relatórios** para preparar um documento de hoje, dos últimos sete dias, do mês atual ou de datas escolhidas. Veja a prévia e marque explicitamente se deseja incluir notas e detalhes de saúde. Baixe HTML, imprima/salve em PDF ou use o menu de compartilhamento do dispositivo. O e-mail manual permite escolher pessoas salvas e revisar seus endereços. Um relatório compartilhado pelo dispositivo pode ir como arquivo ou texto, conforme suporte do navegador.

Na aba **Programar relatórios**, escolha frequência, hora, fuso, conteúdo e autorize a lista de destinatários. Alterar sua rede de cuidado pausa a programação até nova autorização; as alterações de programação cancelam jobs automatizados ainda na fila. Nenhum envio acontece por simplesmente cadastrar um contato. Os estados de fila, aceitação, falha e resultado inconclusivo ficam no histórico.

Em **Configurações → Lembretes no seu ritmo**, escolha aviso dentro do app, push ou e-mail próprio; configure manhã, tarde, noite e até seis horários extras, dias, fuso e silêncio. Para push, toque em “Ativar neste dispositivo” e conceda a permissão. Depois salve os lembretes. O modo in-app só funciona com a janela visível; ele não é um agendamento de sistema. Ao sair da conta, a interface tenta remover a inscrição push do dispositivo. Se houver erro, confira as permissões do navegador.

O texto discreto não inclui informações de humor, mas o sistema pode exibir o nome do app. O servidor respeita horários de silêncio, períodos já anotados e lembretes vencidos. Falhas e atrasos do sistema ou provedor continuam possíveis.

No envio manual, o backend confere a integridade do conteúdo e os endereços aprovados. Se houver alteração desde a prévia, retorna `report_changed` e exige atualizar/revisar, em vez de ampliar silenciosamente o relatório. Uma nova tentativa no mesmo diálogo usa a mesma chave de idempotência.

## Limites e revogação

Relatórios por e-mail têm limite de 8 MiB de conteúdo serializado (HTML e texto juntos). Se ultrapassarem, reduza o período ou desative notas/detalhes. Um envio manual excessivo retorna um erro explícito; um relatório automático excessivo aparece como falha no histórico, sem transmissão.

O limite de 20 pedidos de relatório por 15 minutos é por conta autenticada, após verificação de sessão e CSRF; usuários no mesmo IP não compartilham esse limite. Inscrições push são reconhecidas por conta e dispositivo; outra conta não pode habilitar push usando uma inscrição alheia.

Cancelamentos confirmados antes do início da tentativa impedem a entrega. Uma mensagem cuja tentativa já começou pode estar em trânsito e não pode ser retirada. O worker só chama o provedor se conseguir reivindicar atomicamente um job ainda `queued`; uma revogação anterior impede esse passo. Rascunhos de preferências recebem aviso de descarte ao navegar ou fechar; salve os destinatários antes da programação. A cadência editada é preservada ao salvar a rede de cuidado, mas precisa de nova autorização.
