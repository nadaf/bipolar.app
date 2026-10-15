<?php
/** @param array<string,mixed> $user */
function view_day_panels(array $user): void {
/** Blocos V2 do painel Registrar: marcadores do dia + Sexualidade (privado). Espera $user. */
$pf = user_prefs($user);
$mods = $pf['modules'];
$hide = function (string $k) use ($mods): string { return $mods[$k] ? '' : ' hidden'; };
$scale = function (string $id, string $name, string $label): string {
    $h = '<fieldset class="field mini-scale"><legend>' . e($label) . '</legend><div class="seg seg-sm">';
    foreach (['' => '—', '0' => 'Não', '1' => 'Leve', '2' => 'Moderado', '3' => 'Intenso'] as $v => $l) {
        $h .= '<input type="radio" name="' . $name . '" id="' . $id . '-' . ($v === '' ? 'x' : $v) . '" value="' . $v . '"' . ($v === '' ? ' checked' : '') . '><label for="' . $id . '-' . ($v === '' ? 'x' : $v) . '">' . $l . '</label>';
    }
    return $h . '</div></fieldset>';
};
$anyDay = $mods['sono'] || $mods['sol'] || $mods['habitos'] || $mods['mente'] || $mods['estresse'] || $mods['medicacao'] || $mods['sinais'] || $mods['ciclo'];
?>
<div id="early-warning" class="notice warn-early hidden" role="status" aria-live="polite"></div>

<form id="form-day" class="card day-card<?= $anyDay ? '' : ' hidden' ?>" novalidate>
  <div class="row between">
    <h2>Meu dia <span class="hint" id="day-date-label"></span></h2>
    <a href="#conta-modulos" class="small" id="link-modulos">Escolher o que aparece</a>
  </div>
  <p class="small">Tudo opcional. Preencha só o que fizer sentido — vale para o dia inteiro, não para cada período.</p>

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
    <summary>☕ Cafeína, álcool e exercício</summary>
    <div class="grid3">
      <div class="field"><label for="d-caf">Cafés / energéticos (xícaras)</label><input type="number" id="d-caf" name="caffeine_cups" min="0" max="30" inputmode="numeric"></div>
      <div class="field"><label for="d-alc">Álcool (doses)</label><input type="number" id="d-alc" name="alcohol_units" min="0" max="50" inputmode="numeric"<?= $mods['substancias'] ? ' readonly aria-describedby="d-alc-hint"' : '' ?>></div>
      <div class="field"><label for="d-ex">Exercício (minutos)</label><input type="number" id="d-ex" name="exercise_minutes" min="0" max="960" step="5" inputmode="numeric"></div>
    </div>
    <?php if ($mods['substancias']): ?><p class="small" id="d-alc-hint">O álcool é somado automaticamente dos registros de Álcool no módulo Substâncias.</p><?php endif; ?>
  </details>

  <details class="extras" data-mod="mente"<?= $hide('mente') ?>>
    <summary>⚡ Pensamentos, fala, gastos e irritação</summary>
    <?= $scale('d-rt', 'racing_thoughts', 'Pensamentos acelerados') ?>
    <?= $scale('d-fs', 'fast_speech', 'Fala acelerada / falando mais que o normal') ?>
    <?= $scale('d-sp', 'impulsive_spending', 'Gastos por impulso') ?>
    <div class="field"><label for="d-spv">Quanto gastei por impulso (R$, opcional)</label><input type="number" id="d-spv" name="spending_amount" min="0" step="0.01" inputmode="decimal"></div>
    <?= $scale('d-ir', 'irritability_day', 'Irritabilidade no dia') ?>
  </details>

  <details class="extras" data-mod="estresse"<?= $hide('estresse') ?>>
    <summary>🌧 Acontecimentos e estresse</summary>
    <?= $scale('d-st', 'stress_level', 'Nível de estresse') ?>
    <div class="field"><label for="d-stn">O que aconteceu? <span class="hint">(ex.: mudança, perda, conflito, viagem)</span></label><input id="d-stn" name="stress_note" maxlength="500"></div>
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
    <button type="button" class="btn btn-ghost btn-sm" id="sex-hide">Ocultar</button>
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
    <button type="button" class="btn btn-ghost btn-sm" id="subst-hide">Ocultar</button>
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
<?php }
