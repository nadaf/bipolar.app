<?php
// Cron (cPanel): a cada hora →  php /home/USUARIO/cron/send_reports.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/_cron_boot.php';
require_once APP_ROOT . '/lib/smtp.php';
require_once APP_ROOT . '/lib/report.php';
require_once APP_ROOT . '/lib/schedule.php';
require_once APP_ROOT . '/lib/custom_alerts.php';

$lock = fopen(sys_get_temp_dir() . '/bipolar_reports.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("Já em execução.\n");

$pdo = db();
$st = $pdo->query('SELECT r.*, u.name AS uname, u.timezone, u.id AS uid FROM recipients r JOIN users u ON u.id = r.user_id
    WHERE r.confirmed_at IS NOT NULL AND r.unsubscribed_at IS NULL AND r.next_send_at <= UTC_TIMESTAMP() LIMIT 200');
$sent = 0;
foreach ($st->fetchAll() as $r) {
    $tz = new DateTimeZone($r['timezone']);
    $to = (new DateTime('yesterday', $tz))->format('Y-m-d');
    $from = (new DateTime('today', $tz))->modify('-' . report_period_days($r) . ' days')->format('Y-m-d');
    $user = ['id' => $r['uid'], 'name' => $r['uname']];
    $data = report_data((int)$r['uid'], $from, $to);
    $cancel = rtrim(cfg('app_url'), '/') . '/confirmar.php?cancelar=1&t=' . $r['confirm_token'];
    $html = email_layout('Relatório de humor — ' . ($r['uname'] ?: 'paciente'),
        report_html($user, $data, (bool)$r['include_notes'], (bool)$r['include_media_list'])
        . ((int)$r['include_markers'] ? markers_report_html(markers_report_data((int)$r['uid'], $from, $to, (int)$r['include_sexuality'] === 1, (int)$r['include_substances'] === 1, (int)($r['include_food'] ?? 0) === 1)) : '')
        . (!empty($r['include_custom_alerts']) ? custom_count_html(custom_count_for_report($pdo, (int)$r['uid'], $from, $to, ((int)$r['include_sexuality'] === 1 ? CUSTOM_PRIV_SEX : 0) | ((int)$r['include_substances'] === 1 ? CUSTOM_PRIV_SUBST : 0))) : '')
        . '<p style="font-size:12px;color:#50606c;margin-top:20px">Você recebe este relatório porque confirmou o convite como ' . e(ROLES[$r['role']]) . '. '
        . '<a href="' . e($cancel) . '">Cancelar recebimento</a>.</p>');
    $ok = send_mail($r['email'], 'Relatório de humor: ' . fmt_date($from) . ' a ' . fmt_date($to), $html, '', 'relatorio');
    $next = next_send_at($r, $r['timezone']);
    $pdo->prepare('UPDATE recipients SET next_send_at = ?' . ($ok ? ', last_sent_at = UTC_TIMESTAMP()' : '') . ' WHERE id = ?')
        ->execute([$ok ? $next : gmdate('Y-m-d H:i:s', time() + 3600), $r['id']]);
    if ($ok) $sent++;
    usleep(300000);
}
cron_heartbeat('relatorios', ['enviados' => $sent]);
echo date('c') . " relatórios enviados: $sent\n";
