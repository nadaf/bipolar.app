<?php
// Localiza a pasta "app" fora do public_html (ex.: /home/usuario/bipolar_app ou ../app).
foreach ([__DIR__ . '/../app', __DIR__ . '/../bipolar_app'] as $d) {
    if (is_file($d . '/bootstrap.php')) { require_once $d . '/bootstrap.php'; break; }
}
if (!defined('APP_ROOT')) { http_response_code(500); exit('Pasta do aplicativo não encontrada.'); }
require_once APP_ROOT . '/views/layout.php';
require_once APP_ROOT . '/views/auth.php';
