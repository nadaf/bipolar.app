<?php
/**
 * Apaga TODAS as contas de teste de carga (loadtest+NNN@dominio) e todos os dados delas.
 *
 *   php /home1/USUARIO/tools/cleanup_loadtest.php            → só lista (simulação, nada é apagado)
 *   php /home1/USUARIO/tools/cleanup_loadtest.php --yes      → apaga de verdade
 *   [--domain=bipolar.app.br]
 *
 * Somente e-mails exatamente no formato loadtest+<números>@dominio são afetados.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../cron/_cron_boot.php';
require_once APP_ROOT . '/lib/loadtest.php';
$o = getopt('', ['yes', 'domain::']);
$dry = !isset($o['yes']);
$r = loadtest_cleanup($dry, isset($o['domain']) ? (string)$o['domain'] : null);
if ($dry) {
    printf("SIMULAÇÃO: %d conta(s) de teste encontradas%s\n", $r['found'], $r['found'] ? ' (ex.: ' . implode(', ', array_slice($r['emails'], 0, 3)) . ')' : '');
    echo "Nada foi apagado. Para apagar, rode novamente com --yes\n";
} else {
    printf("%s contas de teste apagadas: %d de %d\n", date('c'), $r['deleted'], $r['found']);
}
