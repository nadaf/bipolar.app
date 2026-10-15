<?php
require __DIR__ . '/_boot.php';
$user = current_user();
if (!$user) {
    require_once APP_ROOT . '/lib/oauth.php';
    $providers = oauth_enabled_providers();
    $oldEmail = (string)($_SESSION['old_email'] ?? '');
    unset($_SESSION['old_email']);
    page_head('Entrar', 'landing'); ?>
  <section class="hero card narrow">
    <img src="/assets/icons/logo.svg" alt="" width="88" height="88" class="hero-logo">
    <h1>Seu humor, um dia de cada vez</h1>
    <p class="lead">Registre como você se sente em cada período do dia, acompanhe padrões com um gráfico de linha do tempo e, se quiser, compartilhe relatórios com quem cuida de você.</p>
    <?= flash_html() ?>
    <form class="auth-form" method="post" action="/conta/entrar.php" novalidate>
      <h2>Entrar</h2>
      <?= csrf_field() ?>
      <div class="field"><label for="login-email">E-mail</label><input type="email" id="login-email" name="email" required autocomplete="email" inputmode="email" maxlength="190" value="<?= e($oldEmail) ?>"></div>
      <?= password_field('login-senha', 'senha', 'Senha', 'current-password') ?>
      <button class="btn btn-primary btn-lg" type="submit">Entrar</button>
      <p class="auth-links"><a href="/conta/esqueci.php">Esqueci minha senha</a> · <a href="/conta/cadastro.php">Criar conta</a></p>
    </form>
    <?php if ($providers || cfg('dev_login')): ?>
    <p class="divider"><span>ou</span></p>
    <div class="login-buttons" role="group" aria-label="Entrar com outra conta">
      <?php foreach ($providers as $k => $p): ?>
        <a class="btn btn-login btn-<?= e($k) ?>" href="/auth/start.php?provider=<?= e($k) ?>">Entrar com <?= e($p['label']) ?></a>
      <?php endforeach; ?>
      <?php if (cfg('dev_login')): ?><a class="btn btn-ghost" href="/auth/dev.php">Entrar (modo de teste local)</a><?php endif; ?>
    </div>
    <?php endif; ?>
    <p class="passkey-login"><button type="button" class="btn btn-ghost hidden" id="btn-passkey-login">🔐 Entrar com biometria (passkey)</button></p>
    <p id="passkey-msg" class="field-err" role="alert"></p>
    <p class="small">Ao entrar, você concorda com a <a href="/privacidade.php">Política de Privacidade</a> e os <a href="/termos.php">Termos de Uso</a>. Seus dados são seus: exporte ou exclua a qualquer momento.</p>
  </section>
  <script src="/assets/js/auth.js?v=1"></script>
  <script src="/assets/js/passkey.js?v=4"></script>
<?php page_foot(); exit; }

if (app_locked($user)) { require_once APP_ROOT . '/views/lock.php'; view_lock_screen($user); exit; }
require_once APP_ROOT . '/lib/ai.php';
require_once APP_ROOT . '/lib/signals.php';
$aiOn = ai_configured();
$aiReady = ai_ready($user);
$aiSet = ai_settings($user);
$lockSet = lock_settings($user);

$tz = $user['timezone'];
page_head('Diário', 'app'); ?>
<script id="boot" type="application/json"><?= json_encode([
    'csrf' => csrf_token(),
    'tz' => $tz,
    'name' => $user['name'],
    'email' => $user['email'],
    'vapid' => push_configured() ? cfg('vapid.public_key', '') : '',
    'levels' => array_map(function ($k, $v) { return ['v' => $k, 'label' => $v[0], 'desc' => $v[1], 'icon' => $v[2], 'color' => MOOD_COLORS[$k]]; }, array_keys(MOOD_LEVELS), MOOD_LEVELS),
    'periods' => PERIODS,
    'roles' => ROLES,
    'media' => cfg('media'),
    'lock' => $lockSet + ['has_factor' => lock_has_factor($user), 'passkeys' => webauthn_count($user)],
    'ai' => ['configured' => $aiReady, 'consent' => $aiSet['consent']],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<h1 class="sr-only">Diário de humor</h1>
<?= flash_html() /* V4.4: avisos do login (ex.: códigos de recuperação restantes) */ ?>
<nav class="tabs" aria-label="Seções">
  <div role="tablist" id="tablist">
    <button role="tab" id="tab-registrar" aria-controls="painel-registrar" aria-selected="true">✎ <span>Registrar</span></button>
    <button role="tab" id="tab-historico" aria-controls="painel-historico" aria-selected="false" tabindex="-1">◔ <span>Histórico</span></button>
    <button role="tab" id="tab-relatorios" aria-controls="painel-relatorios" aria-selected="false" tabindex="-1">✉ <span>Relatórios</span></button>
    <button role="tab" id="tab-lembretes" aria-controls="painel-lembretes" aria-selected="false" tabindex="-1">⏰ <span>Lembretes</span></button>
    <button role="tab" id="tab-conta" aria-controls="painel-conta" aria-selected="false" tabindex="-1">⚙ <span>Conta</span></button>
  </div>
</nav>

<div id="offline-banner" class="notice hidden" role="status">Você está offline. Os registros ficam salvos neste aparelho e serão enviados quando a conexão voltar.</div>
<div id="extreme-note" class="notice gentle hidden" role="status"></div>

<!-- REGISTRAR -->
<section role="tabpanel" id="painel-registrar" aria-labelledby="tab-registrar" class="panel">
  <div id="alert-banner" class="notice alert-banner hidden" role="status"></div>
  <h2 class="greet">Olá<?= $user['name'] ? ', ' . e(explode(' ', $user['name'])[0]) : '' ?>. Como você está?</h2>
  <form id="form-entry" class="card" novalidate>
    <div id="edit-note" class="notice gentle hidden" role="status"></div>
    <div class="row">
      <div class="field">
        <label for="entry-date-br">Data <span class="hint">(dd/mm/aaaa)</span></label>
        <?= date_field_br('entry-date', 'entry_date') ?>
      </div>
      <fieldset class="field periods">
        <legend>Período do dia</legend>
        <div class="seg">
          <?php foreach (PERIODS as $k => $l): ?>
          <input type="radio" name="period" id="p-<?= $k ?>" value="<?= $k ?>" required><label for="p-<?= $k ?>"><?= e($l) ?></label>
          <?php endforeach; ?>
        </div>
        <p class="small" id="sleep-tip">Dormiu mal ou acordou de madrugada? Registre no dia seguinte, com calma — dormir em horários regulares também é cuidado.</p>
      </fieldset>
    </div>
    <div id="slot-view" class="notice slot-view hidden" role="status"></div>

    <div id="entry-body">
    <fieldset class="mood-scale">
      <legend>Humor <span class="hint">(da depressão à euforia)</span></legend>
      <div class="mood-options">
        <?php foreach (array_reverse(MOOD_LEVELS, true) as $v => $m): ?>
        <input type="radio" name="mood" id="m<?= $v + 3 ?>" value="<?= $v ?>" required>
        <label for="m<?= $v + 3 ?>" class="mood-opt lvl<?= $v + 3 ?>">
          <span class="mood-icon" aria-hidden="true"><?= $m[2] ?></span>
          <span class="mood-num" aria-hidden="true"><?= $v > 0 ? '+' . $v : $v ?></span>
          <span class="mood-text"><strong><?= e($m[0]) ?></strong><small><?= e($m[1]) ?></small></span>
        </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <details class="extras">
      <summary>Mais detalhes (opcional)</summary>
      <div class="grid2">
        <div class="field"><label for="sleep">Horas de sono (últimas 24h)</label><input type="number" id="sleep" name="sleep_hours" min="0" max="24" step="0.5" inputmode="decimal"></div>
        <fieldset class="field"><legend>Tomou a medicação?</legend>
          <div class="seg"><input type="radio" name="medication_taken" id="med-s" value="1"><label for="med-s">Sim</label><input type="radio" name="medication_taken" id="med-n" value="0"><label for="med-n">Não</label><input type="radio" name="medication_taken" id="med-na" value="" checked><label for="med-na">Não informar</label></div>
        </fieldset>
        <?php foreach (['irritability' => 'Irritabilidade', 'anxiety' => 'Ansiedade', 'energy' => 'Energia'] as $k => $l): ?>
        <div class="field range-field">
          <label for="<?= $k ?>"><?= $l ?>: <output id="<?= $k ?>-out" for="<?= $k ?>">não informado</output></label>
          <input type="range" id="<?= $k ?>" name="<?= $k ?>" min="-1" max="3" step="1" value="-1" aria-describedby="range-help">
        </div>
        <?php endforeach; ?>
        <p id="range-help" class="small">Escala: não informado, 0 nenhuma, 1 leve, 2 moderada, 3 intensa.</p>
      </div>
      <div class="field"><label for="tags">Gatilhos / etiquetas <span class="hint">(separe por vírgula)</span></label>
        <input type="text" id="tags" name="tags" maxlength="500" list="tag-suggestions" placeholder="ex.: trabalho, discussão, pouco sono">
        <datalist id="tag-suggestions"><option value="pouco sono"><option value="trabalho"><option value="família"><option value="álcool"><option value="exercício"><option value="ciclo menstrual"><option value="mudança de medicação"></datalist>
      </div>
      <div class="field"><label for="note">Anotação</label><textarea id="note" name="note" rows="3" maxlength="5000"></textarea></div>
    </details>

    <details class="extras" id="media-box">
      <summary>Registro detalhado: áudio, vídeo ou arquivo (opcional)</summary>
      <p class="small">Fica guardado de forma privada, visível somente para você. Nunca é enviado em relatórios.</p>
      <div class="media-actions">
        <button type="button" class="btn" id="rec-audio" aria-pressed="false">🎙 Gravar áudio</button>
        <button type="button" class="btn" id="rec-video" aria-pressed="false">🎥 Gravar vídeo</button>
        <label class="btn" for="media-file">📎 Enviar arquivo</label>
        <input type="file" id="media-file" class="sr-only" accept="audio/*,video/*,image/jpeg,image/png">
      </div>
      <p id="rec-status" class="small" role="status"></p>
      <video id="video-preview" class="hidden" muted playsinline aria-label="Pré-visualização da câmera"></video>
      <ul id="pending-media" class="media-list"></ul>
    </details>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-lg" id="btn-save-entry">Salvar registro</button>
      <button type="button" class="btn btn-ghost hidden" id="btn-new-entry">Cancelar edição</button>
    </div>
    </div>
  </form>

  <?php require_once APP_ROOT . '/views/day_panels.php'; view_day_panels($user); ?>

  <div id="push-onboard" class="card onboard hidden" role="region" aria-labelledby="po-title">
    <h2 id="po-title">Quer um lembrete no celular?</h2>
    <p>Uma notificação discreta como “Como está seu humor nesta tarde?” — com um toque você registra. Nada sobre sua saúde aparece na tela bloqueada.</p>
    <p class="row"><button type="button" class="btn btn-primary" id="po-yes">Ativar lembretes</button> <button type="button" class="btn btn-ghost" id="po-no">Agora não</button></p>
  </div>

  <section class="card">
    <h2>Hoje</h2>
    <ul id="today-list" class="today-list" aria-busy="true">
      <?php foreach (PERIODS as $k => $l): ?><li class="skeleton"><span class="per"><?= e($l) ?></span> <span class="sk-bar" aria-hidden="true"></span><span class="sr-only">carregando…</span></li><?php endforeach; ?>
    </ul>
  </section>

  <?php if ($aiReady && $aiSet['consent']): ?>
  <section class="card ia" id="ia-card" aria-labelledby="ia-title">
    <h2 id="ia-title">💭 Reflexões com IA <span class="hint">(opcional)</span></h2>
    <p class="small">Um companheiro de reflexão. Não é médico: não faz diagnóstico nem fala de doses. Envia só as categorias que você autorizou em Conta.</p>
    <div class="row wrap">
      <button type="button" class="btn" data-ai="checkin">Check-in por conversa</button>
      <button type="button" class="btn" data-ai="resumo">Resumo da semana</button>
      <button type="button" class="btn" data-ai="consulta">Preparar consulta</button>
    </div>
    <div id="ia-chat" class="chat hidden" aria-live="polite"></div>
    <form id="ia-form" class="chat-form hidden" novalidate>
      <label for="ia-input" class="sr-only">Sua mensagem</label>
      <textarea id="ia-input" rows="2" maxlength="1500" placeholder="Escreva como você está…"></textarea>
      <div class="row"><button type="submit" class="btn btn-primary">Enviar</button> <button type="button" class="btn btn-ghost hidden" id="ia-voice" aria-pressed="false">🎙 Falar</button></div>
      <p class="small" id="ia-voice-note">A fala é transcrita pelo próprio navegador (no Chrome, o áudio pode passar pelos servidores do Google). Prefira digitar se não quiser isso.</p>
    </form>
    <div id="ia-proposal" class="notice gentle hidden" role="region" aria-label="Registro proposto"></div>
  </section>
  <?php endif; ?>
</section>

<!-- HISTÓRICO -->
<section role="tabpanel" id="painel-historico" aria-labelledby="tab-historico" class="panel" hidden>
  <div class="card" id="alertas">
    <h2>Alertas automáticos</h2>
    <p class="small">O app compara seus registros recentes com o <strong>seu</strong> padrão das últimas 4 semanas (sono, humor, energia, marcadores, medicação e, se ligados, módulos privados). Não é diagnóstico. Diga se cada alerta faz sentido: isso ajusta a sensibilidade para você. <a href="/escala.php#alertas">Como funciona</a></p>
    <ul id="alerts-list" class="alerts-list" aria-busy="true"><li class="muted">Carregando…</li></ul>
  </div>
  <div class="card" id="alertas-personalizados">
    <div class="row between"><h2>Meus alertas personalizados</h2><button type="button" class="btn btn-primary btn-sm" id="ca-new">＋ Novo alerta</button></div>
    <p class="small">Monte seus próprios avisos combinando o que você registra — por exemplo: humor +2 ou mais <strong>E</strong> desejo sexual acima do habitual. Os avisos aparecem aqui e no topo do app; notificação e e-mail dizem só “um alerta que você criou foi atendido”, sem detalhes. Alertas que usam Sexualidade ou Substâncias ficam protegidos pelo mesmo PIN/biometria.</p>
    <ul id="ca-list" class="list" aria-busy="true"><li class="muted">Carregando…</li></ul>
    <form id="ca-form" class="ca-form hidden" novalidate aria-labelledby="ca-form-title">
      <h3 id="ca-form-title">Novo alerta</h3>
      <div class="field"><label for="ca-tpl">Começar de um modelo <span class="hint">(opcional)</span></label><select id="ca-tpl"><option value="">Montar do zero</option></select></div>
      <div class="field"><label for="ca-name">Nome do alerta</label><input id="ca-name" maxlength="100" required placeholder="ex.: Acelerando"></div>
      <fieldset class="field"><legend>Quando avisar</legend>
        <div class="seg"><input type="radio" name="ca_logic" id="ca-and" value="and" checked><label for="ca-and">Todas as condições (E)</label><input type="radio" name="ca_logic" id="ca-or" value="or"><label for="ca-or">Qualquer condição (OU)</label></div>
      </fieldset>
      <ol id="ca-conds" class="ca-conds"></ol>
      <p><button type="button" class="btn btn-sm" id="ca-add-cond">＋ Condição</button></p>
      <div class="grid2">
        <div class="field"><label for="ca-sev">Nível</label><select id="ca-sev"><option value="1">Atenção</option><option value="2">Alerta</option></select></div>
        <div class="field"><label for="ca-cool">Depois de avisar, esperar (dias)</label><input id="ca-cool" type="number" min="1" max="14" value="3" inputmode="numeric"></div>
      </div>
      <fieldset class="field checks"><legend>Como avisar</legend>
        <label class="check"><input type="checkbox" checked disabled> <span>No app (sempre)</span></label>
        <label class="check"><input type="checkbox" id="ca-push"> <span>Notificação (push), com texto genérico</span></label>
        <label class="check"><input type="checkbox" id="ca-email"> <span>E-mail, com texto genérico</span></label>
      </fieldset>
      <div id="ca-preview" class="notice gentle hidden" role="status" aria-live="polite"></div>
      <p class="row dlg-actions"><button type="button" class="btn" id="ca-cancel">Cancelar</button> <button type="button" class="btn" id="ca-try">Ver prévia nos últimos 90 dias</button> <button type="submit" class="btn btn-primary" id="ca-save">Salvar alerta</button></p>
    </form>
  </div>
  <div class="card">
    <div class="row between">
      <h2>Linha do tempo (Life Chart)</h2>
      <label class="inline">Período <select id="range-days"><option value="14">14 dias</option><option value="30" selected>30 dias</option><option value="90">90 dias</option><option value="365">1 ano</option></select></label>
    </div>
    <div id="lifechart" class="chart" role="img" aria-label="Gráfico de humor" tabindex="0"></div>
    <ul class="legend" id="legend"></ul>
    <details><summary>Ver dados em tabela (acessível)</summary><div id="history-table" class="table-wrap" role="region" aria-label="Tabela de registros" tabindex="0"></div></details>
  </div>
  <div class="card">
    <h2>Indicadores de (hipo)mania</h2>
    <p class="small">Soma de sinais por dia: sono abaixo do seu habitual, humor e energia elevados, pensamentos/fala acelerados, gastos, irritabilidade, seus sinais pessoais e — se você ativou — sexualidade. Não é diagnóstico; é um apoio para conversar com sua equipe.</p>
    <div id="indicators" class="table-wrap" role="region" aria-label="Indicadores de (hipo)mania por dia" tabindex="0"></div>
  </div>
  <?php if (user_prefs($user)['modules']['substancias']): ?>
  <div class="card" id="subst-hist-card">
    <h2>🔒 Substâncias e humor <span class="hint">(privado)</span></h2>
    <p class="small">Compara o humor médio nos dias com uso, nos dias sem uso e no dia seguinte. É uma correlação simples, não prova causa — use como ponto de conversa com seu psiquiatra.</p>
    <div id="subst-hist" class="table-wrap" role="region" aria-label="Substâncias e humor" tabindex="0"></div>
  </div>
  <?php endif; ?>
  <div class="card">
    <h2>Calendário (mapa de calor)</h2>
    <p class="small">Cada quadrado mostra a média do dia; ▲ indica elevação, ▼ depressão.</p>
    <div id="heatmap" class="heatmap"></div>
  </div>
  <div class="card">
    <h2>Registros detalhados</h2>
    <ul id="media-history" class="media-list"></ul>
  </div>
</section>

<!-- RELATÓRIOS -->
<section role="tabpanel" id="painel-relatorios" aria-labelledby="tab-relatorios" class="panel" hidden>
  <div class="card">
    <h2>Relatório para imprimir ou salvar em PDF</h2>
    <form id="form-print" class="row" action="/relatorio.php" method="get" target="_blank">
      <div class="field"><label for="r-from-br">De</label><?= date_field_br('r-from', 'de') ?></div>
      <div class="field"><label for="r-to-br">Até</label><?= date_field_br('r-to', 'ate') ?></div>
      <label class="check"><input type="checkbox" name="notas" value="1"> <span>Incluir anotações</span></label>
      <?php if (user_prefs($user)['modules']['sexualidade']): ?><label class="check"><input type="checkbox" name="sexualidade" value="1"> <span>Incluir resumo de sexualidade</span></label><?php endif; ?>
      <?php if (user_prefs($user)['modules']['substancias']): ?><label class="check"><input type="checkbox" name="substancias" value="1"> <span>Incluir resumo de substâncias</span></label><?php endif; ?>
      <button class="btn btn-primary" type="submit">Abrir relatório</button>
    </form>
  </div>
  <div class="card">
    <h2>Destinatários de relatórios por e-mail</h2>
    <p class="small">O destinatário precisa confirmar por e-mail antes de receber qualquer relatório. Áudios e vídeos nunca são enviados.</p>
    <ul id="recipients" class="list"></ul>
    <form id="form-recipient" class="grid2" novalidate>
      <div class="field"><label for="rc-name">Nome</label><input id="rc-name" name="name" required maxlength="150"></div>
      <div class="field"><label for="rc-email">E-mail</label><input id="rc-email" name="email" type="email" required maxlength="190" autocomplete="off"></div>
      <div class="field"><label for="rc-role">Relação</label><select id="rc-role" name="role"><?php foreach (ROLES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="rc-freq">Frequência</label><select id="rc-freq" name="frequency"><option value="diaria">Diária</option><option value="semanal" selected>Semanal</option><option value="mensal">Mensal</option><option value="personalizada">Personalizada</option></select></div>
      <div class="field" id="rc-weekday-f"><label for="rc-weekday">Dia da semana</label><select id="rc-weekday" name="weekday"><option value="1">Segunda-feira</option><option value="2">Terça-feira</option><option value="3">Quarta-feira</option><option value="4">Quinta-feira</option><option value="5">Sexta-feira</option><option value="6">Sábado</option><option value="0">Domingo</option></select></div>
      <div class="field hidden" id="rc-interval-f"><label for="rc-interval">A cada quantos dias?</label><input id="rc-interval" name="interval_days" type="number" min="1" max="365" value="15"></div>
      <div class="checks span-all">
        <label class="check"><input type="checkbox" name="include_notes" value="1"> <span>Incluir minhas anotações e etiquetas</span></label>
        <label class="check"><input type="checkbox" name="include_media_list" value="1"> <span>Mencionar que existem registros de áudio/vídeo (sem enviar o conteúdo)</span></label>
        <label class="check"><input type="checkbox" name="include_markers" value="1" checked> <span>Incluir marcadores do dia e indicador de (hipo)mania</span></label>
        <label class="check"><input type="checkbox" name="include_sexuality" value="1" aria-describedby="rc-sex-warn"> <span>Incluir resumo de sexualidade (privado)</span></label>
        <label class="check"><input type="checkbox" name="include_substances" value="1" aria-describedby="rc-sub-warn"> <span>Incluir resumo de substâncias (privado)</span></label>
        <label class="check"><input type="checkbox" name="include_alerts" value="1" aria-describedby="rc-alert-warn"> <span>Receber alertas de apoio</span></label>
        <label class="check"><input type="checkbox" name="include_custom_alerts" value="1" aria-describedby="rc-custom-warn"> <span>Incluir no relatório quantos alertas personalizados foram atendidos</span></label>
        <p class="small" id="rc-custom-warn">Vai só o número (ex.: “2 alertas personalizados foram atendidos”), nunca quais são nem os motivos. Alertas que usam Sexualidade ou Substâncias só entram na conta se você também marcar esses resumos.</p>
        <p class="small" id="rc-alert-warn">Quando um alerta forte aparecer, essa pessoa recebe só “<?= e(explode(' ', trim((string)$user['name']))[0] ?: 'Você') ?> pode precisar de apoio” e os contatos de crise (CVV 188, SAMU 192) — nunca o motivo ou seus dados. No máximo um aviso por semana.</p>
        <p class="small" id="rc-sub-warn">Vai só a contagem de dias com uso por tipo e de dias acima do habitual — nunca quantidades, motivos ou horários.</p>
        <p class="small" id="rc-sex-warn">Só marque se confiar nessa pessoa para esse tema. Vai apenas um resumo (média do desejo e dias com sinais de risco), nunca atividades ou anotações.</p>
      </div>
      <div class="form-actions"><button class="btn btn-primary" type="submit">Adicionar e enviar convite</button></div>
    </form>
  </div>
</section>

<!-- LEMBRETES -->
<section role="tabpanel" id="painel-lembretes" aria-labelledby="tab-lembretes" class="panel" hidden>
  <div class="card">
    <h2>Lembretes</h2>
    <p class="small">Fuso horário: <strong id="tz-label"></strong> (altere em Conta).</p>
    <div id="push-status" class="notice hidden" role="status"></div>
    <?php if (push_configured()): ?><button type="button" class="btn" id="btn-push">Ativar notificações neste aparelho</button><?php else: ?><p class="small" id="push-off">Os lembretes são enviados por e-mail para <strong><?= e($user['email'] ?: 'o endereço cadastrado em Conta') ?></strong>. Notificações no celular (push) ainda não estão disponíveis.</p><?php endif; ?>
    <div id="push-devices-box" class="<?= push_configured() ? '' : 'hidden' ?>">
      <h3>Aparelhos com notificação</h3>
      <ul id="push-devices" class="list"></ul>
      <p class="row"><button type="button" class="btn btn-sm" id="btn-push-test">Enviar notificação de teste</button></p>
      <details class="small"><summary>iPhone, Android e computador</summary>
        <ul>
          <li><strong>iPhone/iPad (iOS 16.4 ou mais novo):</strong> abra no Safari, toque em Compartilhar → “Adicionar à Tela de Início”, abra pelo ícone e só então ative. No Safari comum (sem instalar) o iOS não entrega push.</li>
          <li><strong>Android:</strong> funciona no Chrome, Edge, Firefox e Samsung Internet, instalado ou não. Os botões “Registrar agora” e “Lembrar em 30 min” aparecem ao expandir a notificação.</li>
          <li><strong>Computador:</strong> Chrome, Edge e Firefox mostram a notificação com o navegador aberto (ou em segundo plano). No macOS, permita notificações do navegador em Ajustes do Sistema → Notificações. Safari no Mac (16+) também funciona.</li>
        </ul>
      </details>
    </div>
    <ul id="reminders" class="list"></ul>
    <form id="form-reminder" class="grid2" novalidate>
      <fieldset class="field"><legend>Horário</legend>
        <div class="seg"><input type="radio" name="preset" id="pr-m" value="08:00"><label for="pr-m">Manhã 08:00</label><input type="radio" name="preset" id="pr-t" value="14:00"><label for="pr-t">Tarde 14:00</label><input type="radio" name="preset" id="pr-n" value="21:00" checked><label for="pr-n">Noite 21:00</label><input type="radio" name="preset" id="pr-c" value="custom"><label for="pr-c">Outro</label></div>
        <label for="rm-time" class="sr-only">Horário personalizado</label><input type="text" inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" placeholder="hh:mm" autocomplete="off" id="rm-time" name="time_local" value="21:00" class="time24 hidden">
      </fieldset>
      <fieldset class="field"><legend>Dias da semana</legend>
        <div class="days">
          <?php foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $i => $d): ?>
          <input type="checkbox" name="days" id="d<?= $i ?>" value="<?= $i ?>" checked><label for="d<?= $i ?>"><?= $d ?></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <div class="field"><label for="rm-channel">Como avisar</label><select id="rm-channel" name="channel"><option value="email" selected>E-mail</option><?php if (push_configured()): ?><option value="push">Notificação (push)</option><option value="ambos">Ambos</option><?php endif; ?></select></div>
      <div class="form-actions"><button class="btn btn-primary" type="submit">Adicionar lembrete</button></div>
    </form>
  </div>
</section>

<!-- CONTA -->
<section role="tabpanel" id="painel-conta" aria-labelledby="tab-conta" class="panel" hidden>
  <div class="card">
    <h2>Preferências</h2>
    <form id="form-settings" class="grid2">
      <div class="field"><label for="st-name">Nome</label><input id="st-name" name="name" maxlength="150" value="<?= e($user['name']) ?>"></div>
      <div class="field"><label for="st-email">E-mail (para lembretes)</label><input id="st-email" name="email" type="email" maxlength="190" value="<?= e($user['email']) ?>"></div>
      <div class="field"><label for="st-tz">Fuso horário</label><select id="st-tz" name="timezone"><?php $tzo = timezone_options(); ?><optgroup label="Brasil"><?php foreach ($tzo['brasil'] as $z => $l): ?><option value="<?= e($z) ?>"<?= $z === $tz ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></optgroup><optgroup label="Outros países"><?php foreach ($tzo['outros'] as $z): ?><option value="<?= e($z) ?>"<?= $z === $tz ? ' selected' : '' ?>><?= e(tz_label_br($z)) ?></option><?php endforeach; ?></optgroup></select></div>
      <label class="check span-all"><input type="checkbox" name="extreme_alerts" value="1"<?= $user['extreme_alerts'] ? ' checked' : '' ?>> <span>Mostrar aviso gentil quando eu registrar níveis extremos por vários períodos</span></label>
      <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar</button></div>
    </form>
  </div>
  <?php require_once APP_ROOT . '/views/account_modules.php'; view_account_modules($user); ?>
  <?php require_once APP_ROOT . '/views/account_v4.php'; view_account_v4($user, $aiOn, $aiSet, $lockSet); ?>
  <div class="card">
    <h2>Senha</h2>
    <?php if (!empty($user['password_hash'])): ?>
    <form id="form-password" class="grid2" novalidate>
      <?= password_field('pw-atual', 'atual', 'Senha atual', 'current-password') ?>
      <?= password_field('pw-nova', 'nova', 'Nova senha', 'new-password', true) ?>
      <div class="form-actions"><button class="btn btn-primary" type="submit">Alterar senha</button></div>
    </form>
    <p class="small">Ao alterar a senha, você sai automaticamente dos outros aparelhos.</p>
    <?php else: ?>
    <p>Você entra com uma conta externa (Google, Microsoft ou X), por isso não há senha para alterar aqui.</p>
    <?php endif; ?>
  </div>
  <?php require_once APP_ROOT . '/views/account_mfa.php'; view_account_mfa($user); ?>
  <div class="card">
    <h2>Seus dados (LGPD)</h2>
    <p>Baixe uma cópia dos seus dados quando quiser. O pacote completo (ZIP) traz tudo: registros, marcadores, sexualidade, substâncias, alertas, destinatários, lembretes e todos os áudios, vídeos e imagens.</p>
    <p class="row"><?php if (class_exists('ZipArchive')): ?><a class="btn btn-primary" href="/api/account.php?action=export&amp;format=zip" download>Baixar tudo (ZIP)</a> <?php endif; ?><a class="btn" href="/api/account.php?action=export&amp;format=json" download>Exportar JSON</a> <a class="btn" href="/api/account.php?action=export&amp;format=csv" download>Exportar CSV (registros)</a></p>
    <p class="small">Saiba como tratamos seus dados na <a href="/privacidade.php">Política de Privacidade</a> e nos <a href="/termos.php">Termos de Uso</a>.</p>
    <form method="post" action="/auth/logout.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="btn" type="submit">Sair</button></form>
  </div>
  <div class="card danger-zone">
    <h2>Excluir conta</h2>
    <p>Apaga na hora, de forma definitiva, a sua conta e tudo o que está ligado a ela: registros, marcadores, sexualidade, substâncias, mídias, alertas, alertas personalizados, destinatários, lembretes, biometria, notificações e uso da IA. Não é possível desfazer. Se quiser, baixe tudo (ZIP) antes.</p>
    <form id="form-delete"><label for="del-confirm">Digite <strong>EXCLUIR</strong> para confirmar</label><input id="del-confirm" autocomplete="off"><button class="btn btn-danger" type="submit">Excluir minha conta</button></form>
  </div>
</section>
<script src="/assets/js/auth.js?v=1"></script>
<script src="/assets/js/passkey.js?v=4"></script>
<script src="/assets/js/app.js?v=10"></script>
<script src="/assets/js/mfa.js?v=1"></script>
<?php page_foot();
