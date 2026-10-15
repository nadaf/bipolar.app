<?php
foreach ([__DIR__ . '/../app', __DIR__ . '/../bipolar_app'] as $d) {
    if (is_file($d . '/bootstrap.php')) { require_once $d . '/bootstrap.php'; break; }
}
if (!defined('APP_ROOT')) { fwrite(STDERR, "Pasta app não encontrada\n"); exit(1); }
