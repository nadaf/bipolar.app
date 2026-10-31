<?php
/** @param array{description?:string,canonical?:string,og?:bool} $meta */
function page_head(string $title, string $bodyClass = '', array $meta = []): void {
    $desc = $meta['description'] ?? 'Diário de humor para pessoas com transtorno bipolar, inspirado no NIMH Life Chart Method.';
    $base = rtrim((string)cfg('app_url', 'https://bipolar.app.br'), '/');
    // V5.1: HTML de quem está logado nunca fica em cache (navegador, proxy ou service worker)
    if (!headers_sent() && !empty($_SESSION['uid'])) header('Cache-Control: no-store, private'); ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php $brandName = trim((string)cfg('brand.name', '')) ?: 'Bipolar'; $brandLogo = trim((string)cfg('brand.logo_url', '')) ?: '/assets/icons/logo.svg'; $brandColor = brand_color(); ?>
<title><?= e($title) ?> · <?= e($brandName) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<?php if (!empty($meta['og'])): $url = $base . ($meta['canonical'] ?? '/'); ?>
<link rel="canonical" href="<?= e($url) ?>">
<meta property="og:type" content="website">
<meta property="og:locale" content="pt_BR">
<meta property="og:site_name" content="Bipolar">
<meta property="og:title" content="<?= e($title) ?> · Bipolar">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($url) ?>">
<meta property="og:image" content="<?= e($base) ?>/assets/og/og-bipolar.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Bipolar: diário de humor gratuito e de código aberto, com blog em blog.bipolar.app.br">
<meta name="twitter:card" content="summary_large_image">
<?php endif; ?>
<meta name="theme-color" content="<?= e($brandColor ?? '#2f6f73') ?>" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#14232b" media="(prefers-color-scheme: dark)">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/assets/icons/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="stylesheet" href="/assets/css/app.css?v=16">
<?php if ($brandColor !== null): ?><link rel="stylesheet" href="/brand.css.php?c=<?= e(substr($brandColor, 1)) ?>"><?php endif; ?>
<script src="/assets/js/theme.js?v=2"></script>
<?php $gaFlash = ga4_take_flash(); if (ga4_active()): // V5.2: GA4 só com aviso de consentimento; nada é enviado antes de aceitar ?>
<meta name="bp-ga4" content="<?= e(ga4_id()) ?>" data-page="<?= e(ga4_generic_title($title)) ?>" data-flash="<?= e($gaFlash) ?>">
<script src="/assets/js/analytics.js?v=2" defer></script>
<?php endif; ?>
</head>
<body class="<?= e($bodyClass) ?>">
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
<header class="topbar">
  <a class="brand" href="/" aria-label="<?= e($brandName) ?> — início"><img src="<?= e($brandLogo) ?>" alt="" width="32" height="32"><span><?= e($brandName) ?></span></a>
  <div class="topbar-actions">
    <button type="button" class="btn btn-ghost hidden" id="btn-install">Instalar app</button>
    <button type="button" class="btn btn-ghost icon-btn" id="btn-theme" data-ga="tema_alternar" aria-label="Tema escuro" aria-pressed="false" title="Usar tema escuro"><span aria-hidden="true">◐</span></button>
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
  <p><a href="/conta/cadastro.php">Criar conta</a> · <a href="https://blog.bipolar.app.br">Blog</a></p>
<?php if (ga4_active()): ?>  <p><button type="button" class="linklike" data-consent-open>Cookies e estatísticas de uso</button></p>
<?php endif; ?>
</footer>
<?php if (ga4_active()): ?>
<section id="consent-banner" class="ga-consent is-hidden" aria-labelledby="consent-title" data-v="2" hidden>
  <h2 id="consent-title">Estatísticas de uso (opcional)</h2>
  <p>Com a sua permissão, usamos o Google Analytics para contar visitas e cliques em botões e melhorar o app. <strong>Seus registros, textos e dados de saúde nunca são enviados.</strong> Você pode mudar de ideia quando quiser. <a href="/privacidade.php#estatisticas">Saiba mais na Política de Privacidade</a>.</p>
  <div class="ga-consent-actions">
    <button type="button" class="btn btn-primary" id="consent-accept">Aceitar</button>
    <button type="button" class="btn" id="consent-deny">Recusar</button>
  </div>
</section>
<?php endif; ?>
<div id="sr-live" class="sr-only" aria-live="polite" aria-atomic="true"></div>
<script src="/assets/js/pwa.js?v=3"></script>
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
