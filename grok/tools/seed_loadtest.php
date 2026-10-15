<?php
/**
 * Cria contas de TESTE DE CARGA direto no banco (sem e-mail, sem limite de cadastro).
 *
 *   php /home1/USUARIO/tools/seed_loadtest.php --count=100 --password='SenhaDeTeste-2026!' [--days=30] [--domain=bipolar.app.br]
 *
 * Contas: loadtest+001@dominio ... loadtest+NNN@dominio, já verificadas, todas com a mesma senha.
 * Idempotente: rodar de novo não duplica (só atualiza a senha). Pode ser agendado UMA vez no Cron do cPanel.
 * Para apagar tudo depois: tools/cleanup_loadtest.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../cron/_cron_boot.php';
require_once APP_ROOT . '/lib/loadtest.php';
$o = getopt('', ['count:', 'password:', 'days::', 'domain::']);
if (empty($o['count']) || empty($o['password'])) {
    fwrite(STDERR, "Uso: php seed_loadtest.php --count=100 --password='SenhaDeTeste-2026!' [--days=30] [--domain=" . loadtest_domain() . "]\n");
    exit(2);
}
try {
    $t = microtime(true);
    $r = loadtest_seed((int)$o['count'], (string)$o['password'], isset($o['days']) ? (int)$o['days'] : 30, isset($o['domain']) ? (string)$o['domain'] : null);
    printf("%s contas criadas: %d · já existentes: %d · registros de histórico: %d · %.1fs\n", date('c'), $r['created'], $r['existing'], $r['entries'], microtime(true) - $t);
    printf("Primeira: %s · Última: %s\n", loadtest_email(1, $o['domain'] ?? loadtest_domain()), loadtest_email((int)$o['count'], $o['domain'] ?? loadtest_domain()));
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . "\n");
    exit(1);
}
