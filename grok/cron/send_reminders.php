<?php
// Cron (cPanel): a cada 5 minutos →  php /home/USUARIO/cron/send_reminders.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/_cron_boot.php';
require_once APP_ROOT . '/lib/smtp.php';
require_once APP_ROOT . '/lib/webpush.php';

$lock = fopen(sys_get_temp_dir() . '/bipolar_reminders.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("Já em execução.\n");

$pdo = db();
$rows = $pdo->query('SELECT r.*, u.timezone, u.email, u.name FROM reminders r JOIN users u ON u.id = r.user_id WHERE r.active = 1')->fetchAll();
$pushOk = push_configured(); // sem VAPID, lembretes 'push' antigos caem para e-mail (abaixo)
$n = 0;
foreach ($rows as $r) {
    $now = new DateTime('now', new DateTimeZone($r['timezone']));
    $today = $now->format('Y-m-d');
    if ($r['last_sent_local_date'] === $today) continue;
    if (!in_array($now->format('w'), explode(',', $r['days']), true)) continue;
    $due = DateTime::createFromFormat('Y-m-d H:i', $today . ' ' . $r['time_local'], new DateTimeZone($r['timezone']));
    $diff = $now->getTimestamp() - $due->getTimestamp();
    if ($diff < 0 || $diff > 15 * 60) continue; // janela de 15 min tolera atrasos do cron

    $delivered = false;
    if ($pushOk && in_array($r['channel'], ['push', 'ambos'], true)) {
        // Payload criptografado (RFC 8291) com texto genérico e ações "Registrar agora" / "Lembrar em 30 min"
        $delivered = push_to_user((int)$r['user_id'], reminder_payload((string)$r['timezone'])) > 0;
    }
    // E-mail: quando escolhido, ou como alternativa se o push falhou
    if ($r['email'] && ($r['channel'] !== 'push' || !$delivered)) {
        $html = email_layout('Hora do seu registro de humor', '<p>Olá' . ($r['name'] ? ', ' . e(explode(' ', $r['name'])[0]) : '') . '! Como você está agora?</p>'
            . '<p><a href="' . e(rtrim(cfg('app_url'), '/')) . '/?aba=registrar" style="display:inline-block;background:#2f6f73;color:#fff;padding:12px 20px;border-radius:10px;text-decoration:none">Registrar humor</a></p>'
            . '<p style="font-size:12px;color:#50606c">Para mudar ou desativar lembretes, acesse Lembretes no aplicativo.</p>');
        if (send_mail($r['email'], 'Lembrete: registre seu humor', $html, '', 'lembrete')) $delivered = true;
    }
    $pdo->prepare('UPDATE reminders SET last_sent_local_date = ? WHERE id = ?')->execute([$today, $r['id']]);
    if ($delivered) $n++;
}
// "Lembrar em 30 min": reenvia para os aparelhos cujo adiamento venceu
if ($pushOk) {
    $sn = $pdo->query('SELECT s.id, s.endpoint, s.p256dh, s.auth, u.timezone FROM push_subscriptions s JOIN users u ON u.id = s.user_id WHERE s.snooze_until IS NOT NULL AND s.snooze_until <= UTC_TIMESTAMP()')->fetchAll();
    foreach ($sn as $s) {
        $pdo->prepare('UPDATE push_subscriptions SET snooze_until = NULL WHERE id = ?')->execute([$s['id']]);
        try {
            $code = send_push($s, reminder_payload((string)$s['timezone']));
            if (in_array($code, [404, 410], true)) $pdo->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$s['id']]);
            elseif ($code >= 200 && $code < 300) $n++;
        } catch (Throwable $e) { error_log('push snooze: ' . $e->getMessage()); }
    }
}
// Limpeza de rate limits antigos
$pdo->exec('DELETE FROM rate_limits WHERE window_start < UNIX_TIMESTAMP() - 86400');
cron_heartbeat('lembretes', ['entregues' => $n]);
echo date('c') . " lembretes entregues: $n\n";
