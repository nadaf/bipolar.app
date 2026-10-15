<?php
require __DIR__ . '/../_boot.php';
$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    form_csrf_or_back('/conta/esqueci.php');
    $email = normalize_email((string)($_POST['email'] ?? ''));
    if (rate_limit('reset_ip', 5, 3600) && rate_limit('reset_email|' . $email, 3, 3600, false) && valid_email($email)) {
        request_password_reset($email);
    }
    $sent = true;
}
page_head('Esqueci minha senha'); ?>
<section class="card narrow">
  <h1>Esqueci minha senha</h1>
  <?php if ($sent): ?>
  <div class="flash flash-ok" role="status">Se existir uma conta com esse e-mail, enviamos um link para criar uma nova senha. Ele vale por <?= RESET_TTL_MINUTES ?> minutos.</div>
  <p class="small">Contas criadas com Google, Microsoft ou X não têm senha aqui — entre pelo mesmo botão que você usou no cadastro.</p>
  <p><a class="btn" href="/">Voltar para o login</a></p>
  <?php else: ?>
  <p>Informe o e-mail da sua conta. Enviaremos um link para você criar uma nova senha.</p>
  <form class="auth-form" method="post" action="/conta/esqueci.php" novalidate>
    <?= csrf_field() ?>
    <div class="field"><label for="es-email">E-mail</label><input type="email" id="es-email" name="email" required maxlength="190" autocomplete="email" inputmode="email"></div>
    <button class="btn btn-primary btn-lg" type="submit">Enviar link</button>
    <p class="auth-links"><a href="/">Voltar para o login</a></p>
  </form>
  <?php endif; ?>
</section>
<?php page_foot();
