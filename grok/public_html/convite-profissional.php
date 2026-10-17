<?php
/**
 * V4.8: link de convite para profissional (uso único, 14 dias, preso ao e-mail convidado).
 * Sem conta: leva ao cadastro já com o e-mail e "Profissional de saúde" marcados.
 * Com conta do MESMO e-mail (confirmado): aceita aqui. Outra conta: recusa sem mostrar o e-mail completo.
 */
require __DIR__ . '/_boot.php';
require_once APP_ROOT . '/lib/care.php';
require_once APP_ROOT . '/lib/smtp.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');
$pdo = db();
$t = (string)($_GET['t'] ?? $_POST['t'] ?? '');
if (!rate_limit('care_link', 60, 900)) { http_response_code(429); page_head('Aguarde'); echo '<section class="card narrow"><h1>Muitas tentativas</h1><p>Aguarde alguns minutos.</p></section>'; page_foot(); exit; }
$s = care_invite_by_token($pdo, $t);
$u = current_user();
$msg = null;
if ($s && $u && $_SERVER['REQUEST_METHOD'] === 'POST') {
    form_csrf_or_back('/convite-profissional.php?t=' . rawurlencode($t));
    if (care_invite_is_for($s, $u)) {
        if (!empty($_POST['recusar'])) {
            $pdo->prepare("UPDATE care_shares SET status = 'recusado', ended_at = UTC_TIMESTAMP(), ended_by = 'profissional', invite_token_hash = NULL WHERE id = ? AND status = 'pendente'")->execute([$s['id']]);
            care_log($pdo, (int)$s['id'], (int)$s['patient_id'], (int)$u['id'], care_display_name($u), 'recusado', (int)$s['scope_mask']);
            flash('Convite recusado.'); redirect('/?aba=conta');
        }
        if (!care_is_pro($u)) {
            if (empty($_POST['sou_profissional'])) $msg = 'Confirme que você é profissional de saúde para aceitar.';
            else {
                $kind = isset(PRO_KINDS[(string)($_POST['tipo'] ?? '')]) ? (string)$_POST['tipo'] : null;
                $reg = trim(mb_substr((string)preg_replace('/[\x00-\x1F]/', '', (string)($_POST['registro'] ?? '')), 0, 40));
                $pdo->prepare('UPDATE users SET is_professional = 1, professional_kind = ?, professional_reg = ? WHERE id = ?')->execute([$kind, $reg !== '' ? $reg : null, $u['id']]);
                $u['is_professional'] = 1; $u['professional_kind'] = $kind; $u['professional_reg'] = $reg;
            }
        }
        if ($msg === null) {
            $err = care_accept($pdo, $u, (int)$s['id']);
            if ($err === null) { flash('Convite aceito. O paciente aparece em Conta → Meus pacientes.'); redirect('/?aba=conta#care-card'); }
            $msg = $err;
        }
    }
}
$first = $s ? (trim(explode(' ', trim((string)$s['pname']))[0] ?? '') ?: 'Um paciente') : '';
page_head('Convite para profissional'); ?>
<section class="card narrow">
<?php if (!$s): ?>
  <h1>Convite inválido ou expirado</h1>
  <p>Este link já foi usado, foi cancelado pelo paciente ou passou do prazo de <?= CARE_INVITE_DAYS ?> dias. Peça um novo link a quem convidou você.</p>
  <p><a class="btn" href="/">Ir para o app</a></p>
<?php elseif (!$u): ?>
  <h1>Convite para acompanhar registros</h1>
  <p><strong><?= e($first) ?></strong> convidou você, como profissional de saúde, para ver (só leitura) os registros que escolheu compartilhar.</p>
  <p class="small">O convite só funciona com uma conta do e-mail convidado (<?= e(mask_email((string)$s['invite_email'])) ?>). Vale até <?= e(date('d/m/Y', (int)strtotime((string)$s['invite_expires_at'] . ' UTC'))) ?>.</p>
  <p><a class="btn btn-primary btn-lg" href="/conta/cadastro.php?convite=<?= e(rawurlencode($t)) ?>">Criar minha conta de profissional</a></p>
  <p>Já tem conta com esse e-mail? <a href="/">Entre</a> e abra este link de novo (o convite também aparece em Conta → Meus pacientes).</p>
<?php elseif (!care_invite_is_for($s, $u)): ?>
  <h1>Este convite é para outra conta</h1>
  <p>O convite foi enviado para <?= e(mask_email((string)$s['invite_email'])) ?> e só pode ser aceito por uma conta com esse e-mail confirmado. Saia e entre com a conta certa<?= care_verified_email($u) === null ? ', ou confirme seu e-mail primeiro' : '' ?>.</p>
  <p><a class="btn" href="/">Voltar</a></p>
<?php else: ?>
  <h1>Aceitar convite</h1>
  <?php if ($msg): ?><div class="flash flash-err" role="alert"><?= e($msg) ?></div><?php endif; ?>
  <p><strong><?= e($first) ?></strong> quer compartilhar com você (só leitura): <?= e(implode('; ', care_scope_labels((int)$s['scope_mask']))) ?>.</p>
  <?php if ($s['expires_on']): ?><p class="small">Acesso até <?= e(date('d/m/Y', (int)strtotime((string)$s['expires_on']))) ?>. O paciente pode encerrar a qualquer momento e vê quando você acessa.</p><?php endif; ?>
  <form method="post" action="/convite-profissional.php" class="auth-form">
    <?= csrf_field() ?><input type="hidden" name="t" value="<?= e($t) ?>">
    <?php if (!care_is_pro($u)): ?>
    <fieldset class="field"><legend>Seu perfil profissional (autodeclarado)</legend>
      <label class="check"><input type="checkbox" name="sou_profissional" value="1" required> <span>Declaro que sou profissional de saúde</span></label>
      <div class="field"><label for="cv-tipo">Profissão</label><select id="cv-tipo" name="tipo"><option value="">—</option><?php foreach (PRO_KINDS as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="cv-reg">CRP/CRM ou outro registro <span class="hint">(opcional; aparece como “declarado”, não é verificado)</span></label><input id="cv-reg" name="registro" maxlength="40" autocomplete="off"></div>
    </fieldset>
    <?php endif; ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Aceitar e ver os registros</button> <button class="btn btn-ghost" type="submit" name="recusar" value="1" formnovalidate>Recusar</button></div>
  </form>
<?php endif; ?>
</section>
<?php page_foot();
