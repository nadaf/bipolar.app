<?php
/** V4.4: segunda etapa do login por e-mail e senha (código do app autenticador, código de recuperação ou passkey). */
require __DIR__ . '/../_boot.php';
if (current_user()) redirect('/');
if (isset($_GET['cancelar'])) { unset($_SESSION['mfa_pending']); redirect('/'); }
$p = mfa_pending();
if (!$p) {
    unset($_SESSION['mfa_pending']);
    flash('O tempo para digitar o código acabou ou houve tentativas demais. Entre de novo com e-mail e senha.', 'err');
    redirect('/');
}
$pdo = db();
$st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$st->execute([$p['uid']]);
$u = $st->fetch();
if (!is_array($u) || !mfa_enabled($u) || (int)$u['session_epoch'] !== $p['epoch']) { unset($_SESSION['mfa_pending']); redirect('/'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    form_csrf_or_back('/conta/mfa.php');
    $_SESSION['mfa_pending']['tries'] = $p['tries'] + 1;
    if (!mfa_rate_ok((int)$u['id'])) {
        unset($_SESSION['mfa_pending']);
        flash('Muitas tentativas. Aguarde 15 minutos e entre de novo.', 'err');
        redirect('/');
    }
    $code = (string)($_POST['codigo'] ?? '');
    $kind = strlen($code) <= 40 ? mfa_verify_code($pdo, $u, $code) : null;
    if ($kind === null) {
        $left = MFA_MAX_TRIES - $p['tries'] - 1;
        if ($left <= 0) { unset($_SESSION['mfa_pending']); flash('Código incorreto. Por segurança, entre de novo com e-mail e senha.', 'err'); redirect('/'); }
        flash('Código incorreto ou já usado. Confira o horário do celular e tente o código mais recente.', 'err');
        redirect('/conta/mfa.php');
    }
    unset($_SESSION['mfa_pending']);
    login_user((int)$u['id']);
    if ($kind === 'recovery') {
        $n = mfa_recovery_left($pdo, (int)$u['id']);
        flash('Você entrou com um código de recuperação. ' . ($n > 0 ? 'Restam ' . plural_br($n, 'código', 'códigos') . '.' : 'Não restam códigos: gere novos em Conta.') . ' Se perdeu o celular, gere novos códigos em Conta → Verificação em duas etapas.', $n > 2 ? 'ok' : 'err');
    }
    redirect('/');
}
$hasPasskey = webauthn_count($u) > 0;
page_head('Verificação em duas etapas'); ?>
<section class="card narrow">
  <h1>Verificação em duas etapas</h1>
  <?= flash_html() ?>
  <p>Abra o app autenticador (Google Authenticator, Microsoft Authenticator, Authy, Aegis…) e digite o código de 6 números da conta <strong>Bipolar</strong>.</p>
  <form class="auth-form" method="post" action="/conta/mfa.php" novalidate>
    <?= csrf_field() ?>
    <div class="field"><label for="mfa-codigo">Código de 6 números ou código de recuperação</label>
      <input id="mfa-codigo" name="codigo" required autocomplete="one-time-code" inputmode="text" autocapitalize="off" spellcheck="false" maxlength="40" aria-describedby="mfa-dica" autofocus></div>
    <p class="small" id="mfa-dica">Perdeu o celular? Use um dos códigos de recuperação que você guardou (formato xxxxx-xxxxx). Cada um vale uma vez.</p>
    <button class="btn btn-primary btn-lg" type="submit">Confirmar</button>
  </form>
  <?php if ($hasPasskey): ?>
  <p class="passkey-login"><button type="button" class="btn btn-ghost hidden" id="btn-passkey-mfa">🔐 Confirmar com a biometria deste aparelho</button></p>
  <p id="passkey-msg" class="field-err" role="alert"></p>
  <?php endif; ?>
  <p class="small"><a href="/conta/mfa.php?cancelar=1">Cancelar e voltar</a></p>
</section>
<?php if ($hasPasskey): ?><script src="/assets/js/passkey.js?v=4"></script><?php endif; ?>
<?php page_foot();
