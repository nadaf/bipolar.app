<?php
/**
 * V4.4: Conta → Verificação em duas etapas.
 * @param array<string,mixed> $user
 */
function view_account_mfa(array $user): void {
$on = mfa_enabled($user);
?>
<div class="card" id="conta-mfa">
  <h2>🛡️ Verificação em duas etapas</h2>
  <?php if (!mfa_eligible($user)): ?>
  <p>Você entra com uma conta externa (Google, Microsoft ou X). Para proteger o acesso com uma segunda etapa, ative a verificação em duas etapas na própria conta do provedor: ela passa a valer aqui também.</p>
  <?php elseif (!mfa_available()): ?>
  <p class="notice gentle"><?= e(MFA_NO_KEY_MSG) ?></p>
  <?php else: ?>
  <p class="small">Opcional. Além da senha, o login pede um código de 6 números do seu app autenticador (Google Authenticator, Microsoft Authenticator, Authy, Aegis ou outro compatível). Se alguém descobrir sua senha, ainda assim não consegue entrar.</p>
  <p id="mfa-status" role="status"><?php if ($on): ?><strong>Ativa</strong> desde <?= e(mfa_enabled_at_local($user)['date'] ?? '') ?>. Códigos de recuperação restantes: <span id="mfa-left"><?= mfa_recovery_left(db(), (int)$user['id']) ?></span>.<?php else: ?><strong>Desativada.</strong><?php endif; ?></p>

  <?php if (!$on): ?>
  <form id="form-mfa-start" class="grid2" novalidate>
    <?= password_field('mfa-senha', 'senha', 'Confirme sua senha para começar', 'current-password') ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Ativar a verificação em duas etapas</button></div>
  </form>
  <div id="mfa-setup" class="hidden">
    <ol class="small">
      <li>No app autenticador, toque em <strong>+</strong> (adicionar conta) e escolha <strong>ler QR code</strong>.</li>
      <li>Aponte a câmera para o código abaixo. Sem câmera? Escolha <strong>digitar a chave</strong> e use a chave em texto (tipo: baseada em tempo).</li>
      <li>Digite o código de 6 números que o app mostrar.</li>
    </ol>
    <p class="mfa-qr"><img id="mfa-qr" alt="QR code para cadastrar a conta Bipolar no app autenticador" width="220" height="220"></p>
    <p class="small">Chave para digitar à mão: <code id="mfa-secret" class="mfa-secret"></code><br>Conta: <span id="mfa-account"></span> · Emissor: Bipolar</p>
    <form id="form-mfa-confirm" class="grid2" novalidate>
      <div class="field"><label for="mfa-code">Código de 6 números</label><input id="mfa-code" name="codigo" inputmode="numeric" autocomplete="one-time-code" maxlength="7" pattern="[0-9 ]*" required></div>
      <div class="form-actions"><button class="btn btn-primary" type="submit">Confirmar e ativar</button></div>
    </form>
  </div>
  <?php else: ?>
  <form id="form-mfa-manage" class="grid2" novalidate>
    <?= password_field('mfa-senha2', 'senha', 'Senha da conta', 'current-password') ?>
    <div class="field"><label for="mfa-code2">Código do app ou de recuperação</label><input id="mfa-code2" name="codigo" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" maxlength="40" required></div>
    <div class="form-actions span-all row wrap">
      <button class="btn" type="submit" value="regen">Gerar novos códigos de recuperação</button>
      <button class="btn btn-danger-ghost" type="submit" value="disable">Desativar a verificação em duas etapas</button>
    </div>
  </form>
  <?php endif; ?>
  <div id="mfa-codes" class="hidden" tabindex="-1">
    <h3>Seus códigos de recuperação</h3>
    <p class="notice gentle">Guarde estes códigos em um lugar seguro (gerenciador de senhas ou papel). Cada um vale <strong>uma vez</strong> e serve para entrar se você perder o celular. Eles não aparecem de novo.</p>
    <ul id="mfa-codes-list" class="mfa-codes"></ul>
    <p class="row wrap"><button type="button" class="btn" id="mfa-copy">Copiar</button> <button type="button" class="btn" id="mfa-download">Baixar arquivo .txt</button> <button type="button" class="btn btn-primary" id="mfa-done">Já guardei</button></p>
  </div>
  <p class="small">Ao ativar, você sai dos outros aparelhos e enviamos um aviso por e-mail. O aviso nunca traz a chave nem os códigos.</p>
  <?php endif; ?>
</div>
<?php }
