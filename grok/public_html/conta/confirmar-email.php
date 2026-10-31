<?php
/**
 * V5.1: confirmação da troca de e-mail (ou do e-mail atual). O link só mostra um botão: o token é consumido no POST,
 * para que leitores de e-mail que abrem links sozinhos não confirmem nada.
 */
require __DIR__ . '/../_boot.php';
require_once APP_ROOT . '/lib/smtp.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex');
$t = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    form_csrf_or_back('/conta/confirmar-email.php?t=' . rawurlencode($t));
    if (!rate_limit('email_change_confirm', 30, 900)) { flash('Muitas tentativas. Aguarde alguns minutos.', 'err'); redirect('/'); }
    // quem está logado ANTES da confirmação (depois dela o session_epoch muda e a sessão antiga deixa de valer)
    $before = current_user();
    $beforeUid = $before ? (int)$before['id'] : 0;
    $result = email_change_confirm($t);
    if ($result['ok']) {
        $me = $before;
        if ($result['changed']) {
            // todas as sessões da conta foram encerradas; quem confirmou logado nesta mesma conta continua dentro
            if ($beforeUid > 0 && $beforeUid === (int)$result['uid']) { login_user($result['uid']); flash('E-mail confirmado e atualizado. Por segurança, os outros aparelhos saíram da conta.'); redirect('/?aba=conta'); }
            $_SESSION = [];
            session_regenerate_id(true);
            flash('E-mail confirmado e atualizado. Entre novamente com o novo e-mail.');
            redirect('/');
        }
        flash('E-mail confirmado.');
        redirect($me ? '/?aba=conta' : '/');
    }
}
$st = db()->prepare("SELECT payload FROM auth_tokens WHERE token_hash = ? AND purpose = 'email_change' AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()");
$st->execute([preg_match('/^[A-Za-z0-9_-]{40,64}$/', $t) ? hash('sha256', $t) : '-']);
$payload = $st->fetchColumn();
page_head('Confirmar e-mail'); ?>
<section class="card narrow">
<?php if ($result !== null && !$result['ok']): ?>
  <h1>Não foi possível confirmar</h1>
  <div class="flash flash-err" role="alert"><?= e($result['erro']) ?></div>
  <p><a class="btn" href="/">Voltar</a></p>
<?php elseif ($payload !== false): ?>
  <h1>Confirmar e-mail</h1>
  <p>Confirme para usar <strong><?= e(mask_email((string)$payload)) ?></strong> na sua conta. Se você trocou de endereço, os outros aparelhos conectados saem da conta.</p>
  <form method="post" action="/conta/confirmar-email.php"><?= csrf_field() ?><input type="hidden" name="t" value="<?= e($t) ?>">
  <button class="btn btn-primary btn-lg" type="submit">Confirmar este e-mail</button></form>
<?php else: ?>
  <h1>Link inválido ou expirado</h1>
  <p>Este link já foi usado, foi substituído por um pedido mais recente ou passou do prazo de <?= EMAIL_CHANGE_TTL_HOURS ?> horas. Peça de novo em <strong>Conta → Preferências</strong>.</p>
  <p><a class="btn" href="/">Voltar</a></p>
<?php endif; ?>
</section>
<?php page_foot();
