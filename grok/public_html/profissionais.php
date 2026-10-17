<?php
require __DIR__ . '/_boot.php';
// V4.7: com o módulo privado instalado (bipolar.app.br), a página completa (planos e formulário) vem dele.
if (ext_page('profissionais')) return;
page_head('Para profissionais de saúde', 'marketing', [
    'og' => true, 'canonical' => '/profissionais.php',
    'description' => 'Diário de humor gratuito e de código aberto para pacientes com transtorno bipolar: life-chart, alertas e relatórios que o paciente envia para quem cuida dele.',
]); ?>
<section class="mk-hero card">
  <p class="eyebrow">Para psiquiatras, psicólogos, clínicas e CAPS</p>
  <h1>O que acontece entre as consultas, com o consentimento do paciente</h1>
  <p class="lead">O Bipolar é um diário de humor gratuito e de código aberto, inspirado no NIMH Life Chart Method. Indique para seus pacientes: eles registram em menos de um minuto por dia e enviam a você, se quiserem, um relatório periódico por e-mail.</p>
  <p class="mk-cta"><a class="btn btn-primary btn-lg" href="/comecar.php">Conhecer o app</a></p>
</section>

<section class="card" aria-labelledby="problema">
  <h2 id="problema">O problema que todo mundo conhece</h2>
  <div class="grid2">
    <div><h3>O gráfico de papel não volta</h3><p>A folha de humor se perde na bolsa, fica em branco por semanas e é preenchida de memória na sala de espera.</p></div>
    <div><h3>A memória falha, e não é culpa de ninguém</h3><p>Em 30 ou 60 dias entre consultas, noites mal dormidas e dias acelerados viram um “acho que foi tudo bem”. Justamente os sinais que mais importam.</p></div>
    <div><h3>A virada chega antes da consulta</h3><p>Sono encurtando, energia subindo, gastos: os primeiros sinais de (hipo)mania ou depressão costumam aparecer dias antes de alguém perceber.</p></div>
  </div>
</section>

<section class="card" aria-labelledby="recursos">
  <h2 id="recursos">O que o app faz</h2>
  <ul class="features">
    <li><span aria-hidden="true">📈</span> <strong>Life-chart:</strong> humor em 7 níveis por manhã, tarde e noite, com sono, energia, irritabilidade, ansiedade e medicação, numa linha do tempo e num calendário.</li>
    <li><span aria-hidden="true">🔔</span> <strong>Alertas automáticos e personalizados:</strong> o app compara cada pessoa com o próprio padrão das últimas semanas e avisa quando sinais se combinam. Regras próprias também podem ser criadas.</li>
    <li><span aria-hidden="true">🧾</span> <strong>Relatórios:</strong> resumo para imprimir ou salvar em PDF e envio periódico por e-mail para quem o paciente autorizar.</li>
    <li><span aria-hidden="true">🔒</span> <strong>Privacidade de verdade:</strong> sem anúncios, sem rastreadores, módulos sensíveis desligados por padrão e trancáveis com PIN ou biometria.</li>
    <li><span aria-hidden="true">🛡️</span> <strong>Verificação em duas etapas (MFA)</strong> e login com passkey.</li>
    <li><span aria-hidden="true">🧩</span> <strong>Código aberto (AGPL):</strong> qualquer pessoa pode auditar o que o app faz com os dados.</li>
  </ul>
</section>

<section class="card" aria-labelledby="compartilhar">
  <h2 id="compartilhar">Como funciona o compartilhamento</h2>
  <ol class="steps">
    <li><strong>O paciente decide.</strong> Ele convida o(a) profissional pelo próprio app. Sem convite aceito, nada é compartilhado.</li>
    <li><strong>Escolhe o que mostrar.</strong> Humor, sono e marcadores entram no resumo. Dos módulos sensíveis (sexualidade e substâncias) e dos alertas, o profissional recebe <strong>só contagens</strong>, e apenas se o paciente marcar cada opção.</li>
    <li><strong>Pode desfazer a qualquer momento.</strong> Revogar o acesso é um clique,.</li>
  </ol>
  <p class="small">Anotações livres só vão se o paciente marcar essa opção para aquele destinatário. Áudios e vídeos nunca são enviados.</p>
</section>

<section class="card prose" aria-labelledby="faq">
  <h2 id="faq">Perguntas frequentes</h2>
  <details><summary>O app faz diagnóstico?</summary><p><strong>Não.</strong> O Bipolar não é dispositivo médico, não diagnostica, não indica tratamento e não substitui o julgamento clínico. Ele organiza o que o paciente registra e aponta padrões para conversar na consulta.</p></details>
  <details><summary>Por que código aberto?</summary><p>Porque saúde mental pede confiança. Com o código aberto (licença AGPL), qualquer pessoa pode verificar o que acontece com os dados. O que se paga é a hospedagem, a manutenção, os backups e o suporte.</p></details>
</section>
<?php page_foot();
