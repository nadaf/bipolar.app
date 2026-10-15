<?php
// Cron (cPanel): a cada hora (minuto 7) →  php /home/USUARIO/cron/analyze_signals.php
// Analisa os registros de todos os usuários ativos e gera alertas (com deduplicação e intervalo de 72 h por regra).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/_cron_boot.php';
require_once APP_ROOT . '/lib/alerts.php';
require_once APP_ROOT . '/lib/custom_alerts.php';

$lock = fopen(sys_get_temp_dir() . '/bipolar_signals.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("Já em execução.\n");

$pdo = db();
// V4.2: também quem tem alerta personalizado ativo (ex.: "3 dias sem registrar" precisa avaliar quem parou de registrar).
$active = 'EXISTS (SELECT 1 FROM entries e WHERE e.user_id = u.id AND e.entry_date >= CURDATE() - INTERVAL 60 DAY)';
try { $users = $pdo->query("SELECT u.* FROM users u WHERE $active OR EXISTS (SELECT 1 FROM alert_rules r WHERE r.user_id = u.id AND r.active = 1)")->fetchAll(); }
catch (PDOException $e) { $users = $pdo->query("SELECT u.* FROM users u WHERE $active")->fetchAll(); error_log('analyze_signals: rode a migração V4.2 (' . $e->getMessage() . ')'); }
$new = 0; $custom = 0; $pending = 0;
foreach ($users as $u) {
    try { $new += count(alerts_run_for_user($pdo, $u, true)); }
    catch (Throwable $e) { error_log('analyze_signals usuário ' . $u['id'] . ': ' . $e->getMessage()); }
    try { $custom += count(custom_eval_user($pdo, $u, true)); $pending += alerts_deliver_pending($pdo, $u); }
    catch (Throwable $e) { error_log('analyze_signals (personalizados) usuário ' . $u['id'] . ': ' . $e->getMessage()); }
}
cron_heartbeat('analise', ['usuarios' => count($users), 'alertas' => $new, 'personalizados' => $custom, 'pendentes_entregues' => $pending]);
echo date('c') . ' usuários analisados: ' . count($users) . ", alertas novos: $new, personalizados: $custom, pendentes entregues: $pending\n";
