<?php
/** @param array<string,mixed> $user */
function view_account_modules(array $user): void {
/** Conta → Módulos, sinais pessoais, plano de ação, medicação e Sexualidade. Espera $user. */
$pf = user_prefs($user);
?>
<div class="card" id="conta-modulos">
  <h2>Módulos do registro diário</h2>
  <p class="small">Escolha o que aparece em “Meu dia”. O registro de humor por período continua com um toque.</p>
  <form id="form-prefs" novalidate>
    <fieldset class="checks"><legend class="sr-only">Módulos</legend>
      <?php foreach (DAY_MODULES as $k => [$l]): if (in_array($k, PRIVATE_MODULES, true) || !tenant_module_available($k)) continue; ?>
      <label class="check"><input type="checkbox" name="mod_<?= $k ?>" value="1"<?= $pf['modules'][$k] ? ' checked' : '' ?>> <span><?= e($l) ?></span></label>
      <?php endforeach; ?>
    </fieldset>

    <details class="extras"<?= $pf['signs'] ? ' open' : '' ?>>
      <summary>🚩 Meus sinais de alerta pessoais (até 10)</summary>
      <p class="small">Combine com seu psiquiatra ou terapeuta: o que costuma mudar em você antes de uma crise? Ex.: “arrumar a casa de madrugada”, “começar muitos projetos”, “dormir menos e não sentir cansaço”.</p>
      <div id="signs-list" class="stack"><?php for ($i = 0; $i < 10; $i++): ?>
        <div class="field<?= $i >= max(3, count($pf['signs']) + 1) ? ' hidden' : '' ?>"><label for="sg-<?= $i ?>">Sinal <?= $i + 1 ?></label><input id="sg-<?= $i ?>" name="sign_<?= $i ?>" maxlength="120" value="<?= e($pf['signs'][$i] ?? '') ?>"></div>
      <?php endfor; ?></div>
      <button type="button" class="btn btn-sm" id="sign-more">+ Mais um sinal</button>
      <div class="field"><label for="pf-plan">Meu plano quando os sinais aparecerem <span class="hint">(aparece junto do alerta)</span></label><textarea id="pf-plan" name="action_plan" rows="3" maxlength="1000" placeholder="ex.: dormir no horário, evitar compras grandes, ligar para a Dra. Ana (65) 9xxxx-xxxx"><?= e($pf['action_plan']) ?></textarea></div>
    </details>

    <details class="extras"<?= $pf['meds'] ? ' open' : '' ?>>
      <summary>💊 Meus horários de medicação</summary>
      <div id="meds-list" class="stack"><?php for ($i = 0; $i < 8; $i++): $md = $pf['meds'][$i] ?? ['id' => '', 'name' => '', 'dose' => '', 'time' => '']; ?>
        <div class="grid3 med-row<?= $i >= max(2, count($pf['meds']) + 1) ? ' hidden' : '' ?>" data-id="<?= e($md['id']) ?>">
          <div class="field"><label for="md-n<?= $i ?>">Remédio</label><input id="md-n<?= $i ?>" class="md-name" maxlength="80" value="<?= e($md['name']) ?>"></div>
          <div class="field"><label for="md-d<?= $i ?>">Dose</label><input id="md-d<?= $i ?>" class="md-dose" maxlength="40" value="<?= e($md['dose']) ?>"></div>
          <div class="field"><label for="md-t<?= $i ?>">Horário</label><input id="md-t<?= $i ?>" class="md-time time24" type="text" inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" placeholder="hh:mm" autocomplete="off" aria-describedby="md-hint" value="<?= e($md['time']) ?>"></div>
        </div>
      <?php endfor; ?></div>
      <p class="small" id="md-hint">Horário no formato 24 horas (ex.: 08:00, 22:30).</p>
      <button type="button" class="btn btn-sm" id="med-more">+ Mais um horário</button>
    </details>

    <?php if (tenant_module_available('sexualidade')): ?>
    <details class="extras" id="sex-settings">
      <summary>🔒 Sexualidade (privado)</summary>
      <p class="small">Na hipomania e na mania é comum o desejo sexual aumentar, surgirem novas parcerias, sexo sem proteção ou muito tempo em apps e conteúdo online — muitas vezes seguido de arrependimento. Esses sinais costumam aparecer cedo e quase nunca são perguntados nas consultas. O módulo é para todas as orientações, identidades e formatos de relação, e não julga: o que importa é a <strong>mudança em relação ao seu normal</strong>.</p>
      <ul class="small">
        <li>Desligado por padrão; só aparece se você ligar.</li>
        <li>Nunca aparece em lembretes por push ou e-mail.</li>
        <li>Só entra em relatório para um destinatário se você marcar isso para ele, e mesmo assim só como resumo (sem atividades nem anotações).</li>
        <li>Você pode borrar a tela e proteger com PIN.</li>
      </ul>
      <label class="check"><input type="checkbox" name="mod_sexualidade" value="1"<?= $pf['modules']['sexualidade'] ? ' checked' : '' ?>> <span>Ativar o módulo Sexualidade</span></label>
      <label class="check"><input type="checkbox" name="sex_blur" value="1"<?= $pf['sex_blur'] ? ' checked' : '' ?>> <span>Borrar a seção até eu tocar para mostrar</span></label>
      <p class="small"><a href="/privacidade.php#sexualidade" target="_blank" rel="noopener">Por que isso ajuda? (fontes científicas)</a></p>
    </details>
    <?php endif; if (tenant_module_available('substancias')): ?>
    <details class="extras" id="subst-settings">
      <summary>🔒 Substâncias (privado, redução de danos)</summary>
      <p class="small">Para registrar álcool, cannabis, tabaco e outras substâncias sem julgamento: quantidade, período, motivo, efeito no humor e se foi mais que o habitual. Ajuda a ver padrões (por exemplo, sono e humor no dia seguinte) e a conversar abertamente com seu psiquiatra sobre interações com a medicação.</p>
      <ul class="small">
        <li>Desligado por padrão; nunca aparece em lembretes por push ou e-mail.</li>
        <li>Só entra em relatório para um destinatário que você autorizar, e só como resumo (dias com uso por tipo e dias acima do habitual).</li>
        <li>Usa o mesmo PIN da seção Sexualidade e pode ficar borrado na tela.</li>
      </ul>
      <label class="check"><input type="checkbox" name="mod_substancias" value="1"<?= $pf['modules']['substancias'] ? ' checked' : '' ?>> <span>Ativar o módulo Substâncias</span></label>
      <label class="check"><input type="checkbox" name="subst_blur" value="1"<?= $pf['subst_blur'] ? ' checked' : '' ?>> <span>Borrar a seção até eu tocar para mostrar</span></label>
    </details>
    <?php endif; if (tenant_module_available('pensamentos_morte')): $tc = $pf['trusted_contact']; ?>
    <details class="extras" id="death-settings">
      <summary>🔒 Pensamentos de morte ou suicídio (privado)</summary>
      <p class="small">Pensamentos de morte podem aparecer nas fases depressivas e mistas. Registrar a intensidade por período, com honestidade, ajuda você e sua equipe a perceber cedo e a pedir ajuda no momento certo. Ter esses pensamentos não é culpa sua, e eles costumam passar.</p>
      <ul class="small">
        <li>Desligado por padrão; fica atrás do mesmo PIN de Sexualidade e Substâncias e pode ficar borrado na tela.</li>
        <li>Ao salvar um nível leve, moderado ou intenso, aparece na hora um painel de apoio (CVV 188, SAMU 192 e o seu contato de confiança). <strong>Ninguém é avisado automaticamente.</strong> Só existe aviso se você mesmo(a) criar um alerta personalizado com esse item.</li>
        <li>Nunca vai para a IA, a não ser que você ligue a categoria própria em Conta → IA. Só entra em relatório se você marcar para um destinatário ou na impressão.</li>
      </ul>
      <label class="check"><input type="checkbox" name="mod_pensamentos_morte" value="1"<?= $pf['modules']['pensamentos_morte'] ? ' checked' : '' ?>> <span>Ativar “Pensamentos de morte ou suicídio”</span></label>
      <label class="check"><input type="checkbox" name="death_blur" value="1"<?= $pf['death_blur'] ? ' checked' : '' ?>> <span>Borrar a seção até eu tocar para mostrar</span></label>
      <fieldset class="grid2"><legend>Contato de confiança <span class="hint">(opcional; aparece no painel de apoio como botão de ligar)</span></legend>
        <div class="field"><label for="tc-name">Nome</label><input id="tc-name" name="tc_name" maxlength="80" autocomplete="off" value="<?= e($tc['name']) ?>"></div>
        <div class="field"><label for="tc-phone">Telefone com DDD</label><input id="tc-phone" name="tc_phone" type="tel" inputmode="tel" maxlength="20" autocomplete="off" placeholder="(65) 99999-9999" value="<?= e($tc['phone']) ?>"></div>
      </fieldset>
    </details>
    <?php endif; ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar módulos</button></div>
  </form>

  <form id="form-sexpin" class="grid2<?= $pf['modules']['sexualidade'] || $pf['modules']['substancias'] || $pf['modules']['pensamentos_morte'] ? '' : ' hidden' ?>" novalidate>
    <h3 class="span-all">PIN das seções privadas (Sexualidade, Substâncias e Pensamentos de morte) <span class="hint" id="sexpin-state"><?= empty($user['sex_pin_hash']) ? '(sem PIN)' : '(PIN ativo)' ?></span></h3>
    <div class="field<?= empty($user['sex_pin_hash']) ? ' hidden' : '' ?>" id="sexpin-cur-f"><label for="sexpin-cur">PIN atual</label><input id="sexpin-cur" type="password" inputmode="numeric" maxlength="8" autocomplete="off"></div>
    <div class="field"><label for="sexpin-new">Novo PIN (4 a 8 números; vazio remove)</label><input id="sexpin-new" type="password" inputmode="numeric" maxlength="8" autocomplete="off"></div>
    <div class="form-actions"><button class="btn" type="submit">Salvar PIN</button> <?php if ($pf['modules']['sexualidade']): ?><button class="btn btn-danger-ghost" type="button" id="sex-wipe">Apagar todos os meus registros de sexualidade</button><?php endif; ?> <?php if ($pf['modules']['substancias']): ?><button class="btn btn-danger-ghost" type="button" id="subst-wipe">Apagar todos os meus registros de substâncias</button><?php endif; ?></div>
  </form>
</div>
<?php }
