<?php
require __DIR__ . '/../_boot.php';
$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    form_csrf_or_back('/conta/reenviar.php');
    $email = normalize_email((string)($_POST['email'] ?? ''));
    if (rate_limit('resend_ip', 5, 3600) && rate_limit('resend_email|' . $email, 3, 3600, false) && valid_email($email)) {
        $u = find_email_user($email);
        if ($u && empty($u['email_verified_at'])) send_verification_email((int)$u['id'], $email, (string)$u['name']);
    }
    $sent = true; // resposta idêntica em todos os casos (anti-enumeração)
}
page_head('Reenviar confirmação'); ?>
<section class="card narrow">
  <h1>Reenviar link de confirmação</h1>
  <?= flash_html() ?>
  <?php if ($sent): ?>
  <div class="flash flash-ok" role="status">Se houver uma conta aguardando confirmação com esse e-mail, enviamos um novo link. Confira também a pasta de spam.</div>
  <p><a class="btn" href="/">Voltar para o login</a></p>
  <?php else: ?>
  <form class="auth-form" method="post" action="/conta/reenviar.php" novalidate>
    <?= csrf_field() ?>
    <div class="field"><label for="re-email">E-mail cadastrado</label><input type="email" id="re-email" name="email" required maxlength="190" autocomplete="email" inputmode="email"></div>
    <button class="btn btn-primary btn-lg" type="submit">Enviar novo link</button>
    <p class="auth-links"><a href="/">Voltar para o login</a></p>
  </form>
  <?php endif; ?>
</section>
<?php page_foot();
