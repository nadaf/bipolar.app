<?php
// V4.6: cor da marca da instalação (config 'brand.color'), servida como CSS externo para respeitar a CSP (sem estilo inline).
foreach ([__DIR__ . '/../app', __DIR__ . '/../bipolar_app'] as $d) {
    if (is_file($d . '/bootstrap.php')) { require_once $d . '/bootstrap.php'; break; }
}
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
$c = function_exists('brand_color') ? brand_color() : null;
if ($c === null) { echo "/* cor padrão */\n"; exit; }
echo ":root:not([data-theme=dark]) { --primary: $c; }\n";
