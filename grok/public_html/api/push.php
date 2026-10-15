<?php
/** Notificações push: assinar/cancelar por aparelho, listar, renomear, testar e "lembrar em 30 min". */
require __DIR__ . '/../_boot.php';
require_once APP_ROOT . '/lib/webpush.php';
$action = (string)($_GET['action'] ?? '');
$pdo = db();

// "Lembrar em 30 min" vem do service worker, sem sessão/cookie confiável: autentica pelo endpoint + segredo "auth" da assinatura.
if ($action === 'snooze') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['erro' => 'Método não permitido.'], 405);
    if (!rate_limit('push_snooze', 30, 3600)) json_out(['erro' => 'Muitas requisições.'], 429);
    $in = json_input();
    $st = $pdo->prepare('SELECT id, auth FROM push_subscriptions WHERE endpoint_hash = ?');
    $st->execute([hash('sha256', (string)($in['endpoint'] ?? ''))]);
    $s = $st->fetch();
    if (!$s || !is_string($in['auth'] ?? null) || (string)$s['auth'] === '' || !hash_equals((string)$s['auth'], $in['auth'])) json_out(['erro' => 'Assinatura desconhecida.'], 403);
    $min = max(5, min(120, (int)($in['minutes'] ?? 30)));
    $pdo->prepare('UPDATE push_subscriptions SET snooze_until = UTC_TIMESTAMP() + INTERVAL ' . $min . ' MINUTE WHERE id = ?')->execute([$s['id']]);
    json_out(['ok' => true, 'minutes' => $min]);
}

$u = require_user_api();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = $pdo->prepare('SELECT id, label, endpoint_hash, created_at, last_ok_at FROM push_subscriptions WHERE user_id = ? ORDER BY created_at');
    $st->execute([$u['id']]);
    json_out(['devices' => $st->fetchAll(), 'configured' => push_configured()]);
}
$in = json_input();
if ($action === 'test') {
    if (!push_configured()) json_out(['erro' => 'As notificações ainda não foram configuradas no servidor (chaves VAPID).'], 422);
    if (!rate_limit('push_test_' . $u['id'], 5, 600)) json_out(['erro' => 'Aguarde alguns minutos para testar de novo.'], 429);
    $only = isset($in['id']) ? (int)$in['id'] : null;
    $n = push_to_user((int)$u['id'], ['title' => 'Notificação de teste', 'body' => 'Tudo certo! É assim que os lembretes vão aparecer.', 'url' => '/?aba=lembretes', 'tag' => 'teste'], $only);
    json_out($n > 0 ? ['ok' => true, 'enviadas' => $n] : ['erro' => 'Nenhum aparelho recebeu. Ative as notificações neste aparelho e tente de novo.'], $n > 0 ? 200 : 422);
}
if ($action === 'delete') {
    $st = $pdo->prepare('DELETE FROM push_subscriptions WHERE id = ? AND user_id = ?');
    $st->execute([(int)($in['id'] ?? 0), $u['id']]);
    if ($st->rowCount() < 1) json_out(['erro' => 'Aparelho não encontrado.'], 404); // V4.4
    json_out(['ok' => true]);
}
if ($action === 'rename') {
    // V4.4: confere o dono antes (UPDATE com o mesmo nome devolve 0 linhas no MySQL, por isso não usamos rowCount aqui)
    $st = $pdo->prepare('SELECT id FROM push_subscriptions WHERE id = ? AND user_id = ?');
    $st->execute([(int)($in['id'] ?? 0), $u['id']]);
    if (!$st->fetchColumn()) json_out(['erro' => 'Aparelho não encontrado.'], 404);
    $pdo->prepare('UPDATE push_subscriptions SET label = ? WHERE id = ? AND user_id = ?')->execute([trim(mb_substr((string)($in['label'] ?? ''), 0, 120)) ?: null, (int)($in['id'] ?? 0), $u['id']]);
    json_out(['ok' => true]);
}
$endpoint = (string)($in['endpoint'] ?? '');
if (!preg_match('#^https://#', $endpoint) || strlen($endpoint) > 2000) json_out(['erro' => 'Assinatura inválida.'], 422);
$hash = hash('sha256', $endpoint);
if (($in['action'] ?? 'subscribe') === 'unsubscribe') {
    $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?')->execute([$hash, $u['id']]);
    json_out(['ok' => true]);
}
$p256 = (string)($in['keys']['p256dh'] ?? '');
$auth = (string)($in['keys']['auth'] ?? '');
if (strlen(b64url_decode($p256)) !== 65 || strlen(b64url_decode($auth)) !== 16) json_out(['erro' => 'Chaves da assinatura inválidas.'], 422);
$label = trim(mb_substr((string)($in['label'] ?? ''), 0, 120)) ?: null;
$pdo->prepare('INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, label) VALUES (?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), label = COALESCE(VALUES(label), label)')
    ->execute([$u['id'], $endpoint, $hash, $p256, $auth, $label]);
json_out(['ok' => true]);
