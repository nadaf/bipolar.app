<?php
require __DIR__ . '/../_boot.php';
$t = (string)($_GET['t'] ?? $_POST['t'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    form_csrf_or_back('/conta/verificar.php?t=' . rawurlencode($t));
    if (!rate_limit('verify', 30, 900)) { flash('Muitas tentativas. Aguarde alguns minutos.', 'err'); redirect('/'); }
    $uid = consume_auth_token($t, 'verify');
    if ($uid) {
        db()->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = ?')->execute([$uid]);
        flash('E-mail confirmado. Bem-vindo(a)!');
        login_or_mfa($uid); // V4.4
    }
}
$valid = find_auth_token($t, 'verify') !== null;
page_head('Confirmar e-mail'); ?>
<section class="card narrow">
<?php if ($valid): ?>
  <h1>Confirmar e-mail</h1>
  <p>Falta só um passo para ativar sua conta.</p>
  <form method="post" action="/conta/verificar.php"><?= csrf_field() ?><input type="hidden" name="t" value="<?= e($t) ?>">
  <button class="btn btn-primary btn-lg" type="submit">Confirmar meu e-mail</button></form>
<?php else: ?>
  <h1>Link inválido ou expirado</h1>
  <p>Este link de confirmação já foi usado ou passou do prazo de <?= VERIFY_TTL_HOURS ?> horas.</p>
  <p><a class="btn btn-primary" href="/conta/reenviar.php">Receber novo link</a> <a class="btn" href="/">Entrar</a></p>
<?php endif; ?>
</section>
<?php page_foot();
