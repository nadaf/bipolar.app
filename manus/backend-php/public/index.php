<?php
declare(strict_types=1);

/*
 * O arquivo público só localiza o código/configuração fora de public_html.
 * Em cPanel, o padrão é ../../bipolar-private a partir de public_html/api/.
 * BIPOLAR_PRIVATE_PATH permite um caminho absoluto alternativo.
 */
$configured = getenv('BIPOLAR_PRIVATE_PATH');
$candidates = array_values(array_filter([
    is_string($configured) && $configured !== '' ? $configured : null,
    dirname(__DIR__, 2) . '/bipolar-private',
    dirname(__DIR__) . '/private',
]));
$privateDir = null;
foreach ($candidates as $candidate) {
    $resolved = realpath($candidate);
    if ($resolved !== false && is_dir($resolved)) {
        $privateDir = $resolved;
        break;
    }
}

if ($privateDir === null || !is_file($privateDir . '/bootstrap.php')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'service_unavailable'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require $privateDir . '/bootstrap.php';
bipolar_api_run($privateDir);
