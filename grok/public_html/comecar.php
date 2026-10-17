<?php
require __DIR__ . '/_boot.php';
// V4.7: com o módulo privado instalado (bipolar.app.br), a página completa (com formulário de indicação) vem dele.
if (ext_page('comecar')) return;
page_head('Comece grátis', 'marketing', [
    'og' => true, 'canonical' => '/comecar.php',
    'description' => 'Diário de humor gratuito e de código aberto para quem vive com transtorno bipolar: registre seu dia em menos de um minuto, perceba padrões e leve relatórios para a consulta.',
]); ?>
<section class="mk-hero card">
  <p class="eyebrow">Gratuito para pacientes, para sempre</p>
  <h1>Um lugar tranquilo para cuidar do seu humor</h1>
  <p class="lead">Quem vive com transtorno bipolar sabe: um dia de cada vez. O Bipolar ajuda você a registrar como está, perceber padrões a tempo e chegar à consulta com a sua história na mão.</p>
  <p class="mk-cta"><a class="btn btn-primary btn-lg" href="/conta/cadastro.php">Criar conta grátis</a> <a class="btn btn-lg" href="/">Já tenho conta</a></p>
  <p class="small">Sem anúncios, sem rastreadores, código aberto. Feito por alguém que também convive com o transtorno bipolar.</p>
</section>

<section class="card" aria-labelledby="oque">
  <h2 id="oque">O que você pode fazer</h2>
  <ul class="features">
    <li><span aria-hidden="true">🌤️</span> <strong>Registrar em menos de um minuto</strong> o humor da manhã, da tarde e da noite, além de sono, energia e remédios.</li>
    <li><span aria-hidden="true">📈</span> <strong>Ver sua linha do tempo</strong> e um calendário de cores para entender seus ciclos.</li>
    <li><span aria-hidden="true">🔔</span> <strong>Receber lembretes</strong> e alertas gentis quando seus sinais mudam em relação ao seu próprio padrão.</li>
    <li><span aria-hidden="true">🧾</span> <strong>Levar um relatório</strong> para o psiquiatra ou psicólogo, impresso, em PDF ou por e-mail.</li>
    <li><span aria-hidden="true">🔒</span> <strong>Manter tudo seu:</strong> módulos íntimos vêm desligados, você pode trancar com PIN ou biometria, exportar ou apagar quando quiser.</li>
    <li><span aria-hidden="true">📱</span> <strong>Instalar como app</strong> no celular, e funciona até sem internet.</li>
  </ul>
  <p class="small">O Bipolar é um diário. Ele não faz diagnóstico e não substitui o seu tratamento.</p>
</section>

<section class="card" id="convidar" aria-labelledby="convidar-t">
  <h2 id="convidar-t">Quer levar para quem cuida de você?</h2>
  <p>Você não precisa de nada além da sua conta: em <strong>Relatórios</strong>, cadastre o e-mail do seu psiquiatra ou psicólogo(a). A pessoa confirma o convite e passa a receber o resumo que <strong>você</strong> escolher, na frequência que você definir. Também dá para imprimir ou salvar em PDF e levar na consulta.</p>
  <p><a class="btn btn-primary" href="/conta/cadastro.php">Criar conta grátis</a></p>
</section>
<p class="center"><a href="/profissionais.php">Você é profissional de saúde? Veja como o app ajuda no acompanhamento</a></p>
<?php page_foot();
