<?php
require __DIR__ . '/../_boot.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/');
form_csrf_or_back('/');
$email = normalize_email((string)($_POST['email'] ?? ''));
$pw = (string)($_POST['senha'] ?? '');
$_SESSION['old_email'] = mb_substr($email, 0, 190);
if (!rate_limit('login_ip', 30, 900) || !rate_limit('login_email|' . $email, 10, 900, false)) {
    flash('Muitas tentativas. Aguarde 15 minutos e tente novamente.', 'err');
    redirect('/');
}
if (!valid_email($email) || $pw === '' || strlen($pw) > 1000) {
    flash('E-mail ou senha incorretos.', 'err');
    redirect('/');
}
$r = login_email($email, $pw);
if ($r['status'] === 'ok') {
    unset($_SESSION['old_email']);
    login_user((int)$r['uid']);
    redirect('/');
}
if ($r['status'] === 'unverified') {
    flash('Confirme seu e-mail antes de entrar. Procure a mensagem na caixa de entrada (e no spam) ou peça um novo link em “Reenviar confirmação”.', 'err');
    redirect('/conta/reenviar.php');
}
flash('E-mail ou senha incorretos.', 'err');
redirect('/');
