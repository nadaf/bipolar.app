<?php
/**
 * Situação das 3 tarefas cron (só no servidor, sem tela nem API).
 *
 *   php /home1/USUARIO/tools/cron_status.php [--tz=America/Cuiaba]
 *
 * Lê storage/heartbeat-*.json. O histórico de execuções fica em storage/logs/cron.log.
 * Pode ser agendado no Cron do cPanel com a saída enviada ao seu e-mail, se quiser acompanhar.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../cron/_cron_boot.php';
require_once APP_ROOT . '/lib/cron_status.php';
$o = getopt('', ['tz::']);
$tz = isset($o['tz']) ? (string)$o['tz'] : (string)cfg('default_timezone', 'America/Cuiaba');
$late = 0;
foreach (cron_status_all($tz) as $r) {
    printf("%-55s %-22s %s\n", $r['label'], $r['last_local'] ?? '-', $r['status_label']);
    if ($r['status'] !== 'ok') $late++;
}
exit($late ? 1 : 0);
