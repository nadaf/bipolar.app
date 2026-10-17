<?php
require __DIR__ . '/../_boot.php';
require_once APP_ROOT . '/lib/care.php';
// V4.8: convite de profissional — e-mail já preenchido (e travado) e "Profissional de saúde" marcado
$conviteRaw = (string)($_GET['convite'] ?? $_POST['convite'] ?? '');
$convite = $conviteRaw !== '' ? care_invite_by_token(db(), $conviteRaw) : null;
if (current_user()) redirect($convite ? '/convite-profissional.php?t=' . rawurlencode($conviteRaw) : '/');
$errors = [];
$old = ['nome' => '', 'email' => $convite ? normalize_email((string)$convite['invite_email']) : '', 'pro' => $convite !== null, 'tipo' => '', 'registro' => ''];
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    form_csrf_or_back('/conta/cadastro.php');
    $old = ['nome' => trim((string)($_POST['nome'] ?? '')), 'email' => normalize_email((string)($_POST['email'] ?? '')), 'pro' => !empty($_POST['profissional']),
        'tipo' => isset(PRO_KINDS[(string)($_POST['tipo'] ?? '')]) ? (string)$_POST['tipo'] : '', 'registro' => trim(mb_substr((string)preg_replace('/[\x00-\x1F]/', '', (string)($_POST['registro'] ?? '')), 0, 40))];
    if ($convite) $old['email'] = normalize_email((string)$convite['invite_email']); // o convite vale só para o e-mail convidado
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
        if ($r['status'] === 'created' && $old['pro']) db()->prepare('UPDATE users SET is_professional = 1, professional_kind = ?, professional_reg = ? WHERE id = ?')
            ->execute([$old['tipo'] !== '' ? $old['tipo'] : null, $old['registro'] !== '' ? $old['registro'] : null, $r['uid']]);
        if (!cfg('require_email_verification', true)) {
            if ($r['status'] === 'created') { login_user((int)$r['uid']); flash('Conta criada. Bem-vindo(a)!'); redirect($convite ? '/convite-profissional.php?t=' . rawurlencode($conviteRaw) : '/'); }
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
  <?php if ($convite): ?><p>Depois de confirmar, o convite aparece em <strong>Conta → Meus pacientes</strong> para você aceitar (ou abra de novo o link do convite).</p><?php endif; ?>
  <p>Não chegou? Confira a pasta de spam ou <a href="/conta/reenviar.php">peça um novo link</a>.</p>
  <p><a class="btn" href="/">Voltar para o login</a></p>
<?php else: ?>
  <h1>Criar conta</h1>
  <?= flash_html() ?>
  <?php if ($errors): ?>
  <div class="flash flash-err" role="alert" tabindex="-1" id="form-errors"><strong>Revise o formulário:</strong><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <?php if ($convite): ?><p class="flash flash-ok" role="status">Você foi convidado(a) como profissional de saúde. Crie a conta com o e-mail do convite.</p><?php endif; ?>
  <form class="auth-form" method="post" action="/conta/cadastro.php" novalidate>
    <?= csrf_field() ?><?php if ($convite): ?><input type="hidden" name="convite" value="<?= e($conviteRaw) ?>"><?php endif; ?>
    <div class="field"><label for="cad-nome">Nome</label><input id="cad-nome" name="nome" required maxlength="150" autocomplete="name" value="<?= e($old['nome']) ?>"></div>
    <div class="field"><label for="cad-email">E-mail</label><input type="email" id="cad-email" name="email" required maxlength="190" autocomplete="email" inputmode="email" value="<?= e($old['email']) ?>"<?= $convite ? ' readonly aria-describedby="cad-email-conv"' : '' ?>><?php if ($convite): ?><p class="small" id="cad-email-conv">E-mail do convite (o convite só vale para ele).</p><?php endif; ?></div>
    <?= password_field('cad-senha', 'senha', 'Senha', 'new-password', true) ?>
    <fieldset class="field"><legend>Profissional de saúde? <span class="hint">(opcional)</span></legend>
      <label class="check"><input type="checkbox" name="profissional" value="1"<?= $old['pro'] ? ' checked' : '' ?>> <span>Sou profissional de saúde (autodeclarado) e quero receber compartilhamentos de pacientes</span></label>
      <div class="field"><label for="cad-tipo">Profissão</label><select id="cad-tipo" name="tipo"><option value="">—</option><?php foreach (PRO_KINDS as $k => $l): ?><option value="<?= $k ?>"<?= $old['tipo'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="cad-reg">CRP/CRM ou outro registro <span class="hint">(opcional; aparece como “declarado”, não é verificado)</span></label><input id="cad-reg" name="registro" maxlength="40" autocomplete="off" value="<?= e($old['registro']) ?>"></div>
    </fieldset>
    <label class="check consent"><input type="checkbox" name="consentimento" value="1" required>
      <span>Li a <a href="/privacidade.php" target="_blank" rel="noopener">Política de Privacidade</a> e os <a href="/termos.php" target="_blank" rel="noopener">Termos de Uso</a> e concordo com o tratamento dos meus dados de saúde para uso deste diário (LGPD, art. 11). Posso revogar excluindo a conta.</span></label>
    <button class="btn btn-primary btn-lg" type="submit">Criar conta</button>
    <p class="auth-links">Já tem conta? <a href="/">Entrar</a></p>
  </form>
<?php endif; ?>
</section>
<script src="/assets/js/auth.js?v=1"></script>
<?php page_foot();
