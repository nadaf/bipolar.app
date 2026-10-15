<?php
require __DIR__ . '/../_boot.php';
require_once APP_ROOT . '/lib/oauth.php';
if (!rate_limit('oauth_start', 20, 300)) { http_response_code(429); exit('Muitas tentativas. Aguarde alguns minutos.'); }
oauth_start((string)($_GET['provider'] ?? ''));
