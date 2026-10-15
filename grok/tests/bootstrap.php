<?php
declare(strict_types=1);
/**
 * Bootstrap da suíte. Variáveis de ambiente (opcionais):
 *  TEST_DB_HOST (127.0.0.1) TEST_DB_PORT (3307) TEST_DB_NAME (bipolar_test) TEST_DB_USER (bp) TEST_DB_PASS (bp)
 */
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/support/TestEnv.php';
require __DIR__ . '/support/Http.php';
require __DIR__ . '/support/IntegrationCase.php';
require __DIR__ . '/support/SoftAuthenticator.php';

TestEnv::setup();
