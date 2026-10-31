<?php
/**
 * V5.1: o que cada tipo de envio da fila faz (lembrete, adiamento de 30 min, relatório por e-mail).
 * Enviar = só envia e devolve [classe, código]; marcar como enviado acontece em 'done', depois da confirmação.
 */
require_once APP_ROOT . '/lib/outbox.php';
require_once APP_ROOT . '/lib/smtp.php';
require_once APP_ROOT . '/lib/webpush.php';

/**
 * Junta as classes de falha de vários canais: passageira vence desconhecida, que vence permanente.
 * @param list<string> $classes
 * @return array{0:string,1:string}
 */
function outbox_merge_failures(array $classes, string $code): array
{
    if (!$classes) return ['permanent', 'sem_destino'];
    foreach (['transient', 'unknown'] as $c) if (in_array($c, $classes, true)) return [$c, $code];
    return ['permanent', $code];
}

/** @return array<string, array{send: callable, done?: callable, dead?: callable}> */
function outbox_reminder_handlers(): array
{
    return [
        'reminder' => [
            'send' => function (PDO $pdo, array $row): array {
                $st = $pdo->prepare('SELECT r.*, u.timezone, u.email, u.name FROM reminders r JOIN users u ON u.id = r.user_id WHERE r.id = ? AND r.user_id = ?');
                $st->execute([$row['ref_id'], $row['user_id']]);
                $r = $st->fetch();
                if (!$r || !(int)$r['active']) return ['cancel', 'lembrete_removido'];
                if ((string)$r['last_sent_local_date'] === (string)$row['local_date']) return ['cancel', 'ja_enviado'];
                $classes = []; $code = '';
                $delivered = false;
                if (push_configured() && in_array($r['channel'], ['push', 'ambos'], true)) {
                    // Payload criptografado (RFC 8291) com texto genérico e ações "Registrar agora" / "Lembrar em 30 min"
                    $p = push_to_user_ex((int)$r['user_id'], reminder_payload((string)$r['timezone']));
                    if ($p['ok'] > 0) $delivered = true;
                    foreach ($p['codes'] as $c) { $k = outbox_classify_http($c); if ($k !== 'ok') { $classes[] = $k; $code = 'push_' . $c; } }
                }
                // E-mail: quando escolhido, ou como alternativa se o push falhou (só para o e-mail confirmado da conta)
                if ($r['email'] && ($r['channel'] !== 'push' || !$delivered)) {
                    $html = email_layout('Hora do seu registro de humor', '<p>Olá' . ($r['name'] ? ', ' . e(explode(' ', $r['name'])[0]) : '') . '! Como você está agora?</p>'
                        . '<p><a href="' . e(rtrim(cfg('app_url'), '/')) . '/?aba=registrar" style="display:inline-block;background:#2f6f73;color:#fff;padding:12px 20px;border-radius:10px;text-decoration:none">Registrar humor</a></p>'
                        . '<p style="font-size:12px;color:#50606c">Para mudar ou desativar lembretes, acesse Lembretes no aplicativo.</p>');
                    $m = send_mail_ex((string)$r['email'], 'Lembrete: registre seu humor', $html, '', 'lembrete');
                    $k = outbox_classify_mail($m);
                    if ($k === 'ok') $delivered = true; else { $classes[] = $k; $code = 'smtp_' . $m['stage'] . '_' . $m['code']; }
                }
                return $delivered ? ['ok', ''] : outbox_merge_failures($classes, $code);
            },
            // só depois da entrega confirmada: não repete o lembrete neste dia
            'done' => function (PDO $pdo, array $row): void {
                $pdo->prepare('UPDATE reminders SET last_sent_local_date = ? WHERE id = ?')->execute([$row['local_date'], $row['ref_id']]);
            },
        ],
        'snooze' => [
            'send' => function (PDO $pdo, array $row): array {
                $st = $pdo->prepare('SELECT s.id, s.endpoint, s.p256dh, s.auth, s.snooze_until, u.timezone FROM push_subscriptions s JOIN users u ON u.id = s.user_id WHERE s.id = ? AND s.user_id = ?');
                $st->execute([$row['ref_id'], $row['user_id']]);
                $s = $st->fetch();
                if (!$s) return ['cancel', 'aparelho_removido'];
                if ((string)$s['snooze_until'] !== (string)$row['ref_value']) return ['cancel', 'adiamento_mudou'];
                if (!push_configured()) return ['transient', 'push_desligado'];
                $code = send_push($s, reminder_payload((string)$s['timezone']));
                if (in_array($code, [404, 410], true)) $pdo->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$s['id']]);
                $k = outbox_classify_http($code);
                return [$k, 'push_' . $code];
            },
            // consome o adiamento só depois da entrega confirmada (e só aquele adiamento)
            'done' => function (PDO $pdo, array $row): void {
                $pdo->prepare('UPDATE push_subscriptions SET snooze_until = NULL WHERE id = ? AND snooze_until = ?')->execute([$row['ref_id'], $row['ref_value']]);
            },
        ],
    ];
}

/** Coloca na fila os adiamentos ("Lembrar em 30 min") vencidos nas últimas 24 h. */
function snoozes_enqueue_due(PDO $pdo): int
{
    $st = $pdo->query('SELECT id, user_id, snooze_until FROM push_subscriptions WHERE snooze_until IS NOT NULL AND snooze_until <= UTC_TIMESTAMP() AND snooze_until > UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 5000');
    $n = 0;
    foreach ($st ? $st->fetchAll() : [] as $s) {
        $due = (string)$s['snooze_until'];
        if (outbox_enqueue($pdo, 'snooze', (int)$s['user_id'], (int)$s['id'], outbox_dedupe('snooze', (string)$s['id'], $due), $due, null,
            gmdate('Y-m-d H:i:s', (int)strtotime($due . ' UTC') + 7200), $due)) $n++;
    }
    return $n;
}

/**
 * Próximo envio agendado do destinatário, a partir de agora.
 * @param array<string,mixed> $r
 */
function report_advance(PDO $pdo, array $r, string $from, bool $sent): void
{
    $next = next_send_at($r, (string)$r['timezone']);
    $pdo->prepare('UPDATE recipients SET next_send_at = ?' . ($sent ? ', last_sent_at = UTC_TIMESTAMP()' : '') . ' WHERE id = ? AND next_send_at = ?')->execute([$next, $r['id'], $from]);
}

/**
 * Relatórios: põe na fila quem está na hora. Se o envio deste período já terminou sem sucesso (dead/expired/cancelled),
 * pula para o próximo período (não fica preso, não repete).
 */
function reports_enqueue_due(PDO $pdo): int
{
    $st = $pdo->query('SELECT r.*, u.timezone FROM recipients r JOIN users u ON u.id = r.user_id
        WHERE r.confirmed_at IS NOT NULL AND r.unsubscribed_at IS NULL AND r.next_send_at <= UTC_TIMESTAMP() ORDER BY r.next_send_at LIMIT 5000');
    $chk = $pdo->prepare('SELECT state FROM outbox WHERE dedupe_key = ?');
    $n = 0;
    foreach ($st ? $st->fetchAll() : [] as $r) {
        $due = (string)$r['next_send_at'];
        $key = outbox_dedupe('report', (string)$r['id'], $due);
        if (outbox_enqueue($pdo, 'report', (int)$r['user_id'], (int)$r['id'], $key, $due, null, gmdate('Y-m-d H:i:s', (int)strtotime($due . ' UTC') + 3 * 86400), $due)) { $n++; continue; }
        $chk->execute([$key]);
        $state = (string)$chk->fetchColumn();
        if (in_array($state, ['dead', 'expired', 'cancelled', 'sent'], true)) report_advance($pdo, $r, $due, false);
    }
    return $n;
}

/** @return array<string, array{send: callable, done?: callable, dead?: callable}> */
function outbox_report_handlers(): array
{
    require_once APP_ROOT . '/lib/report.php';
    require_once APP_ROOT . '/lib/schedule.php';
    require_once APP_ROOT . '/lib/custom_alerts.php';
    $load = function (PDO $pdo, array $row): ?array {
        $st = $pdo->prepare('SELECT r.*, u.name AS uname, u.timezone, u.id AS uid FROM recipients r JOIN users u ON u.id = r.user_id WHERE r.id = ? AND r.user_id = ?');
        $st->execute([$row['ref_id'], $row['user_id']]);
        $r = $st->fetch();
        return $r ?: null;
    };
    return ['report' => [
        'send' => function (PDO $pdo, array $row) use ($load): array {
            $r = $load($pdo, $row);
            if (!$r || $r['confirmed_at'] === null || $r['unsubscribed_at'] !== null) return ['cancel', 'destinatario_inativo'];
            if ((string)$r['next_send_at'] !== (string)$row['ref_value']) return ['cancel', 'agenda_mudou'];
            $tz = new DateTimeZone($r['timezone']);
            $to = (new DateTime('yesterday', $tz))->format('Y-m-d');
            $from = (new DateTime('today', $tz))->modify('-' . report_period_days($r) . ' days')->format('Y-m-d');
            $user = ['id' => $r['uid'], 'name' => $r['uname']];
            $data = report_data((int)$r['uid'], $from, $to);
            $cancel = rtrim(cfg('app_url'), '/') . '/confirmar.php?cancelar=1&t=' . $r['confirm_token'];
            $html = email_layout('Relatório de humor — ' . ($r['uname'] ?: 'paciente'),
                report_html($user, $data, (bool)$r['include_notes'], (bool)$r['include_media_list'])
                . ((int)$r['include_markers'] ? markers_report_html(markers_report_data((int)$r['uid'], $from, $to, (int)$r['include_sexuality'] === 1, (int)$r['include_substances'] === 1, (int)($r['include_food'] ?? 0) === 1, ['death' => (int)($r['include_death'] ?? 0) === 1])) : '')
                . (!empty($r['include_custom_alerts']) ? custom_count_html(custom_count_for_report($pdo, (int)$r['uid'], $from, $to, ((int)$r['include_sexuality'] === 1 ? CUSTOM_PRIV_SEX : 0) | ((int)$r['include_substances'] === 1 ? CUSTOM_PRIV_SUBST : 0) | ((int)($r['include_death'] ?? 0) === 1 ? CUSTOM_PRIV_DEATH : 0))) : '')
                . '<p style="font-size:12px;color:#50606c;margin-top:20px">Você recebe este relatório porque confirmou o convite como ' . e(ROLES[$r['role']]) . '. '
                . '<a href="' . e($cancel) . '">Cancelar recebimento</a>.</p>');
            $m = send_mail_ex((string)$r['email'], 'Relatório de humor: ' . fmt_date($from) . ' a ' . fmt_date($to), $html, '', 'relatorio');
            usleep(300000);
            $k = outbox_classify_mail($m);
            return [$k, $k === 'ok' ? '' : 'smtp_' . $m['stage'] . '_' . $m['code']];
        },
        'done' => function (PDO $pdo, array $row) use ($load): void { $r = $load($pdo, $row); if ($r) report_advance($pdo, $r, (string)$row['ref_value'], true); },
        'dead' => function (PDO $pdo, array $row) use ($load): void { $r = $load($pdo, $row); if ($r) report_advance($pdo, $r, (string)$row['ref_value'], false); },
    ]];
}
