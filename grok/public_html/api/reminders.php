<?php
require __DIR__ . '/../_boot.php';
$u = require_user_api();
$pdo = db();
$m = $_SERVER['REQUEST_METHOD'];
if ($m === 'GET') {
    $st = $pdo->prepare('SELECT id, time_local, days, channel, active FROM reminders WHERE user_id = ? ORDER BY time_local');
    $st->execute([$u['id']]);
    json_out(['reminders' => $st->fetchAll(), 'push_disponivel' => push_configured()]);
}
if ($m === 'POST') {
    [$err, $v] = validate_reminder(json_input(), push_configured());
    if ($err !== null) json_out(['erro' => $err], 422);
    $c = $pdo->prepare('SELECT COUNT(*) FROM reminders WHERE user_id = ?'); $c->execute([$u['id']]);
    if ($c->fetchColumn() >= 12) json_out(['erro' => 'Limite de 12 lembretes.'], 422);
    $pdo->prepare('INSERT INTO reminders (user_id, time_local, days, channel) VALUES (?,?,?,?)')->execute([$u['id'], $v['time_local'], $v['days'], $v['channel']]);
    json_out(['ok' => true]);
}
if ($m === 'DELETE') {
    $pdo->prepare('DELETE FROM reminders WHERE id = ? AND user_id = ?')->execute([(int)($_GET['id'] ?? 0), $u['id']]);
    json_out(['ok' => true]);
}
json_out(['erro' => 'Método não permitido.'], 405);
