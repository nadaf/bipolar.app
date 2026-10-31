<?php
// Cron (cPanel): a cada hora →  /usr/local/bin/php /home/USUARIO/cron/send_reports.php
// V5.1: relatórios passam pela fila de envios (tabela outbox), em lotes pequenos e sem limite fixo de 200 por execução.
// O próximo envio só é agendado depois da entrega confirmada (ou depois de 5 tentativas sem sucesso).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/_cron_boot.php';
require_once APP_ROOT . '/lib/outbox_jobs.php';

$lock = fopen(sys_get_temp_dir() . '/bipolar_reports.lock', 'c');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) exit("Já em execução.\n");

$hbOk = cron_heartbeat('relatorios', [], 'inicio');
$pdo = db();
$sw = outbox_sweep($pdo);
$q = reports_enqueue_due($pdo);
$res = outbox_process($pdo, outbox_report_handlers(), 10, (int)cfg('outbox.budget_seconds', 240));
$hbOk = cron_heartbeat('relatorios', ['enfileirados' => $q, 'enviados' => $res['sent'], 'nova_tentativa' => $res['retry'],
    'falharam' => $res['dead'], 'cancelados' => $res['cancelled'], 'vencidos' => $sw['expired']], 'fim') && $hbOk;
echo date('c') . " relatórios enviados: {$res['sent']}, nova tentativa: {$res['retry']}, falharam: {$res['dead']}\n";
exit($hbOk ? 0 : 2);
