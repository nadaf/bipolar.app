<?php
// Cron (cPanel): a cada 5 minutos →  /usr/local/bin/php /home/USUARIO/cron/send_reminders.php
// V5.1: lembretes e adiamentos passam pela fila de envios (tabela outbox): reserva atômica, até 5 tentativas
// com espera crescente, e o lembrete só é marcado como enviado depois da entrega confirmada.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/_cron_boot.php';
require_once APP_ROOT . '/lib/outbox_jobs.php';

// Só otimização (evita dois processos ao mesmo tempo); a correção vem das reservas da fila.
$lock = fopen(sys_get_temp_dir() . '/bipolar_reminders.lock', 'c');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) exit("Já em execução.\n");

$hbOk = cron_heartbeat('lembretes', [], 'inicio');
$pdo = db();
$sw = outbox_sweep($pdo);
$q = reminders_enqueue_due($pdo);
$qs = snoozes_enqueue_due($pdo);
$res = outbox_process($pdo, outbox_reminder_handlers(), 20, (int)cfg('outbox.budget_seconds', 240));
// Limpeza: limites de tentativas antigos, ids de registros offline com mais de 30 dias, fila com mais de 30 dias
$pdo->exec('DELETE FROM rate_limits WHERE window_start < UNIX_TIMESTAMP() - 86400');
$pdo->exec('DELETE FROM entry_client_ids WHERE created_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');
$pdo->exec("DELETE FROM outbox WHERE state IN ('sent', 'cancelled', 'expired', 'dead') AND updated_at < UTC_TIMESTAMP() - INTERVAL 30 DAY");
// Contas criadas durante a atualização (código antigo ainda no ar) ficam com subject_hash vazio: completa aqui
$pdo->exec("UPDATE oauth_identities SET subject_hash = SHA2(CONCAT(provider, '|', subject), 256) WHERE subject_hash IS NULL");

$hbOk = cron_heartbeat('lembretes', ['enfileirados' => $q + $qs, 'entregues' => $res['sent'], 'nova_tentativa' => $res['retry'],
    'falharam' => $res['dead'], 'cancelados' => $res['cancelled'], 'vencidos' => $sw['expired']], 'fim') && $hbOk;
echo date('c') . " lembretes entregues: {$res['sent']}, nova tentativa: {$res['retry']}, falharam: {$res['dead']}\n";
exit($hbOk ? 0 : 2);
