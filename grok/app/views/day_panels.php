<?php
/** @param array<string,mixed> $user */
function view_day_panels(array $user): void {
/** Blocos V2 do painel Registrar: marcadores do dia + Sexualidade (privado). Espera $user. */
$pf = user_prefs($user);
$mods = $pf['modules'];
$hide = function (string $k) use ($mods): string { return $mods[$k] ? '' : ' hidden'; };
$scale = function (string $id, string $name, string $label, ?array $levels = null): string {
    $h = '<fieldset class="field mini-scale"><legend>' . e($label) . '</legend><div class="seg seg-sm">';
    foreach ($levels ?? ['' => '—', '0' => 'Não', '1' => 'Leve', '2' => 'Moderado', '3' => 'Intenso'] as $v => $l) {
        $v = (string)$v;
        $sr = $v === '' ? '<span class="sr-only">Não informado</span>' : '';
        $h .= '<input type="radio" name="' . $name . '" id="' . $id . '-' . ($v === '' ? 'x' : $v) . '" value="' . $v . '"' . ($v === '' ? ' checked' : '') . '><label for="' . $id . '-' . ($v === '' ? 'x' : $v) . '">' . ($v === '' ? '<span aria-hidden="true">' . $l . '</span>' . $sr : $l) . '</label>';
    }
    return $h . '</div></fieldset>';
};
$foodLv = ['' => '—', '0' => 'Nenhum', '1' => 'Pouco', '2' => 'Moderado', '3' => 'Muito'];
$anyDay = $mods['sono'] || $mods['sol'] || $mods['habitos'] || $mods['mente'] || $mods['pensamentos'] || $mods['estresse'] || $mods['alimentacao'] || $mods['medicacao'] || $mods['sinais'] || $mods['ciclo'];
$thLv = ['' => '—'] + array_map('strval', THOUGHT_LEVELS);
$tc = $pf['trusted_contact'];
?>
<div id="early-warning" class="notice warn-early hidden" role="status" aria-live="polite"></div>

<form id="form-day" class="card day-card<?= $anyDay ? '' : ' hidden' ?>" novalidate>
  <div class="row between">
    <h2>Meu dia <span class="hint" id="day-date-label"></span></h2>
    <a href="#conta-modulos" class="small" id="link-modulos">Escolher o que aparece</a>
  </div>
  <p id="day-load-note" class="notice small hidden" role="status" aria-live="polite"></p>
  <p class="small">Tudo opcional. Preencha só o que fizer sentido. Sono, sol, hábitos, fala/gastos e medicação valem para o dia inteiro. <strong>Pensamentos, acontecimentos/estresse e alimentação são do período</strong> escolhido em “Como você está?” (<span class="day-period-name">—</span>).</p>

  <details class="extras" data-mod="sono"<?= $hide('sono') ?>>
    <summary>😴 Sono</summary>
    <div class="grid3">
      <div class="field"><label for="d-bed">Fui deitar às</label><input type="text" inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" placeholder="hh:mm" autocomplete="off" class="time24" id="d-bed" name="bed_time"></div>
      <div class="field"><label for="d-wake">Acordei às</label><input type="text" inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" placeholder="hh:mm" autocomplete="off" class="time24" id="d-wake" name="wake_time"></div>
      <div class="field"><label for="d-sq">Qualidade do sono</label><select id="d-sq" name="sleep_quality"><option value="">—</option><option value="0">Ruim</option><option value="1">Regular</option><option value="2">Boa</option><option value="3">Ótima</option></select></div>
    </div>
    <p class="small" id="d-sleep-calc" role="status"></p>
  </details>

  <details class="extras" data-mod="sol"<?= $hide('sol') ?>>
    <summary>☀️ Sol e luz do dia</summary>
    <div class="grid2">
      <div class="field"><label for="d-sun">Minutos no sol / luz natural</label><input type="number" id="d-sun" name="sun_minutes" min="0" max="960" step="5" inputmode="numeric"></div>
      <div class="field"><label for="d-sunp">Em que momento?</label><select id="d-sunp" name="sun_period"><option value="">—</option><?php foreach (SUN_PERIODS as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    </div>
  </details>

  <details class="extras" data-mod="habitos"<?= $hide('habitos') ?>>
    <summary>☕ Cafeína, nicotina, álcool e exercício</summary>
    <div class="grid3">
      <div class="field"><label for="d-caf">Cafés / energéticos (xícaras)</label><input type="number" id="d-caf" name="caffeine_cups" min="0" max="30" inputmode="numeric"></div>
      <div class="field"><label for="d-cig">Cigarros (unidades)</label><input type="number" id="d-cig" name="cigarettes" min="0" max="100" step="1" inputmode="numeric"></div>
      <div class="field"><label for="d-nic">Outra forma de nicotina</label><select id="d-nic" name="nicotine_other"><option value="">—</option><?php foreach (NICOTINE_OTHER as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="d-alc">Álcool (doses)</label><input type="number" id="d-alc" name="alcohol_units" min="0" max="50" inputmode="numeric"<?= $mods['substancias'] ? ' readonly aria-describedby="d-alc-hint"' : '' ?>></div>
      <div class="field"><label for="d-ex">Exercício (minutos)</label><input type="number" id="d-ex" name="exercise_minutes" min="0" max="960" step="5" inputmode="numeric"></div>
    </div>
    <?php if ($mods['substancias']): ?><p class="small" id="d-alc-hint">O álcool é somado automaticamente dos registros de Álcool no módulo Substâncias.</p><?php endif; ?>
  </details>

  <details class="extras" data-mod="mente"<?= $hide('mente') ?>>
    <summary>⚡ <?= $mods['pensamentos'] ? 'Fala, gastos e irritação' : 'Pensamentos, fala, gastos e irritação' ?></summary>
    <?php if (!$mods['pensamentos']): // V4.8: com "Pensamentos por período" ligado, os acelerados são marcados por período ?><?= $scale('d-rt', 'racing_thoughts', 'Pensamentos acelerados') ?><?php endif; ?>
    <?= $scale('d-fs', 'fast_speech', 'Fala acelerada / falando mais que o normal') ?>
    <?= $scale('d-sp', 'impulsive_spending', 'Gastos por impulso') ?>
    <div class="field"><label for="d-spv">Quanto gastei por impulso (R$, opcional)</label><input type="number" id="d-spv" name="spending_amount" min="0" step="0.01" inputmode="decimal"></div>
    <?= $scale('d-ir', 'irritability_day', 'Irritabilidade no dia') ?>
  </details>

  <details class="extras" data-mod="pensamentos" data-per-period<?= $hide('pensamentos') ?>>
    <summary>💭 Pensamentos <span class="hint">· <span class="day-period-name">—</span></span></summary>
    <p class="small">Como estiveram seus pensamentos neste período? Marque só o que fizer sentido.</p>
    <?php foreach (THOUGHT_FIELDS as $k => [$l, $help]): if ($k === 'th_death') continue; ?><?= $scale('d-' . str_replace('_', '', $k), $k, $l . ($help ? ' (' . $help . ')' : ''), $thLv) ?><?php endforeach; ?>
  </details>

  <details class="extras" data-mod="estresse" data-per-period<?= $hide('estresse') ?>>
    <summary>🌧 Acontecimentos e estresse <span class="hint">· <span class="day-period-name">—</span></span></summary>
    <?= $scale('d-st', 'stress_level', 'Nível de estresse neste período') ?>
    <div class="field"><label for="d-stn">O que aconteceu? <span class="hint">(ex.: mudança, perda, conflito, viagem)</span></label><input id="d-stn" name="stress_note" maxlength="500"></div>
  </details>

  <details class="extras" data-mod="alimentacao" data-per-period<?= $hide('alimentacao') ?>>
    <summary>🍽 Alimentação <span class="hint">· <span class="day-period-name">—</span></span></summary>
    <p class="small">Sem julgamento: mudanças no apetite e na vontade de doces acompanham muitas fases do humor.</p>
    <?= $scale('d-fsw', 'food_sweets', 'Doces', $foodLv) ?>
    <?= $scale('d-fpr', 'food_processed', 'Industrializados / ultraprocessados', $foodLv) ?>
    <label class="check"><input type="checkbox" id="d-fbi" name="food_binge" value="1"> <span>Comi fora de hora / compulsão</span></label>
  </details>

  <details class="extras" data-mod="medicacao"<?= $hide('medicacao') ?>>
    <summary>💊 Medicação por dose</summary>
    <div id="d-doses" class="checks"><?php if (!$pf['meds']): ?><p class="small">Cadastre seus horários de medicação em Conta → Módulos.</p><?php endif; foreach ($pf['meds'] as $md): ?>
      <label class="check"><input type="checkbox" name="dose" value="<?= e($md['id']) ?>"> <span><?= e($md['time'] . ' · ' . $md['name'] . ($md['dose'] ? ' (' . $md['dose'] . ')' : '')) ?></span></label>
    <?php endforeach; ?></div>
  </details>

  <details class="extras" data-mod="sinais"<?= $hide('sinais') ?>>
    <summary>🚩 Meus sinais de alerta pessoais</summary>
    <div id="d-signs" class="checks"><?php if (!$pf['signs']): ?><p class="small">Combine com seu terapeuta quais sinais costumam aparecer antes de uma crise e cadastre-os em Conta → Módulos.</p><?php endif; foreach ($pf['signs'] as $i => $sg): ?>
      <label class="check"><input type="checkbox" name="sign" value="<?= $i ?>"> <span><?= e($sg) ?></span></label>
    <?php endforeach; ?></div>
  </details>

  <details class="extras" data-mod="ciclo"<?= $hide('ciclo') ?>>
    <summary>🩸 Ciclo menstrual</summary>
    <div class="field"><label for="d-cy">Fase do ciclo</label><select id="d-cy" name="cycle_phase"><option value="">—</option><?php foreach (CYCLE_PHASES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
  </details>

  <div class="form-actions"><button type="submit" class="btn btn-primary" id="btn-save-day">Salvar meu dia</button></div>
</form>

<form id="form-sex" class="card sex-card<?= $mods['sexualidade'] ? '' : ' hidden' ?><?= $pf['sex_blur'] ? ' blurred' : '' ?>" novalidate aria-labelledby="sex-title">
  <div class="row between">
    <h2 id="sex-title">🔒 Sexualidade <span class="hint">(privado)</span></h2>
    <button type="button" class="btn btn-ghost btn-sm" id="sex-hide" aria-controls="sex-body" aria-label="<?= $pf['sex_blur'] ? 'Revelar' : 'Ocultar' ?> dados de Sexualidade"><?= $pf['sex_blur'] ? 'Revelar' : 'Ocultar' ?></button>
  </div>
  <div id="sex-locked" class="sex-locked hidden">
    <p>Esta seção está protegida por PIN.</p>
    <div class="row pin-row"><div class="field"><label for="sex-pin">PIN</label><input id="sex-pin" type="password" inputmode="numeric" autocomplete="off" maxlength="8"></div><button type="button" class="btn btn-primary" id="sex-unlock">Desbloquear</button></div>
  </div>
  <button type="button" class="btn sex-reveal" id="sex-reveal">Toque para mostrar</button>
  <div id="sex-body" class="sex-body">
    <p class="small">Mudanças no desejo e no comportamento sexual estão entre os sinais mais comuns de hipomania e mania — e quase nunca são perguntadas. Registrar com honestidade, sem julgamento, ajuda você e sua equipe a perceber cedo. Vale para qualquer orientação, identidade e tipo de relação. Só você vê; nunca vai em lembretes, e só entra em relatório se você autorizar para um destinatário específico.</p>
    <fieldset class="field"><legend>Desejo sexual hoje, comparado ao seu normal</legend>
      <div class="seg seg-sm"><?php foreach (['' => '—', '-2' => 'Bem menor', '-1' => 'Menor', '0' => 'Como sempre', '1' => 'Maior', '2' => 'Bem maior'] as $v => $l): $id = 'sx-l' . ($v === '' ? 'x' : (int)$v + 2); ?><input type="radio" name="libido" id="<?= $id ?>" value="<?= $v ?>"<?= $v === '' ? ' checked' : '' ?>><label for="<?= $id ?>"><?= $l ?></label><?php endforeach; ?></div>
    </fieldset>
    <fieldset class="field"><legend>O que aconteceu hoje? <span class="hint">(marque quantos quiser)</span></legend>
      <div class="checks"><?php foreach (SEX_ACTIVITIES as $k => [$l]): ?><label class="check"><input type="checkbox" name="activities" value="<?= $k ?>"> <span><?= e($l) ?></span></label><?php endforeach; ?></div>
    </fieldset>
    <div class="grid2">
      <div class="field"><label for="sx-freq">Quantas vezes? <span class="hint">(qualquer atividade)</span></label><input type="number" id="sx-freq" name="frequency" min="0" max="50" inputmode="numeric"></div>
      <div class="field" id="sx-online-f"><label for="sx-online">Tempo online com conteúdo/apps (min)</label><input type="number" id="sx-online" name="online_minutes" min="0" max="1440" step="5" inputmode="numeric"></div>
    </div>
    <fieldset class="field"><legend>Isso é diferente do meu habitual?</legend>
      <div class="seg seg-sm"><input type="radio" name="different_from_usual" id="sx-d1" value="1"><label for="sx-d1">Sim</label><input type="radio" name="different_from_usual" id="sx-d0" value="0"><label for="sx-d0">Não</label><input type="radio" name="different_from_usual" id="sx-dx" value="" checked><label for="sx-dx">Não sei</label></div>
    </fieldset>
    <div class="field"><label for="sx-note">Anotação privada</label><textarea id="sx-note" name="note" rows="2" maxlength="2000"></textarea></div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Salvar (privado)</button></div>
  </div>
</form>

<form id="form-subst" class="card sex-card subst-card<?= $mods['substancias'] ? '' : ' hidden' ?><?= $pf['subst_blur'] ? ' blurred' : '' ?>" novalidate aria-labelledby="subst-title">
  <div class="row between">
    <h2 id="subst-title">🔒 Substâncias <span class="hint">(privado)</span></h2>
    <button type="button" class="btn btn-ghost btn-sm" id="subst-hide" aria-controls="subst-body" aria-label="<?= $pf['subst_blur'] ? 'Revelar' : 'Ocultar' ?> dados de Substâncias"><?= $pf['subst_blur'] ? 'Revelar' : 'Ocultar' ?></button>
  </div>
  <div id="subst-locked" class="sex-locked hidden">
    <p>Esta seção está protegida pelo seu PIN.</p>
    <div class="row pin-row"><div class="field"><label for="subst-pin">PIN</label><input id="subst-pin" type="password" inputmode="numeric" autocomplete="off" maxlength="8"></div><button type="button" class="btn btn-primary" id="subst-unlock">Desbloquear</button></div>
  </div>
  <button type="button" class="btn sex-reveal" id="subst-reveal">Toque para mostrar</button>
  <div id="subst-body" class="sex-body">
    <p class="small">Sem julgamento: registrar o que você usa, quanto e como se sentiu ajuda a perceber padrões — e a conversar abertamente com seu psiquiatra sobre interações com a medicação e sobre o humor. Só você vê; nunca vai em lembretes.</p>
    <details class="extras"><summary>Por que isso importa no transtorno bipolar?</summary>
      <ul class="small">
        <li>Cannabis: estudos associam o uso a piora de sintomas maníacos, início mais cedo do transtorno e mais tempo em episódios. Observar o que acontece com <em>você</em> (sono, humor no dia seguinte) é o mais útil.</li>
        <li>Estimulantes (cocaína, anfetaminas, MDMA) e psicodélicos podem desencadear hipomania/mania, principalmente com sono curto.</li>
        <li>Álcool, benzodiazepínicos e opioides somam sedação com vários remédios; cigarro reduz o nível de olanzapina e clozapina; desidratação (calor, vômitos, festas) pode elevar o lítio.</li>
        <li>Se for usar: evite misturar, hidrate-se, proteja o sono, não use sozinho(a) substâncias de risco e conte ao seu psiquiatra — sem medo.</li>
      </ul>
    </details>
    <div id="subst-favs" class="fav-row" aria-label="Favoritos: registrar com 1 toque"></div>
    <div class="grid2">
      <div class="field"><label for="su-sub">Substância</label><select id="su-sub" name="substance" data-meta="<?= e((string)json_encode(array_map(function (array $x): array { return ['label' => $x[0], 'unit' => $x[1], 'forms' => $x[2] ?: new stdClass()]; }, SUBSTANCES), JSON_UNESCAPED_UNICODE)) ?>"><option value="">Escolha…</option><?php foreach (SUBSTANCES as $k => [$l]): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field hidden" id="su-form-f"><label for="su-form">Forma</label><select id="su-form" name="form"><option value="">—</option></select></div>
      <div class="field hidden" id="su-other-f"><label for="su-other">Qual?</label><input id="su-other" name="other_name" maxlength="200"></div>
      <div class="field"><label for="su-amt">Quantidade aproximada <span class="hint" id="su-unit"></span></label><input type="number" id="su-amt" name="amount" min="0" max="9999" step="0.5" inputmode="decimal"></div>
      <div class="field"><label for="su-reason">Motivo <span class="hint">(opcional)</span></label><select id="su-reason" name="reason"><option value="">—</option><?php foreach (SUBST_REASONS as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    </div>
    <fieldset class="field"><legend>Período</legend>
      <div class="seg seg-sm"><?php foreach (PERIODS as $k => $l): ?><input type="radio" name="su_period" id="su-p-<?= $k ?>" value="<?= $k ?>"><label for="su-p-<?= $k ?>"><?= e($l) ?></label><?php endforeach; ?></div>
    </fieldset>
    <fieldset class="field"><legend>Efeito que você percebeu no humor</legend>
      <div class="seg seg-sm"><?php foreach (['' => '—', '-2' => 'Bem pior', '-1' => 'Pior', '0' => 'Nenhum', '1' => 'Melhor', '2' => 'Bem melhor'] as $v => $l): $id = 'su-e' . ($v === '' ? 'x' : (int)$v + 2); ?><input type="radio" name="mood_effect" id="<?= $id ?>" value="<?= $v ?>"<?= $v === '' ? ' checked' : '' ?>><label for="<?= $id ?>"><?= $l ?></label><?php endforeach; ?></div>
    </fieldset>
    <fieldset class="field"><legend>Mais que o meu habitual?</legend>
      <div class="seg seg-sm"><input type="radio" name="above_usual" id="su-a1" value="1"><label for="su-a1">Sim</label><input type="radio" name="above_usual" id="su-a0" value="0"><label for="su-a0">Não</label><input type="radio" name="above_usual" id="su-ax" value="" checked><label for="su-ax">Não sei</label></div>
    </fieldset>
    <label class="check"><input type="checkbox" id="su-fav" value="1"> <span>☆ Salvar como favorito (registro com 1 toque)</span></label>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Adicionar registro</button></div>
    <h3 class="small-title">Registros do dia</h3>
    <ul id="subst-list" class="list"></ul>
  </div>
</form>

<form id="form-death" class="card sex-card death-card<?= $mods['pensamentos_morte'] ? '' : ' hidden' ?><?= $pf['death_blur'] ? ' blurred' : '' ?>" novalidate aria-labelledby="death-title">
  <div class="row between">
    <h2 id="death-title">🔒 Pensamentos de morte <span class="hint">(privado · <span class="day-period-name">—</span>)</span></h2>
    <button type="button" class="btn btn-ghost btn-sm" id="death-hide" aria-controls="death-body" aria-label="<?= $pf['death_blur'] ? 'Revelar' : 'Ocultar' ?> dados de Pensamentos de morte"><?= $pf['death_blur'] ? 'Revelar' : 'Ocultar' ?></button>
  </div>
  <div id="death-locked" class="sex-locked hidden">
    <p>Esta seção está protegida pelo seu PIN.</p>
    <div class="row pin-row"><div class="field"><label for="death-pin">PIN</label><input id="death-pin" type="password" inputmode="numeric" autocomplete="off" maxlength="8"></div><button type="button" class="btn btn-primary" id="death-unlock">Desbloquear</button></div>
  </div>
  <button type="button" class="btn sex-reveal" id="death-reveal">Toque para mostrar</button>
  <div id="death-body" class="sex-body">
    <p class="small">Sem julgamento. Registrar ajuda a perceber quando pedir ajuda. Ninguém é avisado automaticamente.</p>
    <?= $scale('d-thd', 'th_death', 'Pensamentos de morte ou suicídio neste período', $thLv) ?>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Salvar (privado)</button></div>
  </div>
</form>

<dialog id="support-panel" class="dlg support-panel" role="alertdialog" aria-modal="true" aria-labelledby="support-title" aria-describedby="support-desc">
  <h2 id="support-title" tabindex="-1">Você não está sozinho(a) 💛</h2>
  <div id="support-desc">
    <p>Obrigado por registrar. Pensamentos assim são difíceis, e conversar com alguém agora pode ajudar.</p>
    <p class="support-urgent"><strong>Se estiver em perigo agora, ligue 192 ou vá ao pronto-socorro.</strong></p>
  </div>
  <ul class="support-list">
    <li><a class="btn btn-primary" href="tel:188">Ligar para o CVV 188</a> <span class="small">24 horas, gratuito. Também por chat em <a href="https://cvv.org.br" target="_blank" rel="noopener">cvv.org.br</a>.</span></li>
    <li><a class="btn" href="tel:192">Ligar para o SAMU 192</a></li>
    <?php if ($tc['phone'] !== ''): ?><li><a class="btn" href="tel:<?= e($tc['phone']) ?>">Ligar para <?= e($tc['name'] !== '' ? $tc['name'] : 'meu contato de confiança') ?></a></li><?php endif; ?>
  </ul>
  <p class="small">Ninguém foi avisado. Este painel aparece só para você.</p>
  <div class="form-actions"><button type="button" class="btn btn-ghost" id="support-close">Fechar</button></div>
</dialog>
<?php }
