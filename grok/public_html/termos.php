<?php
require __DIR__ . '/_boot.php';
$controller = trim((string)cfg('legal.controller', '')) ?: 'a pessoa responsável pelo site bipolar.app.br';
$contact = trim((string)cfg('legal.contact_email', '')) ?: 'privacidade@bipolar.app.br';
$repo = trim((string)cfg('legal.source_url', '')) ?: 'https://github.com/';
page_head('Termos de Uso'); ?>
<article class="card narrow prose">
<h1>Termos de Uso</h1>
<p><em>Versão V4.8, novembro de 2026.</em> Ao criar uma conta, você concorda com estes termos e com a <a href="/privacidade.php">Política de Privacidade</a>.</p>

<section class="plain" aria-labelledby="simples">
<h2 id="simples">Em linguagem simples</h2>
<ul>
<li>É um diário gratuito para você acompanhar o seu humor. <strong>Não é médico</strong> e não substitui a sua equipe de saúde.</li>
<li>Os alertas e a IA são sinais para conversar com quem cuida de você, não diagnósticos. Nunca mude remédio ou dose por causa do app.</li>
<li>Em crise, ligue <a href="tel:188">CVV 188</a> ou <a href="tel:192">SAMU 192</a>. O app não monitora você em tempo real e ninguém é avisado automaticamente, a não ser as pessoas que você cadastrar, do jeito que você escolher.</li>
<li>Você pode compartilhar seus registros, só para leitura, com um profissional de saúde que aceitar. Você escolhe o quê e até quando, e encerra quando quiser.</li>
<li>O código é aberto (AGPL-3.0). Você pode conferir, copiar e rodar a sua própria cópia.</li>
<li>Seus dados são seus: baixe ou apague quando quiser.</li>
</ul>
</section>

<h2>1. Quem oferece</h2>
<p>O serviço em bipolar.app.br é oferecido por <?= e($controller) ?>, sem fins comerciais. Contato: <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a>.</p>

<h2>2. O que o app é e o que não é</h2>
<p>É um diário pessoal de humor inspirado no NIMH Life Chart Method, com gráficos, lembretes, alertas automáticos, relatórios para pessoas de apoio e recursos opcionais de IA. <strong>Não é um dispositivo médico</strong>, não faz diagnóstico, não prescreve tratamento e não é serviço de emergência.</p>

<h2>3. Sua conta</h2>
<ul>
<li>Você precisa ter 18 anos ou mais.</li>
<li>Use dados verdadeiros, guarde bem sua senha e cadastre biometria ou PIN se quiser proteger as seções privadas.</li>
<li>Recomendamos ativar a <strong>verificação em duas etapas</strong> (código do aplicativo autenticador, em Conta). Guarde os <strong>códigos de recuperação</strong> em lugar seguro: são eles que permitem entrar se você perder o celular. Cada código vale uma vez. Quem entra com Google, Microsoft ou X ativa a verificação em duas etapas na conta do próprio provedor.</li>
<li>Só cadastre como pessoa de apoio quem concordar em receber seus relatórios; ela precisa confirmar o convite.</li>
</ul>

<h2>3A. Compartilhamento com profissionais de saúde</h2>
<ul>
<li><strong>Paciente:</strong> você convida pelo e-mail do profissional ou pelo código que ele gerar. Nada é visto antes de ele aceitar. Você escolhe as categorias (as sensíveis começam desligadas), a data inicial e a data final, muda tudo quando quiser e encerra a qualquer momento: o acesso para na hora (revogação do consentimento). Em Conta você vê quem viu o quê e quando.</li>
<li><strong>Profissional:</strong> a marcação “Profissional de saúde” e o CRP/CRM são <strong>autodeclarados</strong> e aparecem para o paciente como declarados, sem verificação pelo app. O acesso é só leitura, apenas ao que o paciente liberou e pelo prazo dele. É proibido tentar acessar dados de quem não compartilhou, copiar dados para fins alheios ao cuidado ou compartilhá-los com terceiros. O profissional responde pelo sigilo profissional e pela LGPD quanto ao que vê.</li>
<li>O app não verifica identidade nem registro profissional e não é prontuário eletrônico. O link de convite é pessoal, de uso único e só funciona com a conta do e-mail convidado.</li>
</ul>

<h2>4. IA com a sua chave</h2>
<p>Se você ativar a IA, usa a sua própria chave de um provedor (xAI, OpenAI, Anthropic, Google, DeepSeek, Mistral, OpenRouter ou outro compatível). O uso é cobrado pelo provedor na sua conta de lá e segue os termos dele. Respostas de IA podem estar erradas.</p>

<h2>5. Uso aceitável</h2>
<p>Não tente acessar dados de outras pessoas, sobrecarregar o servidor, burlar limites de segurança nem usar o app para enviar spam a terceiros. Falhas de segurança devem ser comunicadas como descrito no arquivo SECURITY.md do repositório (<a href="<?= e($repo) ?>" rel="noopener"><?= e($repo) ?></a>).</p>

<h2>6. Disponibilidade e responsabilidade</h2>
<p>O serviço é oferecido “como está”, em hospedagem compartilhada, sem garantia de funcionamento contínuo. Lembretes, alertas e e-mails podem atrasar ou falhar. Faça cópias dos seus dados (Conta → Baixar tudo). Na medida permitida pela lei, não nos responsabilizamos por decisões de saúde tomadas com base no app.</p>

<h2>7. Código aberto</h2>
<p>O código-fonte é licenciado sob a <strong>GNU Affero General Public License v3.0</strong> (AGPL-3.0). Quem rodar uma versão modificada como serviço na internet precisa oferecer o código-fonte dessa versão aos usuários.</p>

<h2>8. Encerramento</h2>
<p>Você pode excluir a conta a qualquer momento em Conta. Podemos encerrar contas usadas para abuso. Se o serviço for encerrado, avisaremos com antecedência razoável para você baixar seus dados.</p>

<h2>9. Mudanças e lei aplicável</h2>
<p>Mudanças importantes serão avisadas no app antes de valer. Aplica-se a lei brasileira, incluindo o Código de Defesa do Consumidor e a LGPD.</p>

<p class="small muted">Este texto foi preparado com cuidado, mas <strong>não é aconselhamento jurídico</strong>. Quem opera o site deve revisá-lo, de preferência com um profissional, antes de publicar.</p>
</article>
<?php page_foot();
