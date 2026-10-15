<?php
function page_head(string $title, string $bodyClass = ''): void { ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> · Bipolar</title>
<meta name="description" content="Diário de humor para pessoas com transtorno bipolar, inspirado no NIMH Life Chart Method.">
<meta name="theme-color" content="#2f6f73" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#14232b" media="(prefers-color-scheme: dark)">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/assets/icons/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="stylesheet" href="/assets/css/app.css?v=9">
<script src="/assets/js/theme.js?v=1"></script>
</head>
<body class="<?= e($bodyClass) ?>">
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
<header class="topbar">
  <a class="brand" href="/" aria-label="Bipolar — início"><img src="/assets/icons/logo.svg" alt="" width="32" height="32"><span>Bipolar</span></a>
  <div class="topbar-actions">
    <button type="button" class="btn btn-ghost hidden" id="btn-install">Instalar app</button>
    <button type="button" class="btn btn-ghost icon-btn" id="btn-theme" aria-label="Alternar tema claro/escuro" aria-pressed="false"><span aria-hidden="true">◐</span></button>
  </div>
</header>
<aside class="crisis" aria-label="Ajuda em crise">
  <strong>Precisa de ajuda agora?</strong>
  <a href="tel:188">CVV 188</a> (24h, gratuito) ·
  <a href="tel:192">SAMU 192</a> ·
  procure o <abbr title="Centro de Atenção Psicossocial">CAPS</abbr> mais próximo ou uma UPA.
</aside>
<main id="conteudo" tabindex="-1">
<?php }
function page_foot(): void { ?>
</main>
<footer class="footer">
  <p>Este aplicativo é um diário pessoal e <strong>não substitui</strong> diagnóstico, tratamento ou acompanhamento com psiquiatra e psicólogo.</p>
  <p><a href="/privacidade.php">Política de privacidade (LGPD)</a> · <a href="/termos.php">Termos de uso</a> · <a href="/escala.php">Sobre a escala</a></p>
</footer>
<div id="sr-live" class="sr-only" aria-live="polite" aria-atomic="true"></div>
<script src="/assets/js/pwa.js?v=1"></script>
</body>
</html>
<?php }
/**
 * Campo de data em formato brasileiro: texto dd/mm/aaaa (visível, acessível) + input date nativo
 * escondido que guarda o valor ISO enviado ao servidor e abre o calendário próprio em pt-BR pelo botão.
 */
function date_field_br(string $id, string $name, string $value = ''): string {
    return '<div class="date-br">'
        . '<input type="text" id="' . e($id) . '-br" inputmode="numeric" autocomplete="off" placeholder="dd/mm/aaaa" maxlength="10" pattern="\d{2}/\d{2}/\d{4}" required aria-describedby="' . e($id) . '-err">'
        . '<button type="button" class="btn btn-ghost date-pick" data-for="' . e($id) . '" aria-label="Abrir calendário"><span aria-hidden="true">📅</span></button>'
        . '<input type="date" lang="pt-BR" id="' . e($id) . '" name="' . e($name) . '" class="date-native" tabindex="-1" aria-hidden="true" value="' . e($value) . '">'
        . '</div><p class="field-err small" id="' . e($id) . '-err" aria-live="polite"></p>';
}
