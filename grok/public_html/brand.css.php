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
// V5.1: só no tema claro (antes valia também no escuro do sistema, sem data-theme, e deixava o contraste errado)
echo ":root[data-theme=light] { --primary: $c; }\n@media (prefers-color-scheme: light) { :root:not([data-theme=dark]) { --primary: $c; } }\n";
