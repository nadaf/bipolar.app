<?php
require __DIR__ . '/../_boot.php';
require_once APP_ROOT . '/lib/smtp.php';
require_once APP_ROOT . '/lib/schedule.php';
$u = require_user_api();
$pdo = db();
$m = $_SERVER['REQUEST_METHOD'];
$freqs = ['diaria' => 'diária', 'semanal' => 'semanal', 'mensal' => 'mensal', 'personalizada' => 'personalizada'];

/**
 * Envia (ou reenvia) o convite e grava o resultado: enviado, recusado (endereço rejeitado) ou falhou (servidor de e-mail).
 * V4.2: um convite recusado deixa de aparecer como "aguardando confirmação".
 * @param array<string,mixed> $u
 * @param array<string,mixed> $rc
 * @param array<string,string> $freqs
 * @return array{ok:bool, aviso:?string, status:string}
 */
function send_invite(PDO $pdo, array $u, array $rc, array $freqs): array
{
    $link = rtrim((string)cfg('app_url'), '/') . '/confirmar.php?t=' . $rc['confirm_token'];
    $who = e($u['name'] ?: 'Um(a) usuário(a)');
    $html = email_layout('Convite para receber relatórios de humor',
        "<p>Olá, " . e($rc['name']) . ".</p><p><strong>$who</strong> gostaria de compartilhar com você, como <strong>" . e(ROLES[$rc['role']] ?? 'Outro') . "</strong>, relatórios periódicos (" . e($freqs[$rc['frequency']] ?? 'semanal') . ") do diário de humor no aplicativo Bipolar.</p>"
        . "<p>Você só receberá relatórios se confirmar:</p><p><a href=\"" . e($link) . "\" style=\"display:inline-block;background:#2f6f73;color:#fff;padding:12px 20px;border-radius:10px;text-decoration:none\">Confirmar recebimento</a></p>"
        . "<p style=\"font-size:13px;color:#50606c\">Se não reconhece este pedido, ignore este e-mail. Você poderá cancelar a qualquer momento.</p>");
    $r = send_mail_ex((string)$rc['email'], 'Convite: relatórios de humor de ' . ($u['name'] ?: 'um(a) paciente'), $html, '', 'convite');
    $status = $r['ok'] ? 'enviado' : ($r['recipient_rejected'] ? 'recusado' : 'falhou');
    $pdo->prepare('UPDATE recipients SET invite_status = ?, invite_error_at = ' . ($r['ok'] ? 'NULL' : 'UTC_TIMESTAMP()') . ' WHERE id = ?')->execute([$status, $rc['id']]);
    $aviso = null;
    if (!$r['ok']) {
        // Detalhes técnicos ficam só no log do servidor (error_log); o usuário recebe uma orientação clara.
        $aviso = $r['recipient_rejected']
            ? 'Destinatário salvo, mas o endereço ' . $rc['email'] . ' recusou o convite — ele pode não existir ou ter um erro de digitação. Confira o e-mail; se estiver errado, remova e cadastre de novo.'
            : 'Destinatário salvo, mas não conseguimos enviar o convite agora. Use “Reenviar convite” mais tarde. Se continuar, avise o suporte.';
    }
    return ['ok' => $r['ok'], 'aviso' => $aviso, 'status' => $status];
}

if ($m === 'GET') {
    $st = $pdo->prepare('SELECT id, version, name, email, role, frequency, interval_days, weekday, include_notes, include_media_list, include_markers, include_sexuality, include_substances, include_food, include_death, include_alerts, include_custom_alerts, invite_status, invite_error_at, confirmed_at, unsubscribed_at, last_sent_at, next_send_at FROM recipients WHERE user_id = ? ORDER BY created_at');
    $st->execute([$u['id']]);
    json_out(['recipients' => $st->fetchAll()]);
}
if ($m === 'POST' && ($_GET['action'] ?? '') === 'resend') {
    if (!rate_limit('recipients_' . $u['id'], 10, 3600)) json_out(['erro' => 'Muitos convites em pouco tempo.'], 429);
    $st = $pdo->prepare('SELECT * FROM recipients WHERE id = ? AND user_id = ?');
    $st->execute([(int)($_GET['id'] ?? 0), $u['id']]);
    $rc = $st->fetch();
    if (!$rc) json_out(['erro' => 'Destinatário não encontrado.'], 404);
    if ($rc['confirmed_at'] !== null) json_out(['erro' => 'Essa pessoa já confirmou o convite.'], 422);
    $res = send_invite($pdo, $u, $rc, $freqs);
    json_out(['ok' => true, 'email_enviado' => $res['ok'], 'aviso' => $res['aviso'] === null ? null : str_replace('Destinatário salvo, mas o', 'O', $res['aviso']), 'invite_status' => $res['status']]);
}
if ($m === 'POST') {
    if (!rate_limit('recipients_' . $u['id'], 10, 3600)) json_out(['erro' => 'Muitos convites em pouco tempo.'], 429);
    $in = json_input();
    $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 150));
    $email = trim((string)($in['email'] ?? ''));
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['erro' => 'Informe nome e um e-mail válido.'], 422);
    $email = mb_strtolower($email);
    $st = $pdo->prepare('SELECT name FROM recipients WHERE user_id = ? AND email = ?');
    $st->execute([$u['id'], $email]);
    if (($dup = $st->fetchColumn()) !== false) json_out(['erro' => 'Esse e-mail já está na sua lista (' . $dup . '). Para mudar o que essa pessoa recebe, remova e cadastre de novo.', 'duplicado' => true], 409);
    $role = isset(ROLES[$in['role'] ?? '']) ? $in['role'] : 'outro';
    $freq = isset($freqs[$in['frequency'] ?? '']) ? $in['frequency'] : 'semanal';
    $r = [
        'frequency' => $freq,
        'interval_days' => $freq === 'personalizada' ? max(1, min(365, (int)($in['interval_days'] ?? 7))) : null,
        'weekday' => $freq === 'semanal' ? max(0, min(6, (int)($in['weekday'] ?? 1))) : null,
    ];
    $token = bin2hex(random_bytes(32));
    try {
    $pdo->prepare('INSERT INTO recipients (user_id, name, email, role, frequency, interval_days, weekday, include_notes, include_media_list, include_markers, include_sexuality, include_substances, include_food, include_death, include_alerts, include_custom_alerts, confirm_token, next_send_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$u['id'], $name, $email, $role, $freq, $r['interval_days'], $r['weekday'], !empty($in['include_notes']) ? 1 : 0, !empty($in['include_media_list']) ? 1 : 0, !isset($in['include_markers']) || !empty($in['include_markers']) ? 1 : 0, ($in['include_sexuality'] ?? false) === true ? 1 : 0, ($in['include_substances'] ?? false) === true ? 1 : 0, ($in['include_food'] ?? false) === true ? 1 : 0, ($in['include_death'] ?? false) === true ? 1 : 0, ($in['include_alerts'] ?? false) === true ? 1 : 0, ($in['include_custom_alerts'] ?? false) === true ? 1 : 0, $token, next_send_at($r, $u['timezone'])]);
    } catch (PDOException $ex) {
        if ((int)($ex->errorInfo[1] ?? 0) === 1062) json_out(['erro' => 'Esse e-mail já está na sua lista.', 'duplicado' => true], 409);
        throw $ex;
    }
    $rid = (int)$pdo->lastInsertId();
    $res = send_invite($pdo, $u, ['id' => $rid, 'name' => $name, 'email' => $email, 'role' => $role, 'frequency' => $freq, 'confirm_token' => $token], $freqs);
    json_out(['ok' => true, 'id' => $rid, 'email_enviado' => $res['ok'], 'aviso' => $res['aviso'], 'invite_status' => $res['status']]);
}
if ($m === 'PATCH') {
    // Ajusta o que o destinatário recebe. Sexualidade e Substâncias só entra com opt-in explícito (true), por destinatário.
    $in = json_input();
    $sets = []; $vals = [];
    foreach (['include_notes', 'include_media_list', 'include_markers', 'include_sexuality', 'include_substances', 'include_food', 'include_death', 'include_alerts', 'include_custom_alerts'] as $k) {
        if (array_key_exists($k, $in)) { $sets[] = "$k = ?"; $vals[] = $in[$k] === true ? 1 : 0; }
    }
    if (!$sets) json_out(['erro' => 'Nada para alterar.'], 422);
    $rid = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT id FROM recipients WHERE id = ? AND user_id = ?');
    $st->execute([$rid, $u['id']]);
    if (!$st->fetchColumn()) json_out(['erro' => 'Destinatário não encontrado.'], 404);
    if (!version_guard($pdo, 'recipients', ['id' => $rid, 'user_id' => $u['id']], client_version($in))) $sets[] = 'version = version + 1'; // V4.3.1
    $vals[] = $rid; $vals[] = $u['id'];
    $pdo->prepare('UPDATE recipients SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?')->execute($vals);
    json_out(['ok' => true]);
}
if ($m === 'DELETE') {
    // V4.4: id de outra pessoa (ou já apagado) → 404, como nas demais rotas
    $st = $pdo->prepare('DELETE FROM recipients WHERE id = ? AND user_id = ?');
    $st->execute([(int)($_GET['id'] ?? 0), $u['id']]);
    if ($st->rowCount() < 1) json_out(['erro' => 'Destinatário não encontrado.'], 404);
    json_out(['ok' => true]);
}
json_out(['erro' => 'Método não permitido.'], 405);
