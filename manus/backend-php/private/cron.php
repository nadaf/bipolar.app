<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';
try {
    $config = bipolar_load_config(__DIR__);
    $GLOBALS['bipolar_storage_path'] = $config['app']['storage_path'];
    $pdo = bipolar_pdo($config);
    $result = bipolar_aut_cron_run($pdo, $config);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (BipolarApiException $e) {
    fwrite(STDERR, "cron_error\n");
    exit(1);
} catch (Throwable $e) {
    error_log('[bipolar] automation cron failed');
    fwrite(STDERR, "cron_error\n");
    exit(1);
}
