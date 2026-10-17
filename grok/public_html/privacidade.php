<?php
require __DIR__ . '/_boot.php';
require_once APP_ROOT . '/lib/oauth.php';
require_once APP_ROOT . '/lib/ai_providers.php';
$provNames = array_values(array_map(function (array $p): string { return (string)$p['label']; }, oauth_enabled_providers()));
$loginOpts = 'e-mail e senha' . ($provNames ? ' ou conta ' . implode(', ', array_slice($provNames, 0, -1)) . (count($provNames) > 1 ? ' ou ' : '') . end($provNames) : '');
$controller = trim((string)cfg('legal.controller', '')) ?: 'a pessoa responsável pelo site bipolar.app.br';
$contact = trim((string)cfg('legal.contact_email', '')) ?: 'privacidade@bipolar.app.br';
$hosting = trim((string)cfg('legal.hosting', '')) ?: 'uma empresa de hospedagem compartilhada (cPanel)';
$repo = trim((string)cfg('legal.source_url', '')) ?: 'https://github.com/';
$aiNames = implode(', ', array_map(function (array $p): string { return $p['label']; }, array_filter(ai_providers(), function (string $k): bool { return $k !== 'custom'; }, ARRAY_FILTER_USE_KEY)));
page_head('Política de Privacidade'); ?>
<article class="card narrow prose">
<h1>Política de Privacidade</h1>
<p><em>Versão V4.8, novembro de 2026.</em> Esta política segue a Lei Geral de Proteção de Dados (Lei nº 13.709/2018, LGPD). Leia também os <a href="/termos.php">Termos de Uso</a>.</p>

<section class="plain" aria-labelledby="simples">
<h2 id="simples">Em linguagem simples</h2>
<p>Este é um diário de humor. Você conta aqui coisas muito íntimas: como dormiu, como está a cabeça e, se quiser, até <strong>sexo, drogas e rock and roll</strong> (bom, o rock and roll a gente não registra… ainda). Por isso, combinamos o seguinte:</p>
<ul>
<li><strong>Não existe painel de administração.</strong> Não há tela, rota ou “modo chefe” para ninguém ver os registros de outras pessoas, nem o mantenedor. O código é aberto: qualquer pessoa pode conferir isso em <a href="<?= e($repo) ?>" rel="noopener"><?= e($repo) ?></a>.</li>
<li><strong>Sexualidade, Substâncias e Pensamentos de morte vêm desligados.</strong> Só aparecem se você ligar, e podem ficar trancadas com <strong>biometria ou PIN</strong> (quando você cadastra um dos dois).</li>
<li><strong>Notificações e e-mails de aviso nunca contam o que você registrou.</strong> Eles dizem só algo como “há um novo aviso”; os detalhes ficam dentro do app.</li>
<li><strong>Quem você escolher como apoio só recebe o que você autorizar.</strong> Dos alertas, a pessoa recebe apenas a <strong>quantidade</strong>, e só depois de você marcar essa opção e ela confirmar o convite.</li>
<li><strong>IA é opcional e só com o seu “sim”.</strong> Você escolhe o provedor (<?= e($aiNames) ?> ou outro), usa a <strong>sua própria chave</strong> e escolhe o que pode ser enviado. Vai só o resumo das categorias marcadas, sem seu nome completo nem e-mail. Anotações, alimentação, sexualidade e substâncias ficam de fora, a menos que você marque.</li>
<li><strong>Profissional de saúde só vê o que você liberar.</strong> Você convida, escolhe as categorias e o prazo, encerra quando quiser (vale na hora) e vê quem viu o quê e quando. O profissional só lê; não edita nem baixa seus dados brutos.</li>
<li><strong>Ninguém é avisado automaticamente sobre pensamentos de morte.</strong> Ao registrar, aparece só para você um painel de apoio (CVV 188, SAMU 192 e seu contato de confiança). Aviso só existe se você mesmo(a) criar um alerta com esse item.</li>
<li><strong>É tudo seu.</strong> Em Conta você baixa tudo (ZIP com dados e mídias) ou apaga a conta inteira, na hora.</li>
<li><strong>Sendo honestos sobre os limites:</strong> o site roda em hospedagem <strong>compartilhada</strong> (cPanel). Quem opera o servidor tem, tecnicamente, acesso ao banco de dados e aos arquivos, e os registros não são cifrados de ponta a ponta. Prometemos não olhar, e não existe ferramenta para isso, mas é uma promessa, não uma impossibilidade técnica. Se isso não serve para você, tudo bem: o código é aberto e você pode rodar a sua própria cópia.</li>
</ul>
</section>

<h2>1. Quem é o controlador</h2>
<p>O controlador dos dados é <?= e($controller) ?>. Contato para qualquer assunto de privacidade, inclusive como encarregado (DPO): <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a>.</p>

<h2>2. Quais dados tratamos</h2>
<ul>
<li><strong>Cadastro:</strong> nome, e-mail, fuso horário e preferências. Você entra com <?= e($loginOpts) ?>. Da senha guardamos apenas um <em>hash</em> irreversível (Argon2id ou bcrypt); ninguém consegue ver sua senha.<?php if ($provNames): ?> Ao entrar com <?= e(implode(' ou ', $provNames)) ?>, recebemos nome, e-mail (quando o provedor fornece) e um identificador da conta, nunca a senha desse provedor.<?php endif; ?></li>
<li><strong>Dados de saúde (dados pessoais sensíveis, art. 5º, II e art. 11):</strong> humor por período, sono, medicação, energia, irritabilidade, ansiedade, anotações, etiquetas, marcadores diários que você ativar (horários de sono, sol, cafeína, álcool, exercício, gastos, ciclo menstrual, doses, sinais de alerta pessoais), acontecimentos e estresse <strong>por período do dia</strong> e, se você ativar, alimentação por período (doces, industrializados/ultraprocessados e se comeu fora de hora) e, se você quiser, áudios, vídeos e imagens.</li>
<li id="alimentacao"><strong>Alimentação (opcional):</strong> doces, industrializados/ultraprocessados (de “nenhum” a “muito”) e se você comeu fora de hora ou teve compulsão, por período do dia. Pode ser desligada em Conta → Módulos. Não aparece em lembretes. Em relatórios, só para quem você autorizar e apenas como contagem de dias. Só vai para a IA se você marcar a categoria “Alimentação”, que vem desmarcada.</li>
<li id="pensamentos"><strong>Pensamentos por período (opcional):</strong> positivos/esperançosos, negativos/pessimistas, acelerados, grandiosidade/missão especial e ruminação/culpa, de “nenhum” a “intenso”. Pode ser desligado em Conta → Módulos. Só vai para a IA se você marcar a categoria “Pensamentos”, que vem desmarcada.</li>
<li id="pensamentos-morte"><strong>Pensamentos de morte ou suicídio (opcional, dado sensível de saúde):</strong> só com o módulo próprio ligado (vem desligado), atrás do mesmo PIN/biometria das seções privadas. Ao salvar um nível leve ou maior, o app mostra só para você um painel de apoio com CVV 188, SAMU 192 e o contato de confiança que você cadastrar (o nome e o telefone desse contato ficam guardados nas suas preferências). <strong>Ninguém é notificado automaticamente</strong>; só há aviso se você criar um alerta personalizado com esse item, e o aviso vai para você, com texto genérico. Nunca vai para a IA sem a categoria própria marcada; só entra em relatório se você marcar na impressão ou para um destinatário específico, e mesmo assim só como contagem de dias.</li>
<li id="nicotina"><strong>Nicotina (opcional, no módulo Hábitos, junto da cafeína):</strong> número de cigarros no dia e outra forma de nicotina (vape/pod, palha, charuto/cachimbo, narguilé, adesivo/chiclete). Aparece no Histórico, nas exportações e no resumo de hábitos dos relatórios.</li>
<li id="substancias"><strong>Uso de substâncias (opcional):</strong> só com o módulo <em>Substâncias</em> ligado em Conta, com foco em redução de danos e sem julgamento. Nunca aparece em lembretes. Em relatórios, só para quem você autorizar individualmente e apenas como resumo (dias com uso por tipo e dias acima do habitual), sem quantidades, motivos ou horários.</li>
<li id="sexualidade"><strong>Vida sexual (opcional):</strong> só com o módulo <em>Sexualidade</em> ligado. Ele existe porque mudanças no desejo e no comportamento sexual estão entre os sinais precoces mais frequentes de hipomania e mania (DSM-5; Kopeykina et al., <em>J Affect Disord</em> 2016; Dolman et al., <em>Lancet Psychiatry</em> 2024). Nunca aparece em lembretes e, em relatórios, só para quem você autorizar e apenas como resumo (média do desejo e número de dias com sinais de risco), sem atividades nem anotações. Você pode apagar esses registros a qualquer momento, sem excluir a conta.</li>
<li><strong>Alertas:</strong> os avisos que o app calcula a partir dos seus dados e as regras de alerta que você cria.</li>
<li id="profissionais"><strong>Compartilhamento com profissionais:</strong> se você convidar, guardamos o e-mail convidado (ou a ligação com a conta do profissional), as categorias e datas liberadas, o estado do convite e um <strong>registro de acessos</strong> (quem viu, o quê, quando e qual período), que você vê em Conta. O link de convite é guardado só como <em>hash</em>, vale uma vez e por 14 dias. Do profissional guardamos o perfil autodeclarado (profissão e CRP/CRM informados, <strong>não verificados</strong>).</li>
<li><strong>Pessoas de apoio:</strong> nome e e-mail de quem você cadastrar para receber relatórios, e o que cada uma pode ver.</li>
<li><strong>Biometria (passkeys):</strong> sua digital ou rosto nunca saem do aparelho. Guardamos apenas a chave pública, um contador e o nome que você deu ao aparelho.</li>
<li><strong>Notificações push:</strong> o endereço de entrega do navegador e as chaves para cifrar a mensagem.</li>
<li><strong>Chave de IA (opcional):</strong> o provedor, o modelo e a sua chave de API, guardada cifrada.</li>
<li><strong>Verificação em duas etapas (opcional, contas com e-mail e senha):</strong> a chave do app autenticador, guardada cifrada, o número do último código aceito (para impedir que o mesmo código seja usado duas vezes) e os 10 códigos de recuperação, guardados só como <em>hash</em>. Quando você ativa ou desativa, enviamos um aviso por e-mail, sem a chave e sem códigos. Quem entra com uma conta externa usa a verificação em duas etapas do próprio provedor.</li>
<li><strong>Dados técnicos:</strong> cookie de sessão; contadores de tentativas (para limitar abusos), guardados como <em>hash</em> do endereço IP e apagados em até 1 dia.</li>
</ul>

<h2>3. Para que usamos e com qual base legal</h2>
<p>Usamos os dados <strong>só</strong> para oferecer o diário a você: mostrar gráficos e histórico, calcular alertas, enviar lembretes, gerar relatórios e enviá-los às pessoas que você cadastrar e que confirmarem, e, se você ativar, os recursos de IA.</p>
<ul>
<li><strong>Dados de saúde, sexualidade e substâncias:</strong> seu <strong>consentimento</strong> específico e destacado (art. 11, I), dado ao criar a conta e, para os módulos opcionais, relatórios e IA, em cada ativação. Você pode revogar a qualquer momento (art. 8º, §5º), desligando o recurso ou excluindo a conta.</li>
<li><strong>Compartilhamento com profissional de saúde:</strong> seu <strong>consentimento</strong> específico (art. 11, I), dado ao criar cada convite, por categoria e por prazo. A <strong>revogação</strong> (art. 8º, §5º) é feita em Conta → Compartilhar com profissional → Encerrar, vale na hora, e o profissional perde o acesso imediatamente. O registro de acessos continua disponível para você como prova de quem viu o quê.</li>
<li><strong>Cadastro e segurança (login, limite de tentativas):</strong> execução do serviço que você pediu (art. 7º, V) e legítimo interesse em proteger as contas (art. 7º, IX).</li>
</ul>
<p>Não vendemos dados, não temos anúncios, não usamos ferramentas de análise de terceiros e não usamos seus dados para treinar modelos de IA.</p>

<h2>4. Onde ficam e como protegemos</h2>
<ul>
<li>O site fica em <?= e($hosting) ?>, com conexão sempre cifrada (HTTPS). Áudios, vídeos e imagens ficam fora da área pública do site e só são entregues a você, depois do login.</li>
<?php $xfer = (array)cfg('legal.international_transfer', []); if (!empty($xfer['enabled'])): ?>
<li id="transferencia-internacional"><strong>Transferência internacional de dados (LGPD, art. 33).</strong> Os dados das instalações para profissionais e clínicas (<em>nome</em>.bipolar.app.br), inclusive dados de saúde, ficam em servidor localizado em <?= e((string)($xfer['country'] ?? 'outro país')) ?><?= !empty($xfer['provider']) ? ' (' . e((string)$xfer['provider']) . ')' : '' ?>, e as cópias de segurança cifradas<?= !empty($xfer['backup_provider']) ? ' em ' . e((string)$xfer['backup_provider']) : '' ?>. Essa transferência é feita com base em <?= e((string)($xfer['mechanism'] ?? 'cláusulas-padrão contratuais')) ?>, com criptografia em trânsito e nas cópias de segurança, isolamento por instalação e acesso restrito. Você pode pedir uma cópia das cláusulas e mais informações pelo contato de privacidade abaixo.</li>
<?php endif; ?>
<li><strong>O que é cifrado:</strong> a conexão (HTTPS), as notificações push (de ponta a ponta, e sempre com texto genérico) a sua chave de IA e a chave da verificação em duas etapas (libsodium, com chaves do servidor). Senha, PIN e códigos de recuperação são guardados só como <em>hash</em>.</li>
<li><strong>O que não é cifrado no servidor:</strong> os registros, anotações e mídias ficam no banco de dados e no disco do servidor sem cifra própria. Por isso, quem opera o servidor ou a hospedagem tem acesso técnico a eles.</li>
<li><strong>Registros técnicos (logs):</strong> o app não registra o conteúdo do que você escreve. Os logs guardam só erros técnicos (por exemplo, falha de envio de e-mail com o endereço mascarado, ou o código de erro do provedor de IA sem a sua chave e sem o que foi enviado) e a data e os contadores das tarefas automáticas. A hospedagem também mantém os registros de acesso do servidor web (endereço IP, data e página), conforme a política dela.</li>
<li>Não há área de administração. As tarefas automáticas (alertas, lembretes, relatórios) rodam no servidor sem tela, e a situação delas existe só em arquivos internos, sem dados pessoais.</li>
</ul>

<h2>5. Com quem compartilhamos</h2>
<ul>
<li><strong>Pessoas de apoio que você cadastrar:</strong> recebem por e-mail o relatório com o que você autorizou para cada uma, só depois de confirmar o convite, e podem cancelar pelo link em cada envio. Áudios e vídeos nunca vão por e-mail. Dos alertas, só a quantidade.</li>
<li><strong>Profissionais de saúde que você convidar e que aceitarem:</strong> veem, só para leitura e dentro do app, as categorias e o período que você liberou, sem anotações, sem mídias e sem exportação de dados brutos (podem imprimir ou salvar em PDF a mesma visão). Categorias sensíveis (substâncias, sexualidade, pensamentos de morte) começam desligadas e só são liberadas por você, com o PIN. A partir do momento em que o profissional vê os dados, ele também passa a ser responsável por eles, conforme o sigilo profissional e a LGPD. O controlador do app continua sendo o indicado no item 1.</li>
<li><strong>Serviço de e-mail</strong> usado pelo site, para entregar lembretes, avisos (sempre genéricos), confirmações e os relatórios acima.</li>
<li><strong>Serviços de push</strong> do seu navegador (por exemplo, Google, Apple ou Mozilla): recebem só uma mensagem cifrada e genérica.</li>
<li><strong>Provedor de IA que você escolher</strong> (veja o item 6).</li>
<li>Autoridades, apenas se houver obrigação legal ou ordem judicial.</li>
</ul>

<h2 id="ia">6. Inteligência artificial (opcional)</h2>
<p>Os recursos de IA (resumo da semana, explicação de alertas, preparo para consulta e check-in por conversa) ficam <strong>desligados</strong> até você escolher um provedor, salvar a sua chave de API e consentir.</p>
<ul>
<li>A cada uso, enviamos ao provedor que <strong>você</strong> escolheu (<?= e($aiNames) ?>, ou um endereço compatível que você informar) apenas as categorias que você marcou, com seu primeiro nome no máximo, sem e-mail. Anotações, sexualidade e substâncias ficam de fora, a menos que você as marque.</li>
<li>Esses dados passam a ser tratados também <strong>conforme os termos e a política de privacidade desse provedor</strong>, que geralmente fica fora do Brasil (por exemplo, nos EUA ou na China). O app mostra o link dos termos de cada provedor em Conta. Com a sua chave, a relação de cobrança é entre você e o provedor.</li>
<li>Se você escrever algo que indique risco, o app pausa a conversa e mostra CVV 188 e SAMU 192, sem enviar o texto à IA.</li>
<li>Guardamos apenas o número de usos e de tokens. Sua chave fica cifrada, nunca é mostrada de novo (só os 4 últimos caracteres) e nunca vai para os logs. Você pode trocar ou remover a chave e revogar o consentimento a qualquer momento.</li>
</ul>

<h2>7. Transferência internacional</h2>
<p>Pode haver transferência de dados para fora do Brasil quando: (a) você ativa a IA, para o país do provedor escolhido; (b) a mensagem push passa pelo serviço do seu navegador (sempre cifrada e genérica); (c) a hospedagem ou o serviço de e-mail usa servidores no exterior. No caso da IA, a base é o seu consentimento específico e destacado, informado sobre o caráter internacional (art. 33, VIII).</p>

<h2>8. Cookies e armazenamento no aparelho</h2>
<ul>
<li>Usamos <strong>um único cookie</strong>, essencial, de sessão (<code>bpsid</code>), para manter você conectado. Ele some ao fechar o navegador ou ao sair. Não usamos cookies de publicidade nem de análise.</li>
<li>No próprio aparelho, o app guarda: o tema (claro/escuro), registros feitos sem internet até serem enviados, preferências de avisos e uma cópia da página inicial para funcionar offline. Em aparelho compartilhado, use “Sair” e, se quiser, limpe os dados do site no navegador.</li>
</ul>

<h2>9. Por quanto tempo guardamos</h2>
<ul>
<li>Seus dados ficam guardados enquanto a conta existir. Não apagamos contas inativas automaticamente.</li>
<li>Ao <strong>excluir a conta</strong>, apagamos na hora a conta e tudo ligado a ela: registros, marcadores, sexualidade, substâncias, mídias (os arquivos também), alertas e regras de alerta, pessoas de apoio, lembretes, biometria, verificação em duas etapas e códigos de recuperação, notificações, chave e uso da IA.</li>
<li>Cópias de segurança feitas pela hospedagem podem manter os dados por um período limitado, até serem sobrescritas, conforme a política da hospedagem.</li>
<li>Contadores de tentativas: até 1 dia. Links de confirmação e redefinição de senha: expiram em poucas horas ou dias.</li>
</ul>

<?= ext_partial('privacidade-contatos', ['contact' => $contact]) // V4.7: só existe quando o módulo privado (pedidos de contato) está instalado ?>

<h2>10. Seus direitos (art. 18)</h2>
<p>A qualquer momento, em <strong>Conta</strong>, você pode: confirmar e acessar seus dados; <strong>baixar tudo</strong> (ZIP com os dados em JSON e CSV e todas as mídias, ou só o JSON ou o CSV); corrigir informações; desligar módulos e apagar seus registros de sexualidade e substâncias; remover pessoas de apoio; revogar a IA e remover a chave; e <strong>excluir a conta</strong>. Para qualquer outro pedido (informação sobre compartilhamento, revisão, portabilidade, oposição), escreva para <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a>. Você também pode reclamar à ANPD (Autoridade Nacional de Proteção de Dados).</p>

<h2>11. Crianças e adolescentes</h2>
<p>O app é destinado a maiores de 18 anos. Adolescentes só devem usar com acompanhamento e consentimento dos responsáveis e da equipe de saúde.</p>

<h2>12. Mudanças</h2>
<p>Se esta política mudar de forma importante, avisaremos dentro do app antes de a mudança valer. O histórico de versões fica no repositório do código.</p>

<h2>Aviso importante</h2>
<p>Este aplicativo não é um dispositivo médico e não substitui avaliação, diagnóstico ou tratamento profissional. Em crise, ligue <a href="tel:188">CVV 188</a> ou <a href="tel:192">SAMU 192</a>.</p>
<p class="small muted">Este texto foi preparado com cuidado, mas <strong>não é aconselhamento jurídico</strong>. Quem opera o site deve revisá-lo, de preferência com um profissional, antes de publicar.</p>
</article>
<?php page_foot();
