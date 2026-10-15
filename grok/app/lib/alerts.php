<?php
/** Entrega dos alertas: no app (sempre), push e e-mail (opcionais, sem detalhes) e pessoas de apoio (opt-in duplo). */
require_once APP_ROOT . '/lib/signals.php';
require_once APP_ROOT . '/lib/smtp.php';
require_once APP_ROOT . '/lib/webpush.php';

const CRISIS_HTML = '<p style="font-size:14px;background:#fdf1e7;padding:10px 12px;border-radius:8px"><strong>Em caso de risco imediato:</strong> CVV <a href="tel:188">188</a> (24h, gratuito, também em cvv.org.br) · SAMU <a href="tel:192">192</a> · CAPS ou UPA mais próximos.</p>';

/**
 * Analisa e grava novos alertas para um usuário; entrega quando $deliver. Retorna os ids novos.
 * @param array<string,mixed> $u
 * @return list<int>
 */
function alerts_run_for_user(PDO $pdo, array $u, bool $deliver = true, ?string $today = null): array
{
    if (!alert_prefs($u)['engine']) return [];
    $ids = [];
    foreach (signals_for_user($pdo, $u, $today) as $a) {
        $id = alert_store($pdo, (int)$u['id'], $a, !$deliver);
        if ($id === null) continue;
        $ids[] = $id;
        if ($deliver) alerts_deliver($pdo, $u, $id, $a['severity']);
    }
    return $ids;
}

/** @param array<string,mixed> $u */
function alerts_deliver(PDO $pdo, array $u, int $alertId, int $severity): void
{
    $ap = alert_prefs($u);
    $app = rtrim((string)cfg('app_url'), '/');
    $pdo->prepare('UPDATE alerts SET pending_delivery = 0 WHERE id = ?')->execute([$alertId]);
    if ($ap['push']) {
        // Texto genérico: nada sobre o tipo de alerta, humor, substâncias etc. (aparece na tela bloqueada)
        $n = push_to_user((int)$u['id'], ['title' => 'Bipolar', 'body' => 'Seu diário tem um novo aviso para você. Toque para ver.', 'url' => '/?aba=historico#alertas', 'tag' => 'alerta'], null, $severity >= 3 ? 'high' : 'normal');
        if ($n > 0) $pdo->prepare('UPDATE alerts SET notified_push = 1 WHERE id = ?')->execute([$alertId]);
    }
    if ($ap['email'] && !empty($u['email'])) {
        $ok = send_mail((string)$u['email'], 'Há um novo aviso no seu diário', email_layout('Um novo aviso no seu diário',
            '<p>O diário notou uma mudança nos seus registros recentes. Por privacidade, os detalhes ficam só no aplicativo.</p>'
            . '<p><a href="' . e($app) . '/?aba=historico#alertas" style="display:inline-block;background:#2f6f73;color:#fff;padding:12px 20px;border-radius:10px;text-decoration:none">Ver no aplicativo</a></p>' . CRISIS_HTML), '', 'alerta');
        if ($ok) $pdo->prepare('UPDATE alerts SET notified_email = 1 WHERE id = ?')->execute([$alertId]);
    }
    if ($severity < 2) return;
    // Pessoas de apoio: só quem confirmou o convite E foi marcado para "receber alertas"; no máximo 1 aviso a cada 7 dias.
    $st = $pdo->prepare('SELECT id, name, email, confirm_token FROM recipients WHERE user_id = ? AND include_alerts = 1 AND confirmed_at IS NOT NULL AND unsubscribed_at IS NULL
        AND (last_alert_at IS NULL OR last_alert_at < UTC_TIMESTAMP() - INTERVAL 7 DAY)');
    $st->execute([$u['id']]);
    $who = trim(explode(' ', trim((string)($u['name'] ?? '')))[0] ?? '') ?: 'A pessoa que convidou você';
    $n = 0;
    foreach ($st->fetchAll() as $r) {
        $cancel = $app . '/confirmar.php?cancelar=1&t=' . $r['confirm_token'];
        $html = email_layout($who . ' pode precisar de apoio',
            '<p>Olá, ' . e((string)$r['name']) . '.</p><p><strong>' . e($who) . '</strong> pediu que você fosse avisado(a) quando o diário de humor notasse sinais que merecem atenção. Isso aconteceu agora.</p>'
            . '<p>Não enviamos detalhes — eles pertencem a ' . e($who) . '. Um gesto simples ajuda: mande uma mensagem, pergunte como está, ofereça companhia. Evite cobranças ou diagnósticos.</p>'
            . CRISIS_HTML . '<p style="font-size:12px;color:#50606c">Você recebe este aviso porque aceitou o convite e ' . e($who) . ' autorizou alertas para você. <a href="' . e($cancel) . '">Cancelar tudo</a>.</p>');
        if (send_mail((string)$r['email'], $who . ' pode precisar de apoio', $html, '', 'alerta_apoio')) {
            $pdo->prepare('UPDATE recipients SET last_alert_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$r['id']]);
            $n++;
        }
    }
    if ($n) $pdo->prepare('UPDATE alerts SET notified_recipients = ? WHERE id = ?')->execute([$n, $alertId]);
}

/**
 * V4.2: entrega os alertas automáticos que ficaram pendentes (gerados ao abrir o Histórico, o relatório ou logo após salvar)
 * nas últimas 24 h, e os personalizados pendentes. Chamado pelo cron.
 * @param array<string,mixed> $u
 */
function alerts_deliver_pending(PDO $pdo, array $u): int
{
    $st = $pdo->prepare('SELECT id, severity FROM alerts WHERE user_id = ? AND pending_delivery = 1 AND custom_rule_id IS NULL AND created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY');
    $st->execute([$u['id']]);
    $n = 0;
    foreach ($st->fetchAll() as $a) {
        $up = $pdo->prepare('UPDATE alerts SET pending_delivery = 0 WHERE id = ? AND pending_delivery = 1');
        $up->execute([$a['id']]);
        if ($up->rowCount() === 0) continue;
        if (alert_prefs($u)['engine']) { alerts_deliver($pdo, $u, (int)$a['id'], (int)$a['severity']); $n++; }
    }
    require_once APP_ROOT . '/lib/custom_alerts.php';
    return $n + custom_deliver_pending($pdo, $u);
}
