<?php
require __DIR__ . '/../_boot.php';
if (current_user()) redirect('/');
$errors = [];
$old = ['nome' => '', 'email' => ''];
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    form_csrf_or_back('/conta/cadastro.php');
    $old = ['nome' => trim((string)($_POST['nome'] ?? '')), 'email' => normalize_email((string)($_POST['email'] ?? ''))];
    $pw = (string)($_POST['senha'] ?? '');
    if (!rate_limit('signup_ip', 5, 3600) || !rate_limit('signup_email|' . $old['email'], 3, 3600, false)) {
        $errors[] = 'Muitas tentativas de cadastro. Aguarde uma hora e tente novamente.';
    } else {
        if ($old['nome'] === '' || mb_strlen($old['nome']) > 150) $errors[] = 'Informe seu nome (até 150 caracteres).';
        if (!valid_email($old['email'])) $errors[] = 'Informe um e-mail válido.';
        if ($p = password_problem($pw, $old['email'])) $errors[] = $p;
        if (empty($_POST['consentimento'])) $errors[] = 'Para criar a conta é preciso concordar com o tratamento dos seus dados de saúde (LGPD).';
    }
    if (!$errors) {
        $r = signup_email($old['nome'], $old['email'], $pw);
        if (!cfg('require_email_verification', true)) {
            if ($r['status'] === 'created') { login_user((int)$r['uid']); flash('Conta criada. Bem-vindo(a)!'); redirect('/'); }
            $errors[] = 'Não foi possível criar a conta com esse e-mail. Se você já tem conta, entre ou use “Esqueci minha senha”.';
        } else {
            $done = true; // mesma resposta para e-mail novo ou já cadastrado
        }
    }
}
page_head('Criar conta'); ?>
<section class="card narrow">
<?php if ($done): ?>
  <h1>Verifique seu e-mail</h1>
  <div class="flash flash-ok" role="status">Se este e-mail puder ser usado, enviamos um link de confirmação para <strong><?= e($old['email']) ?></strong>. O link vale por <?= VERIFY_TTL_HOURS ?> horas.</div>
  <p>Não chegou? Confira a pasta de spam ou <a href="/conta/reenviar.php">peça um novo link</a>.</p>
  <p><a class="btn" href="/">Voltar para o login</a></p>
<?php else: ?>
  <h1>Criar conta</h1>
  <?= flash_html() ?>
  <?php if ($errors): ?>
  <div class="flash flash-err" role="alert" tabindex="-1" id="form-errors"><strong>Revise o formulário:</strong><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <form class="auth-form" method="post" action="/conta/cadastro.php" novalidate>
    <?= csrf_field() ?>
    <div class="field"><label for="cad-nome">Nome</label><input id="cad-nome" name="nome" required maxlength="150" autocomplete="name" value="<?= e($old['nome']) ?>"></div>
    <div class="field"><label for="cad-email">E-mail</label><input type="email" id="cad-email" name="email" required maxlength="190" autocomplete="email" inputmode="email" value="<?= e($old['email']) ?>"></div>
    <?= password_field('cad-senha', 'senha', 'Senha', 'new-password', true) ?>
    <label class="check consent"><input type="checkbox" name="consentimento" value="1" required>
      <span>Li a <a href="/privacidade.php" target="_blank" rel="noopener">Política de Privacidade</a> e os <a href="/termos.php" target="_blank" rel="noopener">Termos de Uso</a> e concordo com o tratamento dos meus dados de saúde para uso deste diário (LGPD, art. 11). Posso revogar excluindo a conta.</span></label>
    <button class="btn btn-primary btn-lg" type="submit">Criar conta</button>
    <p class="auth-links">Já tem conta? <a href="/">Entrar</a></p>
  </form>
<?php endif; ?>
</section>
<script src="/assets/js/auth.js?v=1"></script>
<?php page_foot();
