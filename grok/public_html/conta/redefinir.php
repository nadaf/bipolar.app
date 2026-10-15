<?php
require __DIR__ . '/../_boot.php';
$t = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    form_csrf_or_back('/conta/redefinir.php?t=' . rawurlencode($t));
    $pw = (string)($_POST['senha'] ?? '');
    $pw2 = (string)($_POST['senha2'] ?? '');
    $tok = find_auth_token($t, 'reset');
    if (!rate_limit('reset_set', 20, 900)) $errors[] = 'Muitas tentativas. Aguarde alguns minutos.';
    elseif ($tok) {
        $st = db()->prepare('SELECT email FROM users WHERE id = ?');
        $st->execute([$tok['user_id']]);
        if ($p = password_problem($pw, (string)$st->fetchColumn())) $errors[] = $p;
        elseif (!hash_equals($pw, $pw2)) $errors[] = 'As senhas não conferem.';
        elseif ($uid = consume_auth_token($t, 'reset')) {
            set_password($uid, $pw); // encerra todas as sessões abertas
            db()->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = ?')->execute([$uid]);
            $_SESSION = [];
            session_regenerate_id(true);
            flash('Senha alterada. Entre com a nova senha.');
            redirect('/');
        }
    }
}
$valid = find_auth_token($t, 'reset') !== null;
page_head('Nova senha'); ?>
<section class="card narrow">
<?php if ($valid): ?>
  <h1>Criar nova senha</h1>
  <?php if ($errors): ?><div class="flash flash-err" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <form class="auth-form" method="post" action="/conta/redefinir.php" novalidate>
    <?= csrf_field() ?><input type="hidden" name="t" value="<?= e($t) ?>">
    <?= password_field('nova-senha', 'senha', 'Nova senha', 'new-password', true) ?>
    <?= password_field('nova-senha2', 'senha2', 'Repita a nova senha', 'new-password') ?>
    <button class="btn btn-primary btn-lg" type="submit">Salvar nova senha</button>
  </form>
<?php else: ?>
  <h1>Link inválido ou expirado</h1>
  <p>Links de redefinição valem por <?= RESET_TTL_MINUTES ?> minutos e só podem ser usados uma vez.</p>
  <p><a class="btn btn-primary" href="/conta/esqueci.php">Pedir novo link</a> <a class="btn" href="/">Entrar</a></p>
<?php endif; ?>
</section>
<script src="/assets/js/auth.js?v=1"></script>
<?php page_foot();
