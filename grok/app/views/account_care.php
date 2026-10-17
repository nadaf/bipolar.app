<?php
/**
 * V4.8: Conta → Compartilhar com profissional (paciente) e Profissional de saúde (Meus pacientes).
 * @param array<string,mixed> $user
 */
function view_account_care(array $user): void {
require_once APP_ROOT . '/lib/care.php';
$pro = care_is_pro($user);
?>
<div class="card" id="care-card">
  <h2>Compartilhar com profissional</h2>
  <p class="small">Convide seu psiquiatra, psicólogo(a) ou outro profissional para <strong>ver</strong> (só leitura) os registros que você escolher. Ele precisa aceitar. Você escolhe as categorias e o prazo, pode mudar ou encerrar quando quiser (vale na hora) e vê aqui quem viu o quê e quando. O profissional nunca edita nada e não baixa seus dados brutos.</p>
  <form id="form-care-invite" novalidate>
    <fieldset class="field"><legend>Convidar por</legend>
      <div class="seg seg-sm"><input type="radio" name="care_by" id="care-by-email" value="email" checked><label for="care-by-email">E-mail do profissional</label><input type="radio" name="care_by" id="care-by-code" value="code"><label for="care-by-code">Código que ele gerou</label></div>
    </fieldset>
    <div class="field" id="care-email-f"><label for="care-email">E-mail do profissional</label><input id="care-email" type="email" maxlength="190" autocomplete="off" inputmode="email"></div>
    <div class="field hidden" id="care-code-f"><label for="care-code">Código (ex.: ABCD-2345)</label><input id="care-code" maxlength="12" autocomplete="off" autocapitalize="characters"></div>
    <fieldset class="field care-scope"><legend>O que compartilhar</legend>
      <?php foreach (CARE_SCOPE as $k => [$bit, $label, $def, $sens]): ?>
      <label class="check"><input type="checkbox" name="care_scope" value="<?= $k ?>"<?= $def ? ' checked' : '' ?>> <span><?= e($label) ?><?= $sens ? ' <strong>(sensível; pede seu PIN)</strong>' : '' ?></span></label>
      <?php endforeach; ?>
    </fieldset>
    <div class="grid2">
      <div class="field"><label for="care-from">Dados a partir de <span class="hint">(opcional)</span></label><input type="date" id="care-from"></div>
      <div class="field"><label for="care-until">Acesso até <span class="hint">(opcional)</span></label><input type="date" id="care-until"></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Enviar convite</button></div>
  </form>
  <div id="care-link-box" class="notice hidden" role="status" tabindex="-1">
    <p id="care-link-msg"></p>
    <div class="field"><label for="care-link">Link do convite (uso único)</label><input id="care-link" readonly></div>
    <p class="row"><button type="button" class="btn btn-sm" id="care-copy">Copiar link</button> <a class="btn btn-sm" id="care-wa" href="#" target="_blank" rel="noopener">Enviar pelo WhatsApp</a> <button type="button" class="btn btn-sm hidden" id="care-share">Compartilhar…</button></p>
  </div>
  <h3>Meus compartilhamentos</h3>
  <ul id="care-shares" class="list care-list"><li class="muted">Carregando…</li></ul>
  <details class="extras" id="care-log-box"><summary>Quem viu o quê e quando</summary><ul id="care-log" class="list"></ul></details>
</div>

<div class="card" id="care-pro-card">
  <h2>Profissional de saúde</h2>
  <form id="form-care-profile" class="grid2" novalidate>
    <label class="check span-all"><input type="checkbox" id="cp-on" name="is_professional" value="1"<?= $pro ? ' checked' : '' ?>> <span>Sou profissional de saúde (autodeclarado) e quero receber compartilhamentos</span></label>
    <div class="field"><label for="cp-kind">Profissão</label><select id="cp-kind"><option value="">—</option><?php foreach (PRO_KINDS as $k => $l): ?><option value="<?= $k ?>"<?= ($user['professional_kind'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="cp-reg">CRP/CRM ou outro registro <span class="hint">(opcional; aparece como “declarado”, não é verificado)</span></label><input id="cp-reg" maxlength="40" autocomplete="off" value="<?= e((string)($user['professional_reg'] ?? '')) ?>"></div>
    <div class="form-actions"><button class="btn" type="submit">Salvar perfil</button></div>
  </form>
  <h3>Convites recebidos</h3>
  <ul id="care-pending" class="list care-list"><li class="muted">Nenhum convite.</li></ul>
  <div id="care-pro-area"<?= $pro ? '' : ' hidden' ?>>
    <h3>Código para o paciente</h3>
    <p class="small">Gere um código de uso único (vale <?= CARE_CODE_HOURS ?> h) e passe ao paciente: ele digita em “Compartilhar com profissional”.</p>
    <p class="row"><button type="button" class="btn" id="care-code-btn">Gerar código</button> <output id="care-code-out" class="care-code" aria-live="polite"></output></p>
    <h3>Meus pacientes</h3>
    <ul id="care-patients" class="list care-list"><li class="muted">Nenhum paciente ainda.</li></ul>
  </div>
</div>
<?php }
