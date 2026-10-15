<?php
declare(strict_types=1);

define('APP_ROOT', __DIR__);

$cfgFile = getenv('BIPOLAR_CONFIG') ?: APP_ROOT . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    exit('Configuração ausente: copie app/config.example.php para app/config.php.');
}
$GLOBALS['CONFIG'] = require $cfgFile;
define('STORAGE_DIR', rtrim((string)($GLOBALS['CONFIG']['storage_dir'] ?? dirname(__DIR__) . '/storage'), '/'));
// Chaves VAPID geradas por tools/generate_vapid.php (via Cron, sem SSH) valem quando o config.php não as tiver.
if (trim((string)($GLOBALS['CONFIG']['vapid']['public_key'] ?? '')) === '' && is_file(STORAGE_DIR . '/vapid.php')) {
    $vk = include STORAGE_DIR . '/vapid.php';
    if (is_array($vk)) $GLOBALS['CONFIG']['vapid'] = $vk + (array)($GLOBALS['CONFIG']['vapid'] ?? []);
    unset($vk);
}
date_default_timezone_set('UTC');
if (empty($GLOBALS['CONFIG']['dev_login'])) { ini_set('display_errors', '0'); }
ini_set('log_errors', '1');
mb_internal_encoding('UTF-8');

require APP_ROOT . '/lib/helpers.php';
require APP_ROOT . '/lib/db.php';
require APP_ROOT . '/lib/auth.php';
require APP_ROOT . '/lib/mood.php';
require APP_ROOT . '/lib/validate.php';
require APP_ROOT . '/lib/password_auth.php';
require_once APP_ROOT . '/lib/mfa.php'; // V4.4
require APP_ROOT . '/lib/markers.php';
require APP_ROOT . '/lib/lock.php';

if (PHP_SAPI !== 'cli') {
    set_exception_handler(function (Throwable $e) {
        error_log('Erro não tratado: ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) http_response_code(500);
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo '{"erro":"Erro interno. Tente novamente mais tarde."}';
        } else {
            echo 'Erro interno. Tente novamente mais tarde.';
        }
    });
}

if (PHP_SAPI !== 'cli') {
    send_security_headers();
    start_secure_session();
}
