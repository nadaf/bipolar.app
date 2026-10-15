<?php
require __DIR__ . '/../_boot.php';
require_once APP_ROOT . '/lib/oauth.php';
// V4.4: a URI de retorno não leva mais ?provider= (a Microsoft não aceita): o provedor vem do login iniciado nesta sessão
$p = (string)($_GET['provider'] ?? (is_array($_SESSION['oauth'] ?? null) ? ($_SESSION['oauth']['p'] ?? '') : ''));
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
