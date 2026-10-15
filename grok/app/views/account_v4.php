<?php
/**
 * Conta → Biometria e bloqueio, Alertas automáticos e "Reflexões com IA" (provedor e chave próprios).
 * @param array<string,mixed> $user
 * @param array{consent:bool,consent_at:?string,categories:list<string>} $aiSet
 * @param array{minutes:int,app:bool,on_hide:bool} $lockSet
 */
function view_account_v4(array $user, bool $aiOn, array $aiSet, array $lockSet): void {
$ap = alert_prefs($user);
?>
<div class="card" id="conta-biometria">
  <h2>🔐 Biometria e bloqueio</h2>
  <p class="small">Use Face ID, Touch ID, Windows Hello ou a digital do Android para abrir as seções privadas (Sexualidade, Substâncias) — e, se quiser, o app inteiro. A biometria nunca sai do seu aparelho: o site recebe só uma assinatura. O PIN continua valendo como alternativa.</p>
  <ul id="wa-list" class="list"><li class="muted">Carregando…</li></ul>
  <p id="wa-unsupported" class="notice gentle hidden">Este navegador não oferece biometria compatível (WebAuthn). Use o PIN.</p>
  <form id="form-wa-add" class="row" novalidate>
    <div class="field"><label for="wa-name">Nome deste aparelho</label><input id="wa-name" maxlength="100" placeholder="ex.: Meu celular"></div>
    <button type="submit" class="btn">Cadastrar biometria deste aparelho</button>
  </form>
  <form id="form-lock" class="grid2" novalidate>
    <div class="field"><label for="lk-min">Desbloqueio vale por (minutos)</label><input id="lk-min" type="number" min="1" max="240" value="<?= (int)$lockSet['minutes'] ?>"></div>
    <div class="checks span-all">
      <label class="check"><input type="checkbox" id="lk-hide"<?= $lockSet['on_hide'] ? ' checked' : '' ?>> <span>Bloquear de novo quando eu sair do app ou trocar de aba</span></label>
      <label class="check"><input type="checkbox" id="lk-app"<?= $lockSet['app'] ? ' checked' : '' ?>> <span>Bloquear o app inteiro ao abrir</span></label>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar bloqueio</button></div>
  </form>
</div>

<div class="card" id="conta-alertas">
  <h2>🔔 Alertas automáticos</h2>
  <p class="small">A cada hora o app procura sinais de (hipo)mania, depressão, estado misto, instabilidade, dias sem registro, doses esquecidas, substâncias acima do habitual e seus sinais pessoais. Notificações e e-mails dizem só “há um novo aviso” — os detalhes ficam aqui no app.</p>
  <form id="form-alert-prefs" class="checks" novalidate>
    <label class="check"><input type="checkbox" name="engine"<?= $ap['engine'] ? ' checked' : '' ?>> <span>Analisar meus registros e mostrar alertas</span></label>
    <label class="check"><input type="checkbox" name="push"<?= $ap['push'] ? ' checked' : '' ?>> <span>Avisar por notificação (push)</span></label>
    <label class="check"><input type="checkbox" name="email"<?= $ap['email'] ? ' checked' : '' ?>> <span>Avisar também por e-mail</span></label>
    <label class="check"><input type="checkbox" name="reset_tune"> <span>Voltar a sensibilidade ao padrão (desfaz os ajustes de “faz / não faz sentido”)</span></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar alertas</button></div>
  </form>
  <p class="small">Pessoas de apoio só recebem alertas se você marcar “Receber alertas de apoio” para elas em Relatórios.</p>
</div>

<?php if ($aiOn): $aiChoice = ai_user_choice($user); $aiProv = ai_providers(); ?>
<div class="card" id="conta-ia">
  <h2>💭 Reflexões com IA (opcional)</h2>
  <p class="small">Resumo reflexivo da semana, explicação dos alertas, preparo para a consulta e check-in por conversa ou voz. <strong>Desligado até você escolher um provedor, colar a sua chave e consentir.</strong></p>
  <ul class="small">
    <li>Você usa a <strong>sua própria chave</strong> de um provedor de IA. O uso é cobrado por ele, direto na sua conta de lá.</li>
    <li>A cada uso, as categorias escolhidas abaixo são enviadas ao provedor que você escolheu, sem seu nome completo nem e-mail, e tratadas conforme os termos dele. Ele pode ficar fora do Brasil (por exemplo, nos EUA).</li>
    <li>Sua chave fica guardada <strong>cifrada</strong> no servidor e nunca aparece de novo na tela: mostramos só os 4 últimos caracteres. Você pode trocar ou remover quando quiser.</li>
    <li>A IA pode errar. Ela é instruída a não diagnosticar e a nunca sugerir mudança de remédio ou dose.</li>
    <li>Se você escrever algo que indique risco, a conversa é pausada no próprio app e mostramos CVV 188 e SAMU 192, sem enviar o texto à IA.</li>
    <li>Você pode revogar quando quiser (LGPD, art. 8º §5º e art. 18).</li>
  </ul>
  <?php if (ai_byok_available()): ?>
  <form id="form-ai-key" class="grid2" novalidate autocomplete="off">
    <div class="field"><label for="ai-provider">Provedor</label>
      <select id="ai-provider" name="provider"><option value="">Escolha…</option>
        <?php foreach ($aiProv as $k => $p): ?><option value="<?= e($k) ?>"<?= $aiChoice['provider'] === $k ? ' selected' : '' ?>><?= e($p['label']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="ai-model-sel">Modelo</label>
      <select id="ai-model-sel"></select></div>
    <div class="field"><label for="ai-model">Nome do modelo (pode digitar outro)</label><input id="ai-model" name="model" maxlength="100" spellcheck="false" autocapitalize="off" value="<?= e($aiChoice['model']) ?>"></div>
    <div class="field<?= $aiChoice['provider'] === 'custom' ? '' : ' hidden' ?>" id="ai-base-wrap"><label for="ai-base">URL base compatível com OpenAI</label><input id="ai-base" name="base_url" type="url" maxlength="255" placeholder="https://exemplo.com/v1" value="<?= e($aiChoice['base_url']) ?>"></div>
    <div class="field span-all"><label for="ai-key">Chave de API</label><input id="ai-key" name="api_key" type="password" maxlength="400" spellcheck="false" autocapitalize="off" autocomplete="off" placeholder="<?= $aiChoice['has_key'] ? 'Chave salva terminando em …' . e($aiChoice['last4']) . ' (deixe vazio para manter)' : 'Cole aqui a sua chave' ?>" aria-describedby="ai-key-help"></div>
    <p class="small span-all" id="ai-key-help"><span id="ai-key-status"><?= $aiChoice['has_key'] ? 'Chave salva: …' . e($aiChoice['last4']) . '.' : 'Nenhuma chave salva.' ?></span> <a id="ai-keys-link" href="#" target="_blank" rel="noopener" class="hidden">Onde pego minha chave?</a> <a id="ai-terms-link" href="#" target="_blank" rel="noopener" class="hidden">Termos do provedor</a></p>
    <div class="form-actions span-all row wrap">
      <button class="btn" type="button" id="ai-test">Testar chave</button>
      <button class="btn btn-primary" type="submit">Salvar provedor e chave</button>
      <button class="btn btn-danger-ghost<?= $aiChoice['has_key'] ? '' : ' hidden' ?>" type="button" id="ai-remove">Remover chave</button>
    </div>
    <p class="small span-all" id="ai-test-out" role="status" aria-live="polite"></p>
  </form>
  <?php else: ?>
  <p class="notice gentle">Chaves próprias ainda não estão disponíveis neste servidor (o responsável precisa preencher <code>ai.secret_key</code> no config.php).</p>
  <?php endif; ?>
  <form id="form-ai" novalidate>
    <fieldset class="checks"><legend>O que pode ser enviado</legend>
      <?php foreach (AI_CATEGORIES as $k => [$l]): ?>
      <label class="check"><input type="checkbox" name="cat" value="<?= e($k) ?>"<?= in_array($k, $aiSet['categories'], true) ? ' checked' : '' ?>> <span><?= e($l) ?></span></label>
      <?php endforeach; ?>
    </fieldset>
    <label class="check"><input type="checkbox" id="ai-consent"<?= $aiSet['consent'] ? ' checked' : '' ?>> <span>Li e <strong>consinto</strong> com o envio dessas categorias de dados de saúde ao provedor de IA que escolhi, para estes recursos.</span></label>
    <?php if ($aiSet['consent'] && $aiSet['consent_at']): ?><p class="small">Consentimento dado em <?= e(date('d/m/Y H:i', (int)strtotime($aiSet['consent_at']))) ?> (UTC).</p><?php endif; ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar</button></div>
  </form>
  <p class="small" id="ai-usage"></p>
</div>
<?php endif; ?>
<?php }
