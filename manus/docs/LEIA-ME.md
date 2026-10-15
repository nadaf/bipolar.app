# Bipolar — seu diário de humor

O aplicativo é uma **PWA React/TypeScript**. Ela pode ser instalada pelo navegador, funciona em celular e desktop e não exige Node.js no servidor cPanel. O build gera arquivos estáticos, e o backend é PHP independente.

## O que usar agora

Na prévia, o diário funciona **neste dispositivo**: selecione um humor, escolha manhã/tarde/noite e salve. Texto, sono, medicação, sintomas, contexto e mídia são opcionais. Os registros ficam no IndexedDB deste navegador, sem senha e sem criptografia local. Não há envio à nuvem no modo local. O gráfico inicial pode exibir dados ilustrativos identificados; eles não são registros pessoais, não são persistidos e nunca entram na exportação.

Registros podem ser consultados no histórico com calendário e filtros, editados e excluídos. JSON e CSV exportam os campos de texto e os metadados dos anexos, **não os arquivos de áudio e vídeo**. Salve os originais se desejar um backup das mídias locais. Limpar dados do navegador pode apagar seu diário. Use um dispositivo pessoal protegido.

A gravação pede permissão e consentimento. Aceita até 2 minutos por gravação e 25 MB por arquivo. Arquivos anexados podem ter outra duração, respeitando o tamanho. A compatibilidade da captura e dos formatos depende do navegador. Nada é enviado sem salvar. Notas de texto podem complementar mídia para acessibilidade; não existe transcrição automática.

## Para rodar em bipolar.app.br

Use o pacote de implantação `bipolar-cpanel-deploy.zip`: contém `public_html/` (frontend compilado e API pública), `bipolar-private/` (código privado e modelo de configuração), `schema.sql` e este guia. **Não extraia a pasta privada dentro de public_html.** Configure o banco, PHP moderno, HTTPS e os provedores conforme [Implantação cPanel](IMPLANTACAO-CPANEL.md).

O arquivo público `app-config.js` vem em modo local. Só depois de instalar e validar a API, altere `serverEnabled` para `true`. O login será habilitado para cada provedor que tiver Client ID e Secret configurados. Nenhuma senha ou chave deve aparecer no frontend. Google, X e Microsoft criam identidades separadas; contas não são vinculadas automaticamente pelo email. Registros locais não são enviados automaticamente para uma conta.

A entrega não configura DNS nem acessa seu cPanel. Não é possível afirmar que o domínio esteja implantado ou que o login externo funcione sem suas configurações. A prévia e o pacote são distintos do domínio de produção.

## PWA e offline

O manifesto, favicon e ícones já estão incluídos. Em produção, o service worker guarda apenas a interface, os assets e a configuração pública de funcionamento. Não guarda respostas de API, sessões, tokens ou mídia do servidor. O modo local pode continuar sem internet depois de o shell ser carregado e instalado; o modo com conta não implementa fila offline nem sincronização automática. Em uma nova versão, reabra o app para atualizar o shell. O botão de instalar orienta os navegadores sem prompt; no Safari iOS, use Compartilhar → Adicionar à Tela de Início. A prévia embutida pode não permitir instalação.

## Desenvolver

```bash
pnpm install --frozen-lockfile
pnpm dev:static
pnpm check
pnpm test
pnpm build:static
```

O build termina em `dist/public/`, incluindo service worker versionado com assets locais. A configuração de desenvolvimento usa porta 3000. Os arquivos do template Node presentes no código-fonte não são necessários nem executados na implantação PHP. `backend-php/tests/integration.php` é apenas CLI e não deve ser publicado.

## Saúde, escala e responsabilidade

A escala vai de **-3 Depressão → -2 Muito baixo → -1 Baixo → 0 Estável → +1 Elevado → +2 Muito elevado → +3 Euforia**. É uma escala editorial subjetiva de observação, não um instrumento clínico validado. Os níveis elevados ajudam a descrever experiências relacionadas à elevação do humor; não classificam episódios de hipomania ou mania. Combine os significados com sua psicóloga. Sintomas mistos são separados da escala. [Fundamentação e fontes](REFERENCIAS-E-ESCALA.md).

O app não diagnostica, não ajusta medicação, não é monitorado e não envia alertas de crise. Inclui CVV 188 para apoio emocional e SAMU 192 para emergência no Brasil. Privacidade e segurança técnicas não significam, por si só, conformidade integral com LGPD; uma implantação para outras pessoas requer definição de base legal, retenção, responsável e avaliação jurídica.

**Recomendação de infraestrutura:** começar no cPanel é razoável para uso pessoal ou pequeno, desde que o PHP e o banco sejam suportados, HTTPS esteja ativo e haja backups protegidos. MySQL 5.7 é compatível com o esquema, mas legado: atualizar o banco é mais urgente do que contratar VPS automaticamente. Vídeos, criptografia e backups tendem a pressionar disco, banda e memória; migre com base em métricas, não numa capacidade de usuários inventada. Detalhes no guia de implantação.

## Novidades da versão 1.1

A página **Relatórios** prepara documentos diários, semanais, mensais ou de intervalo personalizado. Você pode baixar HTML, imprimir/salvar em PDF pelo navegador ou compartilhar pelo menu do dispositivo, conforme a compatibilidade. A prévia usa somente registros pessoais. Notas e detalhes de saúde ficam desativados por padrão; mídias nunca entram no relatório. Antes de compartilhar, a interface exige revisão e consentimento. E-mail manual e programação automática exigem conta, SMTP e cron configurados. Uma alteração de destinatários pausa os envios automáticos até nova autorização.

Os envios automáticos podem ser diários, semanais, mensais ou a cada 1–90 dias. Você escolhe horário, fuso, pessoas e conteúdo. O relatório diário cobre o dia anterior; o semanal, os sete dias anteriores; o mensal, o mês calendário anterior; o personalizado, o bloco anterior de dias escolhido. Não há um link público para seu diário. E-mails são enviados separadamente para cada pessoa. E-mail não é criptografia de ponta a ponta, e pausar a programação não apaga documentos que já foram recebidos.

Em **Configurações → Lembretes no seu ritmo**, habilite opcionalmente manhã, tarde, noite e até seis horários adicionais, com dias da semana, fuso, silêncio e opção de não lembrar períodos já anotados. Avisos dentro do aplicativo funcionam apenas com o app aberto e visível. Push pode aparecer com o aplicativo fechado quando o servidor, o navegador e o sistema permitirem; no iPhone/iPad, requer instalação na Tela de Início e iOS 16.4+. E-mail de lembrete usa apenas seu endereço escolhido, nunca a lista de destinatários de relatórios.

Todos os canais vêm desligados. O texto dos lembretes não inclui humor, notas ou diagnóstico, mas o sistema pode mostrar o nome do aplicativo. O cron e os serviços podem atrasar; não são alarmes de emergência. Uma preferência salva não comprova execução ou entrega. O histórico informa fila, aceitação pelo provedor, falha ou resultado inconclusivo. Não repetimos automaticamente uma tentativa cujo resultado não tenha sido confirmado.

**Para atualizar uma instalação existente, faça backup e aplique somente `migration-v2.sql`, sem sobrescrever seu `config.php` privado.** O novo pacote inclui bibliotecas PHPMailer e Web Push e requer PHP 8.2 ou superior. [Configuração de relatórios e notificações](RELATORIOS-E-NOTIFICACOES.md).
