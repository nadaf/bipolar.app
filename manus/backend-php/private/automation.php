<?php
declare(strict_types=1);
require_once __DIR__ . "/report-render.php";
require_once __DIR__ . "/automation-guards.php";

/*
 * Automation is deliberately kept out of bootstrap.php.  This file contains
 * only bipolar_aut_* symbols so it can be loaded by both HTTP and cron.
 * Secrets, recipient addresses, subscription endpoints and report payloads
 * are encrypted with the existing SecretBox key before they reach MySQL.
 */

function bipolar_aut_defaults(): array
{
    return [
        'reports' => [
            'enabled' => false, 'frequency' => 'weekly', 'time' => '18:00',
            'weekday' => 1, 'monthDay' => 1, 'intervalDays' => 14,
            'startDate' => (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d'),
            'timezone' => 'America/Sao_Paulo', 'includeNotes' => false,
            'includeDetails' => false, 'recipients' => [], 'consent' => false,
        ],
        'reminders' => [
            'enabled' => false, 'channel' => 'in-app',
            'slots' => [
                ['id' => 'morning', 'enabled' => true, 'time' => '09:00', 'period' => 'morning'],
                ['id' => 'afternoon', 'enabled' => true, 'time' => '15:00', 'period' => 'afternoon'],
                ['id' => 'night', 'enabled' => true, 'time' => '21:00', 'period' => 'night'],
            ],
            'custom' => [], 'days' => [0, 1, 2, 3, 4, 5, 6],
            'timezone' => 'America/Sao_Paulo', 'skipRecorded' => true,
            'quietStart' => '22:00', 'quietEnd' => '07:00', 'email' => '',
        ],
    ];
}

function bipolar_aut_smtp_config(array $config): ?array
{
    $smtp = $config['smtp'] ?? [];
    if (!is_array($smtp) || !(bool)($smtp['enabled'] ?? false)) return null;
    $host = trim((string)($smtp['host'] ?? ''));
    $port = (int)($smtp['port'] ?? 0);
    $username = (string)($smtp['username'] ?? '');
    $password = (string)($smtp['password'] ?? '');
    $fromEmail = trim((string)($smtp['from_email'] ?? ''));
    $fromName = bipolar_clean_text((string)($smtp['from_name'] ?? 'Bipolar.app.br'), 120);
    if ($host === '' || !in_array($port, [465, 587], true) || $username === '' || $password === '' ||
        !filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || strlen($fromEmail) > 254) return null;
    return ['host' => $host, 'port' => $port, 'username' => $username, 'password' => $password,
        'from_email' => $fromEmail, 'from_name' => $fromName !== '' ? $fromName : 'Bipolar.app.br'];
}

function bipolar_aut_vapid_config(array $config): ?array
{
    $vapid = $config['push']['vapid'] ?? ($config['vapid'] ?? []);
    if (!is_array($vapid) || !(bool)($vapid['enabled'] ?? false)) return null;
    $public = trim((string)($vapid['public_key'] ?? ''));
    $private = trim((string)($vapid['private_key'] ?? ''));
    $subject = trim((string)($vapid['subject'] ?? ''));
    if ($public === '' || $private === '' || !filter_var($subject, FILTER_VALIDATE_URL) ||
        !str_starts_with(strtolower($subject), 'mailto:') && !str_starts_with(strtolower($subject), 'https://')) return null;
    $decode = static function (string $value): string|false {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) return false;
        return base64_decode(strtr($value, '-_', '+/'), true) ?: false;
    };
    $publicBytes = $decode($public); $privateBytes = $decode($private);
    if ($publicBytes === false || strlen($publicBytes) !== 65 || $privateBytes === false || strlen($privateBytes) !== 32) return null;
    return ['public_key' => $public, 'private_key' => $private, 'subject' => $subject];
}

function bipolar_aut_capabilities(PDO $pdo, array $config): array
{
    $smtp = bipolar_aut_smtp_config($config);
    $smtpAvailable = $smtp !== null && is_file(__DIR__ . '/vendor/autoload.php');
    if ($smtpAvailable) {
        require_once __DIR__ . '/vendor/autoload.php';
        $smtpAvailable = class_exists('PHPMailer\\PHPMailer\\PHPMailer');
    }
    $vapid = bipolar_aut_vapid_config($config);
    $pushAvailable = $vapid !== null && is_file(__DIR__ . '/vendor/autoload.php');
    if ($pushAvailable) {
        require_once __DIR__ . '/vendor/autoload.php';
        $pushAvailable = class_exists('Minishlink\\WebPush\\WebPush') && class_exists('Minishlink\\WebPush\\Subscription');
    }
    $lastRun = null;
    try {
        $row = $pdo->query('SELECT last_run_at FROM automation_cron_heartbeat WHERE id = 1 LIMIT 1')->fetch();
        if (is_array($row) && $row['last_run_at'] !== null) $lastRun = bipolar_db_to_iso((string)$row['last_run_at']);
    } catch (Throwable $e) { /* Migration may not have been run yet; never expose SQL details. */ }
    return ['smtp' => $smtpAvailable, 'push' => $pushAvailable,
        'vapidPublicKey' => $pushAvailable ? (string)$vapid['public_key'] : '', 'cronLastRun' => $lastRun];
}

function bipolar_aut_response(PDO $pdo, array $config, string $userId, ?array $settings = null): array
{
    $capabilities = bipolar_aut_capabilities($pdo, $config);
    $stmt = $pdo->prepare('SELECT endpoint_hash FROM push_subscriptions WHERE user_id = ? AND disabled_at IS NULL'); $stmt->execute([$userId]);
    $capabilities['pushEndpointHashes'] = array_column($stmt->fetchAll(), 'endpoint_hash');
    return ['settings' => $settings ?? bipolar_aut_load_settings($pdo, $config, $userId), 'capabilities' => $capabilities];
}

function bipolar_aut_load_settings(PDO $pdo, array $config, string $userId): array
{
    $defaults = bipolar_aut_defaults();
    $stmt = $pdo->prepare('SELECT settings_cipher FROM automation_settings WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]); $row = $stmt->fetch();
    $settings = $defaults;
    if (is_array($row)) {
        try { $decoded = json_decode(bipolar_decrypt((string)$row['settings_cipher'], $config['app']['encryption_key']), true, 64, JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { throw new BipolarApiException('data_unavailable', 500); }
        if (!is_array($decoded)) throw new BipolarApiException('data_unavailable', 500);
        $settings = bipolar_aut_merge_settings($defaults, $decoded);
    }
    $stmt = $pdo->prepare('SELECT id, name_cipher, email_cipher, role FROM automation_recipients WHERE user_id = ? AND active = 1 ORDER BY created_at ASC');
    $stmt->execute([$userId]); $recipients = [];
    while ($item = $stmt->fetch()) {
        try {
            $name = bipolar_decrypt((string)$item['name_cipher'], $config['app']['encryption_key']);
            $email = bipolar_decrypt((string)$item['email_cipher'], $config['app']['encryption_key']);
        } catch (Throwable $e) { throw new BipolarApiException('data_unavailable', 500); }
        $recipients[] = ['id' => (string)$item['id'], 'name' => $name, 'email' => $email, 'role' => (string)$item['role']];
    }
    $settings['reports']['recipients'] = $recipients;
    return $settings;
}

function bipolar_aut_merge_settings(array $defaults, array $value): array
{
    $out = $defaults;
    foreach (['reports', 'reminders'] as $section) if (is_array($value[$section] ?? null)) {
        foreach ($value[$section] as $key => $item) if ($key !== 'recipients' && $key !== 'slots' && $key !== 'custom') $out[$section][$key] = $item;
    }
    if (isset($value['reminders']['slots']) && is_array($value['reminders']['slots'])) $out['reminders']['slots'] = $value['reminders']['slots'];
    if (isset($value['reminders']['custom']) && is_array($value['reminders']['custom'])) $out['reminders']['custom'] = $value['reminders']['custom'];
    return $out;
}

function bipolar_aut_valid_time(mixed $value): bool { return is_string($value) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1; }
function bipolar_aut_timezone(mixed $value): string
{
    if (!is_string($value) || strlen($value) > 64) throw new BipolarApiException('invalid_automation', 422);
    try { new DateTimeZone($value); } catch (Throwable $e) { throw new BipolarApiException('invalid_automation', 422); }
    return $value;
}
function bipolar_aut_date(mixed $value, string $timezone): string
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !bipolar_valid_date($value, $timezone)) throw new BipolarApiException('invalid_automation', 422);
    return $value;
}
function bipolar_aut_bool(mixed $value): bool { if (!is_bool($value)) throw new BipolarApiException('invalid_automation', 422); return $value; }

function bipolar_aut_validate_settings(array $raw, array $config, string $userId, bool $forSave = true): array
{
    $defaults = bipolar_aut_defaults();
    $s = bipolar_aut_merge_settings($defaults, $raw);
    if (!is_array($raw['reports'] ?? null) || !is_array($raw['reminders'] ?? null)) throw new BipolarApiException('invalid_automation', 422);
    $r = $s['reports']; $timezone = bipolar_aut_timezone($r['timezone'] ?? 'America/Sao_Paulo');
    $r['enabled'] = bipolar_aut_bool($r['enabled'] ?? false);
    if (!in_array($r['frequency'] ?? null, ['daily', 'weekly', 'monthly', 'custom'], true)) throw new BipolarApiException('invalid_automation', 422);
    if (!bipolar_aut_valid_time($r['time'] ?? null)) throw new BipolarApiException('invalid_automation', 422);
    if (!is_int($r['weekday'] ?? null) || $r['weekday'] < 0 || $r['weekday'] > 6 || !is_int($r['monthDay'] ?? null) || $r['monthDay'] < 1 || $r['monthDay'] > 31 || !is_int($r['intervalDays'] ?? null) || $r['intervalDays'] < 1 || $r['intervalDays'] > 90) throw new BipolarApiException('invalid_automation', 422);
    $r['startDate'] = bipolar_aut_date($r['startDate'] ?? null, $timezone);
    $r['includeNotes'] = bipolar_aut_bool($r['includeNotes'] ?? false); $r['includeDetails'] = bipolar_aut_bool($r['includeDetails'] ?? false);
    $r['consent'] = bipolar_aut_bool($r['consent'] ?? false);
    $rawRecipients = $raw['reports']['recipients'] ?? [];
    if (!is_array($rawRecipients) || count($rawRecipients) > 10) throw new BipolarApiException('recipient_limit', 422);
    $recipients = []; $ids = [];
    foreach ($rawRecipients as $item) {
        if (!is_array($item) || !is_string($item['id'] ?? null) || !bipolar_is_uuid($item['id']) || in_array($item['id'], $ids, true)) throw new BipolarApiException('invalid_recipient', 422);
        $name = bipolar_clean_text((string)($item['name'] ?? ''), 160); $email = trim((string)($item['email'] ?? '')); $role = $item['role'] ?? null;
        if ($name === '' || !bipolar_valid_text($name, 160) || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['psychologist', 'psychiatrist', 'family', 'other'], true)) throw new BipolarApiException('invalid_recipient', 422);
        $ids[] = $item['id']; $recipients[] = ['id' => $item['id'], 'name' => $name, 'email' => $email, 'role' => $role];
    }
    if (count(array_unique(array_map(static fn($item) => strtolower($item['email']), $recipients))) !== count($recipients)) throw new BipolarApiException('invalid_recipient', 422);
    $r['recipients'] = $recipients; $r['timezone'] = $timezone; if ($r['enabled'] && !$recipients) throw new BipolarApiException('consent_required', 422);
    if ($r['enabled'] && (!$r['consent'] || bipolar_aut_smtp_config($config) === null)) throw new BipolarApiException('automation_not_configured', 503);
    $m = $s['reminders']; $mtz = bipolar_aut_timezone($m['timezone'] ?? 'America/Sao_Paulo');
    $m['enabled'] = bipolar_aut_bool($m['enabled'] ?? false); if (!in_array($m['channel'] ?? null, ['in-app', 'push', 'email'], true)) throw new BipolarApiException('invalid_automation', 422);
    $m['timezone'] = $mtz; $m['skipRecorded'] = bipolar_aut_bool($m['skipRecorded'] ?? true);
    foreach (['quietStart', 'quietEnd'] as $key) if (!bipolar_aut_valid_time($m[$key] ?? null)) throw new BipolarApiException('invalid_automation', 422);
    $email = trim((string)($m['email'] ?? '')); if ($email !== '' && (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL))) throw new BipolarApiException('invalid_automation', 422); $m['email'] = $email;
    $days = $m['days'] ?? []; if (!is_array($days) || count($days) > 7) throw new BipolarApiException('invalid_automation', 422); $cleanDays = [];
    foreach ($days as $day) { if (!is_int($day) || $day < 0 || $day > 6 || in_array($day, $cleanDays, true)) throw new BipolarApiException('invalid_automation', 422); $cleanDays[] = $day; } sort($cleanDays); $m['days'] = $cleanDays;
    if (!is_array($m['slots'] ?? null) || count($m['slots']) !== 3) throw new BipolarApiException('invalid_automation', 422); $slots = [];
    foreach ($m['slots'] as $slot) {
        if (!is_array($slot) || !in_array($slot['id'] ?? null, ['morning', 'afternoon', 'night'], true) || !is_bool($slot['enabled'] ?? null) || !bipolar_aut_valid_time($slot['time'] ?? null) || !in_array($slot['period'] ?? null, ['morning', 'afternoon', 'night'], true)) throw new BipolarApiException('invalid_automation', 422);
        $slots[] = ['id' => $slot['id'], 'enabled' => $slot['enabled'], 'time' => $slot['time'], 'period' => $slot['period']];
    } $m['slots'] = $slots;
    if (!is_array($m['custom'] ?? null) || count($m['custom']) > 6) throw new BipolarApiException('invalid_automation', 422); $custom = []; $customIds = [];
    foreach ($m['custom'] as $item) {
        if (!is_array($item) || !bipolar_is_uuid((string)($item['id'] ?? '')) || in_array($item['id'], $customIds, true) || !bipolar_aut_valid_time($item['time'] ?? null) || !is_bool($item['enabled'] ?? null) || !in_array($item['period'] ?? null, ['morning', 'afternoon', 'night', null], true)) throw new BipolarApiException('invalid_automation', 422);
        $customIds[] = $item['id']; $custom[] = ['id' => $item['id'], 'time' => $item['time'], 'period' => $item['period'], 'enabled' => $item['enabled']];
    } $m['custom'] = $custom;
    if ($m['enabled'] && $m['channel'] === 'email' && $m['email'] === '') throw new BipolarApiException('invalid_automation', 422);
    if ($m['enabled'] && $m['channel'] === 'email' && bipolar_aut_smtp_config($config) === null) throw new BipolarApiException('automation_not_configured', 503);
    if ($m['enabled'] && $m['channel'] === 'push' && bipolar_aut_vapid_config($config) === null) throw new BipolarApiException('automation_not_configured', 503);
    if (count(array_unique(array_column($slots, 'id'))) !== 3) throw new BipolarApiException('invalid_automation', 422);
    $active = array_filter(array_merge($slots, $custom), static fn($slot) => $slot['enabled']); if ($m['enabled'] && (!$active || !$cleanDays)) throw new BipolarApiException('invalid_automation', 422); if (count(array_unique(array_column($active, 'time'))) !== count($active)) throw new BipolarApiException('invalid_automation', 422);
    $s['reports'] = $r; $s['reminders'] = $m; return $s;
}

function bipolar_aut_save_settings(PDO $pdo, array $config, string $userId, array $raw): array
{
    $settings = bipolar_aut_validate_settings($raw['settings'] ?? [], $config, $userId);
    if ($settings['reminders']['enabled'] && $settings['reminders']['channel'] === 'push') { $stmt = $pdo->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ? AND disabled_at IS NULL'); $stmt->execute([$userId]); if ((int)$stmt->fetchColumn() < 1) throw new BipolarApiException('invalid_push_subscription', 422); }
    $old = bipolar_aut_load_settings($pdo, $config, $userId);
    $recipients = $settings['reports']['recipients']; $now = bipolar_db_now(); $cipherSettings = bipolar_encrypt(json_encode(['reports' => array_diff_key($settings['reports'], ['recipients' => true]), 'reminders' => $settings['reminders']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $config['app']['encryption_key']);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO automation_settings (user_id, settings_cipher, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE settings_cipher = VALUES(settings_cipher), updated_at = VALUES(updated_at)'); $stmt->execute([$userId, $cipherSettings, $now]);
        $keep = [];
        foreach ($recipients as $recipient) {
            $keep[] = $recipient['id']; $nameCipher = bipolar_encrypt($recipient['name'], $config['app']['encryption_key']); $emailCipher = bipolar_encrypt($recipient['email'], $config['app']['encryption_key']);
            $stmt = $pdo->prepare('SELECT id FROM automation_recipients WHERE id = ? AND user_id = ? LIMIT 1 FOR UPDATE'); $stmt->execute([$recipient['id'], $userId]);
            if (is_array($stmt->fetch())) { $stmt = $pdo->prepare('UPDATE automation_recipients SET name_cipher = ?, email_cipher = ?, role = ?, active = 1, updated_at = ? WHERE id = ? AND user_id = ?'); $stmt->execute([$nameCipher, $emailCipher, $recipient['role'], $now, $recipient['id'], $userId]); }
            else { $stmt = $pdo->prepare('INSERT INTO automation_recipients (id, user_id, name_cipher, email_cipher, role, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)'); $stmt->execute([$recipient['id'], $userId, $nameCipher, $emailCipher, $recipient['role'], $now, $now]); }
        }
        $stmt = $pdo->prepare('SELECT id FROM automation_recipients WHERE user_id = ? AND active = 1'); $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) if (!in_array((string)$row['id'], $keep, true)) {
            $id = (string)$row['id']; $q = $pdo->prepare("UPDATE automation_outbox SET status = 'failed', failure_code = 'cancelled', last_attempt_at = ? WHERE user_id = ? AND recipient_id = ? AND status = 'queued'"); $q->execute([$now, $userId, $id]);
            $q = $pdo->prepare("UPDATE automation_deliveries d INNER JOIN automation_outbox o ON o.id = d.outbox_id SET d.status = 'failed', d.last_attempt_at = ?, d.message = 'Cancelado antes do envio.' WHERE o.user_id = ? AND o.recipient_id = ? AND d.status = 'queued'"); $q->execute([$now, $userId, $id]);
            $q = $pdo->prepare('UPDATE automation_recipients SET active = 0, updated_at = ? WHERE id = ? AND user_id = ?'); $q->execute([$now, $id, $userId]);
        }
        if (!$settings['reports']['enabled'] || $old['reports'] !== $settings['reports']) { $q = $pdo->prepare("UPDATE automation_outbox SET status = 'failed', failure_code = 'cancelled', last_attempt_at = ? WHERE user_id = ? AND type = 'report' AND is_automated = 1 AND status = 'queued'"); $q->execute([$now, $userId]); $q = $pdo->prepare("UPDATE automation_deliveries d INNER JOIN automation_outbox o ON o.id = d.outbox_id SET d.status = 'failed', d.last_attempt_at = ?, d.message = 'Cancelado antes do envio.' WHERE o.user_id = ? AND o.type = 'report' AND o.is_automated = 1 AND d.status = 'queued'"); $q->execute([$now, $userId]); }
        if (!$settings['reminders']['enabled'] || $old['reminders'] !== $settings['reminders']) { $q = $pdo->prepare("UPDATE automation_outbox SET status = 'failed', failure_code = 'cancelled', last_attempt_at = ? WHERE user_id = ? AND type = 'reminder' AND is_automated = 1 AND status = 'queued'"); $q->execute([$now, $userId]); $q = $pdo->prepare("UPDATE automation_deliveries d INNER JOIN automation_outbox o ON o.id = d.outbox_id SET d.status = 'failed', d.last_attempt_at = ?, d.message = 'Cancelado antes do envio.' WHERE o.user_id = ? AND o.type = 'reminder' AND o.is_automated = 1 AND d.status = 'queued'"); $q->execute([$now, $userId]); }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw new BipolarApiException('automation_save_failed', 500); }
    return bipolar_aut_response($pdo, $config, $userId, bipolar_aut_load_settings($pdo, $config, $userId));
}

function bipolar_aut_report_period(array $reports, DateTimeImmutable $local): ?array
{
    if (!$reports['enabled'] || !$reports['consent']) return null;
    $date = $local->setTime(0, 0); $frequency = $reports['frequency']; $target = DateTimeImmutable::createFromFormat('!H:i', $reports['time']);
    if (!$target) return null; $scheduled = $date->setTime((int)substr($reports['time'], 0, 2), (int)substr($reports['time'], 3, 2));
    if ($local->getTimestamp() < $scheduled->getTimestamp() || $local->getTimestamp() - $scheduled->getTimestamp() > 300) return null;
    if ($frequency === 'daily') return [$date->modify('-1 day')->format('Y-m-d'), $date->modify('-1 day')->format('Y-m-d')];
    if ($frequency === 'weekly') { if ((int)$date->format('w') !== $reports['weekday']) return null; return [$date->modify('-7 days')->format('Y-m-d'), $date->modify('-1 day')->format('Y-m-d')]; }
    if ($frequency === 'monthly') {
        if ((int)$date->format('j') !== min($reports['monthDay'], (int)$date->format('t'))) return null;
        $first = $date->modify('first day of last month'); return [$first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')];
    }
    $anchor = new DateTimeImmutable($reports['startDate'], $local->getTimezone()); $days = (int)$anchor->diff($date)->format('%r%a'); if ($days < 0 || $days % $reports['intervalDays'] !== 0) return null;
    return [$date->modify('-' . $reports['intervalDays'] . ' days')->format('Y-m-d'), $date->modify('-1 day')->format('Y-m-d')];
}

function bipolar_aut_time_minutes(string $time): int { return ((int)substr($time, 0, 2)) * 60 + (int)substr($time, 3, 2); }
function bipolar_aut_in_quiet(string $time, string $start, string $end): bool
{
    if ($start === $end) return false; $t = bipolar_aut_time_minutes($time); $s = bipolar_aut_time_minutes($start); $e = bipolar_aut_time_minutes($end);
    return $s < $e ? ($t >= $s && $t < $e) : ($t >= $s || $t < $e);
}
function bipolar_aut_reminder_slots(array $reminders, DateTimeImmutable $local): array
{
    if (!$reminders['enabled'] || $reminders['channel'] === 'in-app' || !in_array((int)$local->format('w'), $reminders['days'], true)) return [];
    $now = $local->format('H:i'); $out = [];
    foreach (array_merge($reminders['slots'], $reminders['custom']) as $slot) if (($slot['enabled'] ?? false) && bipolar_aut_valid_time($slot['time'] ?? null)) {
        $scheduled = $local->setTime((int)substr($slot['time'], 0, 2), (int)substr($slot['time'], 3, 2));
        if ($local->getTimestamp() >= $scheduled->getTimestamp() && $local->getTimestamp() - $scheduled->getTimestamp() <= 300 && !bipolar_aut_in_quiet($now, $reminders['quietStart'], $reminders['quietEnd']) && !bipolar_aut_in_quiet($slot['time'], $reminders['quietStart'], $reminders['quietEnd'])) $out[] = $slot;
    }
    return $out;
}

function bipolar_aut_entries_for_report(PDO $pdo, array $config, string $userId, string $from, string $to, bool $includeNotes, bool $includeDetails): array
{
    $stmt = $pdo->prepare('SELECT id, date_value, period, payload_cipher FROM entries WHERE user_id = ? AND date_value BETWEEN ? AND ? ORDER BY date_value ASC, FIELD(period, "morning", "afternoon", "night") ASC'); $stmt->execute([$userId, $from, $to]); $records = []; $distribution = ['Depressão' => 0, 'Muito baixo' => 0, 'Baixo' => 0, 'Estável' => 0, 'Elevado' => 0, 'Muito elevado' => 0, 'Euforia' => 0]; $days = [];
    $labels = [-3 => 'Depressão', -2 => 'Muito baixo', -1 => 'Baixo', 0 => 'Estável', 1 => 'Elevado', 2 => 'Muito elevado', 3 => 'Euforia'];
    while ($row = $stmt->fetch()) {
        try { $payload = json_decode(bipolar_decrypt((string)$row['payload_cipher'], $config['app']['encryption_key']), true, 64, JSON_THROW_ON_ERROR); } catch (Throwable $e) { throw new BipolarApiException('data_unavailable', 500); }
        if (!is_array($payload) || !is_int($payload['mood'] ?? null)) continue; $mood = (int)$payload['mood']; $label = $labels[$mood] ?? 'estável'; $distribution[$label]++; $days[(string)$row['date_value']] = true;
        $item = ['id' => (string)$row['id'], 'date' => (string)$row['date_value'], 'period' => (string)$row['period'], 'mood' => $mood, 'moodLabel' => $label];
        if ($includeNotes) $item['note'] = (string)($payload['note'] ?? '');
        if ($includeDetails) $item['details'] = ['sleep' => $payload['sleep'] ?? null, 'medication' => $payload['medication'] ?? '', 'mixed' => (bool)($payload['mixed'] ?? false), 'symptoms' => is_array($payload['symptoms'] ?? null) ? array_values($payload['symptoms']) : [], 'factors' => is_array($payload['factors'] ?? null) ? array_values($payload['factors']) : []];
        $records[] = $item;
    }
    return ['from' => $from, 'to' => $to, 'daysWithRecords' => count($days), 'recordCount' => count($records), 'moodDistribution' => $distribution, 'records' => $records];
}

function bipolar_aut_escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function bipolar_aut_period_label(string $period): string { return ['morning' => 'manhã', 'afternoon' => 'tarde', 'night' => 'noite'][$period] ?? $period; }

function bipolar_aut_queue(PDO $pdo, array $config, string $userId, string $type, string $channel, string $eventKey, ?string $recipientId, ?string $subscriptionId, string $recipientLabel, array $payload, bool $automated): bool
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $tooLarge = strlen($json) > 8 * 1024 * 1024;
    if ($tooLarge && !$automated) throw new BipolarApiException('report_too_large', 422);
    if ($tooLarge) $json = json_encode(['source'=>'automated','error'=>'report_too_large'], JSON_THROW_ON_ERROR);
    $id=bipolar_uuid();$now=bipolar_db_now();$cipher=bipolar_encrypt($json,$config['app']['encryption_key']);
    $labelCipher=bipolar_encrypt(bipolar_clean_text($recipientLabel,191),$config['app']['encryption_key']);$targetKey=$subscriptionId ?? ($recipientId ?? 'user');
    $status=$tooLarge?'failed':'queued';$ownTransaction=!$pdo->inTransaction();if($ownTransaction)$pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('INSERT IGNORE INTO automation_outbox(id,user_id,type,channel,event_key,target_key,recipient_id,subscription_id,recipient_label_cipher,payload_cipher,is_automated,status,available_at,attempts,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,0,?)');
        $stmt->execute([$id,$userId,$type,$channel,$eventKey,$targetKey,$recipientId,$subscriptionId,$labelCipher,$cipher,$automated?1:0,$status,$now,$now]);
        if($stmt->rowCount()!==1){if($ownTransaction)$pdo->commit();return false;}
        $stmt=$pdo->prepare('INSERT INTO automation_deliveries(id,outbox_id,user_id,type,channel,status,created_at,recipient_label_cipher,message) VALUES(?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$id,$id,$userId,$type,$channel,$status,$now,$labelCipher,$tooLarge?'Relatório excede 8 MiB. Reduza o período ou o conteúdo.':($type==='report'?'Relatório enfileirado.':'Lembrete enfileirado.')]);
        if($ownTransaction)$pdo->commit();return !$tooLarge;
    } catch(Throwable $e){if($ownTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function bipolar_aut_send_report(PDO $pdo, array $config, string $userId, array $body): array
{
    if (bipolar_aut_smtp_config($config) === null) throw new BipolarApiException('automation_not_configured', 503);
    $from = $body['from'] ?? null; $to = $body['to'] ?? null; $tz = (string)($config['app']['timezone'] ?? 'America/Sao_Paulo'); $today = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');
    $from = bipolar_aut_date($from, $tz); $to = bipolar_aut_date($to, $tz); if ($from > $to || $to > $today || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 365) throw new BipolarApiException('invalid_report_period', 422);
    if (($body['consent'] ?? null) !== true || !is_bool($body['includeNotes'] ?? null) || !is_bool($body['includeDetails'] ?? null) || !is_string($body['idempotencyKey'] ?? null) || !bipolar_is_uuid($body['idempotencyKey'])) throw new BipolarApiException('invalid_automation', 422);
    $ids = $body['recipientIds'] ?? []; if (!is_array($ids) || count($ids) < 1 || count($ids) > 10) throw new BipolarApiException('recipient_limit', 422); $ids = array_values(array_unique($ids)); if (count($ids) > 10) throw new BipolarApiException('recipient_limit', 422);
    $settings = bipolar_aut_load_settings($pdo, $config, $userId);
    $report = bipolar_aut_entries_for_report($pdo, $config, $userId, $from, $to, $body['includeNotes'], $body['includeDetails']); $queued = 0; if (!is_string($body['expectedFingerprint'] ?? null) || !hash_equals(bipolar_aut_report_fingerprint($report), $body['expectedFingerprint'])) throw new BipolarApiException('report_changed', 409); if ($report['recordCount'] === 0) throw new BipolarApiException('empty_report', 422); $pdo->beginTransaction();
    try {
        foreach ($ids as $id) {
            if (!is_string($id) || !bipolar_is_uuid($id)) throw new BipolarApiException('invalid_recipient', 422); $recipient = null; foreach ($settings['reports']['recipients'] as $item) if ($item['id'] === $id) { $recipient = $item; break; }
            if ($recipient === null) throw new BipolarApiException('invalid_recipient', 422); $expected = null; foreach (($body['expectedRecipients'] ?? []) as $target) if (is_array($target) && ($target['id'] ?? null) === $id) $expected = $target['email'] ?? null; if (!is_string($expected) || !hash_equals($recipient['email'], $expected)) throw new BipolarApiException('report_changed', 409); $rendered = bipolar_aut_render_report($report, $recipient['name']); $payload = ['source' => 'manual', 'recipientEmail' => $recipient['email'], 'recipientName' => $recipient['name'], 'subject' => $rendered['subject'], 'text' => $rendered['text'], 'html' => $rendered['html']];
            if (bipolar_aut_queue($pdo, $config, $userId, 'report', 'email', 'manual:' . $body['idempotencyKey'], $recipient['id'], null, $recipient['name'], $payload, false)) $queued++;
        } $pdo->commit();
    } catch (BipolarApiException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw new BipolarApiException('automation_queue_failed', 500); }
    return ['status' => 'queued', 'queued' => $queued];
}

function bipolar_aut_push_endpoint(string $endpoint): string
{
    if (strlen($endpoint) < 1 || strlen($endpoint) > 2048 || !filter_var($endpoint, FILTER_VALIDATE_URL)) throw new BipolarApiException('invalid_push_subscription', 422);
    $parts = parse_url($endpoint); $host = strtolower((string)($parts['host'] ?? '')); $scheme = strtolower((string)($parts['scheme'] ?? '')); $port = $parts['port'] ?? null;
    $allowed = $host === 'fcm.googleapis.com' || $host === 'updates.push.services.mozilla.com' || preg_match('/^[a-z0-9.-]+\.push\.services\.mozilla\.com$/', $host) === 1 || preg_match('/^[a-z0-9.-]+\.notify\.windows\.com$/', $host) === 1 || preg_match('/^[a-z0-9.-]+\.push\.apple\.com$/', $host) === 1;
    if ($scheme !== 'https' || ($port !== null && (int)$port !== 443) || !$allowed || isset($parts['user']) || isset($parts['pass'])) throw new BipolarApiException('invalid_push_subscription', 422);
    return $endpoint;
}
function bipolar_aut_b64url(string $value, int $bytes): string
{
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) throw new BipolarApiException('invalid_push_subscription', 422); $decoded = base64_decode(strtr($value, '-_', '+/'), true); if ($decoded === false || strlen($decoded) !== $bytes) throw new BipolarApiException('invalid_push_subscription', 422); return $decoded;
}
function bipolar_aut_push_subscribe(PDO $pdo, array $config, string $userId, array $body): array
{
    $sub = $body['subscription'] ?? null; if (!is_array($sub) || !is_string($sub['endpoint'] ?? null) || !is_array($sub['keys'] ?? null) || !is_string($sub['keys']['p256dh'] ?? null) || !is_string($sub['keys']['auth'] ?? null)) throw new BipolarApiException('invalid_push_subscription', 422);
    $endpoint = bipolar_aut_push_endpoint($sub['endpoint']); $p256dh = bipolar_aut_b64url($sub['keys']['p256dh'], 65); $auth = bipolar_aut_b64url($sub['keys']['auth'], 16); $hash = hash('sha256', $endpoint); $now = bipolar_db_now();
    $existing = $pdo->prepare('SELECT user_id FROM push_subscriptions WHERE endpoint_hash = ? LIMIT 1'); $existing->execute([$hash]); $owner = $existing->fetch(); if (is_array($owner) && (string)$owner['user_id'] !== $userId) throw new BipolarApiException('subscription_conflict', 409);
    if (is_array($owner) === false) { $count = $pdo->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ? AND disabled_at IS NULL'); $count->execute([$userId]); if ((int)$count->fetchColumn() >= 10) throw new BipolarApiException('subscription_limit', 422); }
    $stmt = $pdo->prepare('INSERT INTO push_subscriptions (id, user_id, endpoint_hash, endpoint_cipher, p256dh_cipher, auth_cipher, created_at, last_seen_at, disabled_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL) ON DUPLICATE KEY UPDATE endpoint_cipher = VALUES(endpoint_cipher), p256dh_cipher = VALUES(p256dh_cipher), auth_cipher = VALUES(auth_cipher), last_seen_at = VALUES(last_seen_at), disabled_at = NULL'); $stmt->execute([bipolar_uuid(), $userId, $hash, bipolar_encrypt($endpoint, $config['app']['encryption_key']), bipolar_encrypt($p256dh, $config['app']['encryption_key']), bipolar_encrypt($auth, $config['app']['encryption_key']), $now, $now]);
    return ['ok' => true];
}
function bipolar_aut_push_unsubscribe(PDO $pdo, array $config, string $userId, array $body): array
{
    $endpoint = $body['endpoint'] ?? null; if (!is_string($endpoint)) throw new BipolarApiException('invalid_push_subscription', 422); $endpoint = bipolar_aut_push_endpoint($endpoint); $stmt = $pdo->prepare('UPDATE push_subscriptions SET disabled_at = ? WHERE user_id = ? AND endpoint_hash = ?'); $stmt->execute([bipolar_db_now(), $userId, hash('sha256', $endpoint)]); return ['ok' => true];
}

function bipolar_aut_delivery_history(PDO $pdo, array $config, string $userId): array
{
    $stmt = $pdo->prepare("SELECT d.id, d.type, d.channel, o.status, d.created_at, o.last_attempt_at, d.recipient_label_cipher, CASE WHEN o.status = 'unknown' THEN 'Resultado inconclusivo. Não será repetido automaticamente.' ELSE d.message END AS message FROM automation_deliveries d INNER JOIN automation_outbox o ON o.id = d.outbox_id WHERE d.user_id = ? ORDER BY d.created_at DESC LIMIT 30"); $stmt->execute([$userId]); $out = [];
    while ($row = $stmt->fetch()) { try { $label = bipolar_decrypt((string)$row['recipient_label_cipher'], $config['app']['encryption_key']); } catch (Throwable $e) { throw new BipolarApiException('data_unavailable', 500); } $out[] = ['id' => (string)$row['id'], 'type' => (string)$row['type'], 'channel' => (string)$row['channel'], 'status' => (string)$row['status'], 'createdAt' => bipolar_db_to_iso((string)$row['created_at']), 'lastAttemptAt' => $row['last_attempt_at'] === null ? null : bipolar_db_to_iso((string)$row['last_attempt_at']), 'recipientLabel' => $label, 'message' => (string)$row['message']]; }
    return ['deliveries' => $out];
}

function bipolar_aut_heartbeat(PDO $pdo): void
{
    $now = bipolar_db_now(); $stmt = $pdo->prepare('INSERT INTO automation_cron_heartbeat (id, last_run_at) VALUES (1, ?) ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at)'); $stmt->execute([$now]);
}
function bipolar_aut_cancel_if_revoked(PDO $pdo, string $userId, array $settings, string $type, string $outboxId, bool $automated): bool
{
    $revoked = $automated && (($type === 'report' && (!$settings['reports']['enabled'] || !$settings['reports']['consent'])) || ($type === 'reminder' && !$settings['reminders']['enabled']));
    if ($revoked) { $now = bipolar_db_now(); $stmt = $pdo->prepare("UPDATE automation_outbox SET status = 'failed', failure_code = 'cancelled', last_attempt_at = ? WHERE id = ? AND user_id = ? AND status = 'queued'"); $stmt->execute([$now, $outboxId, $userId]); $stmt = $pdo->prepare("UPDATE automation_deliveries SET status = 'failed', last_attempt_at = ?, message = 'Cancelado antes do envio.' WHERE id = ? AND user_id = ? AND status = 'queued'"); $stmt->execute([$now, $outboxId, $userId]); return true; } return false;
}

function bipolar_aut_now_utc(array $config): DateTimeImmutable
{
    $clock = $config['automation']['clock'] ?? null;
    if (is_callable($clock)) {
        $value = $clock();
        if ($value instanceof DateTimeImmutable) return $value->setTimezone(new DateTimeZone('UTC'));
        if (is_string($value)) try { return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC')); } catch (Throwable $e) { throw new RuntimeException('clock'); }
    }
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function bipolar_aut_send_email(array $config, array $payload): void
{
    $smtp = bipolar_aut_smtp_config($config); if ($smtp === null) throw new RuntimeException('smtp_not_configured'); require_once __DIR__ . '/vendor/autoload.php';
    $mail = new PHPMailer\PHPMailer\PHPMailer(true); $mail->isSMTP(); $mail->Host = $smtp['host']; $mail->SMTPAuth = true; $mail->Username = $smtp['username']; $mail->Password = $smtp['password']; $mail->Port = $smtp['port']; $mail->SMTPSecure = $smtp['port'] === 465 ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; $mail->CharSet = 'UTF-8'; $mail->Timeout = 15; $mail->SMTPDebug = 0; $mail->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]; $mail->setFrom($smtp['from_email'], $smtp['from_name']); $mail->addAddress((string)$payload['recipientEmail'], (string)($payload['recipientName'] ?? '')); $mail->Subject = (string)$payload['subject']; $mail->Body = (string)$payload['html']; $mail->AltBody = (string)$payload['text']; $mail->isHTML(true); $mail->send();
}
function bipolar_aut_send_push(PDO $pdo, array $config, array $job, array $payload): void
{
    $vapid = bipolar_aut_vapid_config($config); if ($vapid === null) throw new RuntimeException('push_not_configured'); require_once __DIR__ . '/vendor/autoload.php';
    $stmt = $pdo->prepare('SELECT endpoint_cipher, p256dh_cipher, auth_cipher FROM push_subscriptions WHERE id = ? AND user_id = ? AND disabled_at IS NULL LIMIT 1'); $stmt->execute([$job['subscription_id'], $job['user_id']]); $row = $stmt->fetch(); if (!is_array($row)) throw new RuntimeException('subscription_gone');
    try { $endpoint = bipolar_decrypt((string)$row['endpoint_cipher'], $config['app']['encryption_key']); $p256dh = bipolar_decrypt((string)$row['p256dh_cipher'], $config['app']['encryption_key']); $auth = bipolar_decrypt((string)$row['auth_cipher'], $config['app']['encryption_key']); } catch (Throwable $e) { throw new RuntimeException('subscription_data'); }
    $webPush = new Minishlink\WebPush\WebPush(['VAPID' => ['subject' => $vapid['subject'], 'publicKey' => $vapid['public_key'], 'privateKey' => $vapid['private_key']]], ['TTL' => 300, 'urgency' => 'normal'], 15, ['allow_redirects' => false, 'verify' => true]); $report = $webPush->sendOneNotification(Minishlink\WebPush\Subscription::create(['endpoint' => bipolar_aut_push_endpoint($endpoint), 'keys' => ['p256dh' => bipolar_base64url($p256dh), 'auth' => bipolar_base64url($auth)], 'contentEncoding' => 'aes128gcm']), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if (!$report->isSuccess()) { if ($report->isSubscriptionExpired()) { $stmt = $pdo->prepare('UPDATE push_subscriptions SET disabled_at = ? WHERE id = ? AND user_id = ?'); $stmt->execute([bipolar_db_now(), $job['subscription_id'], $job['user_id']]); } throw new RuntimeException('push_delivery_failed'); }
}

function bipolar_aut_enqueue_automated(PDO $pdo, array $config, string $userId, array $settings, DateTimeImmutable $utcNow): int
{
    $queued = 0; $reportTimezone = new DateTimeZone($settings['reports']['timezone']); $localReport = $utcNow->setTimezone($reportTimezone); $period = bipolar_aut_report_period($settings['reports'], $localReport);
    if ($period !== null && bipolar_aut_smtp_config($config) !== null) {
        $report = bipolar_aut_entries_for_report($pdo, $config, $userId, $period[0], $period[1], $settings['reports']['includeNotes'], $settings['reports']['includeDetails']);
        foreach ($settings['reports']['recipients'] as $recipient) { $rendered = bipolar_aut_render_report($report, $recipient['name']); $key = 'auto:' . $settings['reports']['frequency'] . ':' . $period[0] . ':' . $period[1]; $payload = ['source' => 'automated', 'recipientEmail' => $recipient['email'], 'recipientName' => $recipient['name'], 'subject' => $rendered['subject'], 'text' => $rendered['text'], 'html' => $rendered['html']]; if (bipolar_aut_queue($pdo, $config, $userId, 'report', 'email', $key, $recipient['id'], null, $recipient['name'], $payload, true)) $queued++; }
    }
    $reminderTimezone = new DateTimeZone($settings['reminders']['timezone']); $localReminder = $utcNow->setTimezone($reminderTimezone); $slots = bipolar_aut_reminder_slots($settings['reminders'], $localReminder); if ($slots === []) return $queued;
    foreach ($slots as $slot) {
        $periodName = $slot['period'] ?? null; if ($settings['reminders']['skipRecorded']) { $stmt = $pdo->prepare('SELECT period FROM entries WHERE user_id = ? AND date_value = ?'); $stmt->execute([$userId, $localReminder->format('Y-m-d')]); $periods = array_map('strval', array_column($stmt->fetchAll(), 'period')); if ($periodName !== null && in_array($periodName, $periods, true)) continue; if ($periodName === null && count(array_unique($periods)) >= 3) continue; }
        $generic = ['title' => 'Um momento para você', 'body' => 'Se quiser, faça uma anotação no seu diário.', 'url' => '/', 'tag' => 'bipolar-reminder-' . $localReminder->format('Y-m-d') . '-' . $slot['id']]; $event = 'auto:' . $localReminder->format('Y-m-d') . ':' . $slot['id'];
        if ($settings['reminders']['channel'] === 'email' && $settings['reminders']['email'] !== '' && bipolar_aut_smtp_config($config) !== null) {
            $payload = ['source' => 'automated', 'recipientEmail' => $settings['reminders']['email'], 'recipientName' => '', 'subject' => 'A hora do seu cuidado', 'text' => 'Um momento para você. Abra seu diário quando puder.', 'html' => '<!doctype html><meta charset="utf-8"><p>Um momento para você. Abra seu diário quando puder.</p>'];
            if (bipolar_aut_queue($pdo, $config, $userId, 'reminder', 'email', $event . ':email', null, null, 'Você', $payload, true)) $queued++;
        } elseif ($settings['reminders']['channel'] === 'push' && bipolar_aut_vapid_config($config) !== null) {
            $stmt = $pdo->prepare('SELECT id FROM push_subscriptions WHERE user_id = ? AND disabled_at IS NULL'); $stmt->execute([$userId]);
            foreach ($stmt->fetchAll() as $subscription) if (bipolar_aut_queue($pdo, $config, $userId, 'reminder', 'push', $event . ':push:' . (string)$subscription['id'], null, (string)$subscription['id'], 'Seu dispositivo', ['source' => 'automated', 'push' => $generic], true)) $queued++;
        }
    }
    return $queued;
}

function bipolar_aut_mark_delivery(PDO $pdo, array $job, string $status, ?string $failureCode = null): void
{
    $now = bipolar_db_now(); $message = $status === 'sent' ? ($job['type'] === 'report' ? 'Relatório enviado.' : 'Lembrete enviado.') : ($status === 'failed' ? 'Não foi possível entregar.' : 'Resultado inconclusivo. Não será repetido automaticamente.');
    $stmt = $pdo->prepare('UPDATE automation_outbox SET status = ?, attempts = attempts + IF(status = \'queued\', 1, 0), last_attempt_at = ?, failure_code = ? WHERE id = ? AND status IN (\'queued\', \'unknown\')'); $stmt->execute([$status, $now, $failureCode, $job['id']]);
    $stmt = $pdo->prepare('UPDATE automation_deliveries SET status = ?, last_attempt_at = ?, message = ? WHERE id = ? AND user_id = ?'); $stmt->execute([$status, $now, $message, $job['id'], $job['user_id']]);
}

function bipolar_aut_worker(PDO $pdo, array $config, int $limit = 100): array
{
    $limit = min(100, max(1, $limit)); $sent = 0; $failed = 0; $unknown = 0;
    $stmt = $pdo->prepare("SELECT id FROM automation_outbox WHERE status = 'queued' AND available_at <= ? ORDER BY created_at ASC LIMIT " . $limit);
    $stmt->execute([bipolar_db_now()]); $jobs = $stmt->fetchAll();
    $startedAt = microtime(true);
    foreach ($jobs as $queuedJob) {
        if (microtime(true) - $startedAt >= 50) break;
        $load = $pdo->prepare("SELECT * FROM automation_outbox WHERE id = ? AND status = 'queued' LIMIT 1"); $load->execute([$queuedJob['id']]); $job = $load->fetch();
        if (!is_array($job)) continue;
        $userId = (string)$job['user_id']; $attemptStarted = false;
        try {
            $settings = bipolar_aut_load_settings($pdo, $config, $userId);
            if (bipolar_aut_cancel_if_revoked($pdo, $userId, $settings, (string)$job['type'], (string)$job['id'], (bool)$job['is_automated'])) { $failed++; continue; }
            $payload = json_decode(bipolar_decrypt((string)$job['payload_cipher'], $config['app']['encryption_key']), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) throw new RuntimeException('payload');
            if ($job['type'] === 'report') {
                $recipient = null; foreach ($settings['reports']['recipients'] as $item) if ($item['id'] === $job['recipient_id']) $recipient = $item;
                if (!$recipient || $recipient['email'] !== ($payload['recipientEmail'] ?? null)) throw new RuntimeException('recipient_revoked');
            }
            if ($job['type'] === 'reminder' && $job['is_automated']) {
                $local = bipolar_aut_now_utc($config)->setTimezone(new DateTimeZone($settings['reminders']['timezone']));
                if ($settings['reminders']['channel'] !== $job['channel'] || bipolar_aut_in_quiet($local->format('H:i'), $settings['reminders']['quietStart'], $settings['reminders']['quietEnd']) || bipolar_aut_now_utc($config)->getTimestamp() - (new DateTimeImmutable($job['created_at'], new DateTimeZone('UTC')))->getTimestamp() > 300) throw new RuntimeException('reminder_expired');
                $parts = explode(':', $job['event_key']); $slotId = $parts[2] ?? ''; $slot = null;
                foreach (array_merge($settings['reminders']['slots'], $settings['reminders']['custom']) as $item) if ($item['id'] === $slotId && $item['enabled']) $slot = $item;
                if (!$slot) throw new RuntimeException('reminder_revoked');
                if ($settings['reminders']['skipRecorded']) {
                    $check = $pdo->prepare('SELECT period FROM entries WHERE user_id = ? AND date_value = ?'); $check->execute([$userId, $local->format('Y-m-d')]); $periods = array_column($check->fetchAll(), 'period');
                    if (($slot['period'] !== null && in_array($slot['period'], $periods, true)) || ($slot['period'] === null && count(array_unique($periods)) >= 3)) throw new RuntimeException('already_recorded');
                }
            }
            if (!bipolar_aut_claim_delivery($pdo, $job)) { $failed++; continue; } $attemptStarted = true;
            if (isset($config['automation']['send_callback']) && is_callable($config['automation']['send_callback'])) ($config['automation']['send_callback'])($job, $payload);
            elseif ($job['channel'] === 'email') bipolar_aut_send_email($config, $payload);
            elseif ($job['channel'] === 'push') bipolar_aut_send_push($pdo, $config, $job, $payload['push'] ?? []);
            else throw new RuntimeException('channel');
            bipolar_aut_mark_delivery($pdo, $job, 'sent'); $sent++;
        } catch (Throwable $e) {
            try {
                bipolar_aut_mark_delivery($pdo, $job, $attemptStarted ? 'unknown' : 'failed', $attemptStarted ? 'provider_result_unknown' : 'cancelled_before_attempt');
                if ($attemptStarted) $unknown++; else $failed++;
            } catch (Throwable $dbError) { $unknown++; error_log('[bipolar] automation delivery status unknown'); }
        }
    }
    return ['sent' => $sent, 'failed' => $failed, 'unknown' => $unknown, 'processed' => count($jobs)];
}

function bipolar_aut_cron_lock(PDO $pdo): bool
{
    try { $stmt = $pdo->query("SELECT GET_LOCK('bipolar_automation_cron', 0) AS locked"); $row = $stmt->fetch(); return is_array($row) && (int)$row['locked'] === 1; }
    catch (Throwable $e) { return false; }
}
function bipolar_aut_cron_unlock(PDO $pdo): void { try { $pdo->query("SELECT RELEASE_LOCK('bipolar_automation_cron')"); } catch (Throwable $e) {} }
function bipolar_aut_cron_run(PDO $pdo, array $config): array
{
    if (PHP_SAPI !== 'cli') throw new RuntimeException('cli_only');
    if (!bipolar_aut_cron_lock($pdo)) return ['locked' => true, 'queued' => 0, 'sent' => 0, 'failed' => 0, 'unknown' => 0];
    $queued = 0; $result = ['locked' => false, 'queued' => 0, 'sent' => 0, 'failed' => 0, 'unknown' => 0];
    try {
        bipolar_aut_heartbeat($pdo); $now = bipolar_aut_now_utc($config);
        $stmt = $pdo->query('SELECT user_id, settings_cipher FROM automation_settings');
        while ($row = $stmt->fetch()) {
            try {
                $settings = bipolar_aut_load_settings($pdo, $config, (string)$row['user_id']); $queued += bipolar_aut_enqueue_automated($pdo, $config, (string)$row['user_id'], $settings, $now);
            } catch (Throwable $e) { error_log('[bipolar] automation scheduling skipped'); }
        }
        $result['queued'] = $queued; $worker = bipolar_aut_worker($pdo, $config, (int)($config['automation']['max_deliveries_per_run'] ?? 100)); foreach (['sent', 'failed', 'unknown'] as $key) $result[$key] = $worker[$key];
    } finally { bipolar_aut_cron_unlock($pdo); }
    return $result;
}
