<?php
/**
 * Tela de bloqueio do app inteiro (biometria do aparelho ou PIN).
 * @param array<string,mixed> $user
 */
function view_lock_screen(array $user): void {
    page_head('Bloqueado', 'app locked-page'); ?>
  <section class="card narrow lock-screen" id="lock-screen" data-csrf="<?= e(csrf_token()) ?>">
    <h1>🔒 App bloqueado</h1>
    <p>Para proteger sua privacidade, desbloqueie com a biometria deste aparelho<?= !empty($user['sex_pin_hash']) ? ' ou com seu PIN' : '' ?>.</p>
    <?php if (webauthn_count($user) > 0): ?><p><button type="button" class="btn btn-primary btn-lg" id="btn-unlock-bio">Desbloquear com biometria</button></p><?php endif; ?>
    <?php if (!empty($user['sex_pin_hash'])): ?>
    <form id="form-unlock-pin" class="row" novalidate>
      <div class="field"><label for="unlock-pin">PIN</label><input id="unlock-pin" type="password" inputmode="numeric" autocomplete="off" maxlength="8"></div>
      <button class="btn" type="submit">Desbloquear com PIN</button>
    </form>
    <?php endif; ?>
    <p id="lock-msg" class="field-err" role="alert"></p>
    <form method="post" action="/auth/logout.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="btn btn-ghost" type="submit">Sair da conta</button></form>
  </section>
  <script src="/assets/js/passkey.js?v=3"></script>
<?php page_foot();
}
