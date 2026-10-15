<?php
// Página 404 com a identidade do app (os cabeçalhos de segurança vêm do _boot.php).
require __DIR__ . '/_boot.php';
http_response_code(404);
header('Cache-Control: no-store');
page_head('Página não encontrada'); ?>
<article class="card narrow">
  <h1>Página não encontrada</h1>
  <p>O endereço que você abriu não existe ou mudou de lugar.</p>
  <p class="row"><a class="btn btn-primary" href="/">Ir para o início</a> <a class="btn btn-ghost" href="/como-funcionam-os-alertas.php">Como funcionam os alertas</a></p>
</article>
<?php page_foot();
