<?php
require __DIR__ . '/_boot.php';
$t = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$cancel = isset($_GET['cancelar']) || isset($_POST['cancelar']);
$r = null;
if (preg_match('/^[a-f0-9]{64}$/', $t)) {
    $st = db()->prepare('SELECT r.*, u.name AS uname FROM recipients r JOIN users u ON u.id = r.user_id WHERE r.confirm_token = ?');
    $st->execute([$t]);
    $r = $st->fetch();
}
$expired = $r && !$r['confirmed_at'] && !$cancel && strtotime($r['created_at'] . ' UTC') < time() - INVITE_TTL_DAYS * 86400;
$done = false;
// Confirmação exige POST (evita confirmação automática por scanners de link em e-mail).
if ($r && !$expired && $_SERVER['REQUEST_METHOD'] === 'POST' && rate_limit('confirm', 30, 600)) {
    if ($cancel) db()->prepare('UPDATE recipients SET unsubscribed_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$r['id']]);
    else db()->prepare('UPDATE recipients SET confirmed_at = UTC_TIMESTAMP(), unsubscribed_at = NULL WHERE id = ?')->execute([$r['id']]);
    $done = true;
}
page_head($cancel ? 'Cancelar relatórios' : 'Confirmar relatórios'); ?>
<section class="card narrow">
<?php if (!$r): ?>
  <h1>Link inválido</h1><p>Este link não é válido ou foi removido pelo(a) paciente.</p>
<?php elseif ($expired): ?>
  <h1>Convite expirado</h1><p>Este convite tinha validade de <?= INVITE_TTL_DAYS ?> dias. Peça para a pessoa que convidou você enviar um novo convite.</p>
<?php elseif ($done): ?>
  <h1><?= $cancel ? 'Envio cancelado' : 'Recebimento confirmado' ?></h1>
  <p><?= $cancel ? 'Você não receberá mais relatórios.' : 'Obrigado! Você passará a receber os relatórios de ' . e($r['uname']) . '.' ?></p>
<?php else: ?>
  <h1><?= $cancel ? 'Cancelar recebimento?' : 'Confirmar recebimento de relatórios' ?></h1>
  <p><?= $cancel ? 'Você deixará de receber os relatórios de humor de ' . e($r['uname']) . '.' : e($r['uname']) . ' convidou você (' . e(ROLES[$r['role']]) . ') para receber relatórios do diário de humor.' ?></p>
  <form method="post"><input type="hidden" name="t" value="<?= e($t) ?>"><?php if ($cancel): ?><input type="hidden" name="cancelar" value="1"><?php endif; ?>
  <button class="btn <?= $cancel ? 'btn-danger' : 'btn-primary' ?>" type="submit"><?= $cancel ? 'Cancelar envios' : 'Confirmar' ?></button></form>
<?php endif; ?>
</section>
<?php page_foot();
