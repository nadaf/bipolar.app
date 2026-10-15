<?php
require __DIR__ . '/../_boot.php';
require_once APP_ROOT . '/lib/oauth.php';
$p = (string)($_GET['provider'] ?? '');
try {
    if (!rate_limit('oauth_cb', 30, 300)) throw new RuntimeException('Muitas tentativas. Aguarde alguns minutos.');
    $identity = oauth_callback($p);
    login_user(oauth_login_or_register($p, $identity));
    redirect('/');
} catch (Throwable $ex) {
    error_log('OAuth ' . $p . ': ' . $ex->getMessage());
    http_response_code(400);
    page_head('Erro no login'); ?>
    <section class="card narrow"><h1>Não foi possível entrar</h1>
    <p><?= e($ex instanceof RuntimeException ? $ex->getMessage() : 'Erro inesperado.') ?></p>
    <p><a class="btn btn-primary" href="/">Voltar e tentar novamente</a></p></section>
    <?php page_foot();
}
