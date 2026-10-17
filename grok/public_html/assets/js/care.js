/* V4.8: Conta → Compartilhar com profissional (paciente) e Meus pacientes (profissional). JavaScript puro, CSP 'self'. */
(function () {
  'use strict';
  const card = document.getElementById('care-card');
  const boot = document.getElementById('boot');
  if (!card || !boot) return;
  let csrf = JSON.parse(boot.textContent).csrf;
  document.addEventListener('bp:csrf', (e) => { csrf = e.detail; });
  const $ = (s) => document.getElementById(s);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const br = (d) => (d ? d.slice(8, 10) + '/' + d.slice(5, 7) + '/' + d.slice(0, 4) : '');
  const when = (utc) => { try { return new Date(String(utc).replace(' ', 'T') + 'Z').toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }); } catch { return utc; } };
  const say = (msg, type) => {
    let t = $('toast');
    if (!t) { t = document.createElement('div'); t.id = 'toast'; t.className = 'toast'; t.setAttribute('role', 'status'); t.setAttribute('aria-live', 'polite'); document.body.appendChild(t); }
    t.textContent = msg; t.dataset.type = type || 'ok'; t.classList.add('show');
    const ms = type === 'err' ? 7000 : 4000;
    clearTimeout(t._h); t._until = Date.now() + ms; t._h = setTimeout(() => { t._until = 0; t.classList.remove('show'); }, ms);
  };
  async function call(action, body) {
    const r = await fetch('/api/care.php' + (action ? '?action=' + action : ''), action
      ? { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body || {}) }
      : { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    let d = {};
    try { d = await r.json(); } catch { /* vazio */ }
    if (!r.ok) { const e = new Error(d.erro || 'Erro ' + r.status); e.status = r.status; throw e; }
    return d;
  }
  const STATUS = { pendente: 'aguardando aceite', ativo: 'ativo', recusado: 'recusado', revogado: 'encerrado', cancelado: 'cancelado', expirado: 'expirado' };
  let st = null;
  function scopeBoxes(prefix, sel) {
    return Object.entries(st.scopes).map(([k, s]) => '<label class="check"><input type="checkbox" name="' + prefix + '" value="' + k + '"' + (sel.includes(k) ? ' checked' : '') + '> <span>' + esc(s.label) + (s.sensitive ? ' <strong>(sensível)</strong>' : '') + '</span></label>').join('');
  }
  function renderShares() {
    const ul = $('care-shares');
    if (!st.shares.length) { ul.innerHTML = '<li class="muted">Você ainda não compartilha com ninguém.</li>'; return; }
    ul.innerHTML = st.shares.map((s) => {
      const who = s.profissional || s.convite_para || 'Profissional';
      const live = s.status === 'ativo' || s.status === 'pendente';
      const meta = [STATUS[s.status] || s.status, s.data_from ? 'dados desde ' + br(s.data_from) : '', s.expires_on ? 'até ' + br(s.expires_on) : 'sem data final'].filter(Boolean).join(' · ');
      const labels = s.scope.map((k) => (st.scopes[k] ? st.scopes[k].label : k)).join('; ');
      return '<li data-share="' + s.id + '"><div><strong>' + esc(who) + '</strong><br><span class="muted">' + esc(meta) + '</span><br><span class="small">' + esc(labels) + '</span></div>'
        + (live ? '<div class="row">'
          + '<details class="extras"><summary>Alterar</summary><form class="care-edit" data-id="' + s.id + '" novalidate><fieldset class="care-scope"><legend>O que compartilhar</legend>' + scopeBoxes('ed_scope', s.scope) + '</fieldset>'
          + '<div class="grid2"><div class="field"><label for="ed-from-' + s.id + '">Dados a partir de</label><input type="date" id="ed-from-' + s.id + '" name="data_from" value="' + esc(s.data_from || '') + '"></div>'
          + '<div class="field"><label for="ed-until-' + s.id + '">Acesso até</label><input type="date" id="ed-until-' + s.id + '" name="expires_on" value="' + esc(s.expires_on || '') + '"></div></div>'
          + '<div class="form-actions"><button class="btn btn-sm btn-primary" type="submit">Salvar</button></div></form></details>'
          + (s.status === 'pendente' && s.por_email ? ' <button type="button" class="btn btn-sm" data-care-link="' + s.id + '">Novo link</button>' : '')
          + ' <button type="button" class="btn btn-sm btn-danger-ghost" data-care-revoke="' + s.id + '" aria-label="' + (s.status === 'pendente' ? 'Cancelar convite de ' : 'Encerrar compartilhamento com ') + esc(who) + '">' + (s.status === 'pendente' ? 'Cancelar convite' : 'Encerrar') + '</button></div>' : '')
        + '</li>';
    }).join('');
  }
  function renderLog() {
    const ul = $('care-log');
    ul.innerHTML = st.log.length ? st.log.map((l) => '<li><div><strong>' + esc(l.quem) + '</strong> ' + esc(l.acao) + ' · ' + esc(when(l.quando_utc))
      + (l.de ? '<br><span class="small">Período: ' + esc(br(l.de)) + ' a ' + esc(br(l.ate)) + '</span>' : '')
      + (l.acao_codigo === 'visualizou' && l.escopo.length ? '<br><span class="small">Categorias: ' + esc(l.escopo.join('; ')) + '</span>' : '') + '</div></li>').join('') : '<li class="muted">Nenhum acesso registrado.</li>';
  }
  function renderPro() {
    $('care-pro-area').hidden = !st.me.is_professional;
    const pend = $('care-pending');
    pend.innerHTML = st.pending.length ? st.pending.map((p) => '<li><div><strong>' + esc(p.paciente) + '</strong> quer compartilhar com você<br><span class="small">' + esc(p.scope.join('; ')) + (p.expires_on ? ' · até ' + esc(br(p.expires_on)) : '') + '</span></div><div class="row">'
      + (st.me.is_professional ? '<button type="button" class="btn btn-sm btn-primary" data-care-accept="' + p.id + '">Aceitar</button> ' : '<span class="small">Marque “Sou profissional de saúde” acima para aceitar.</span> ')
      + '<button type="button" class="btn btn-sm btn-ghost" data-care-decline="' + p.id + '">Recusar</button></div></li>').join('') : '<li class="muted">Nenhum convite.</li>';
    const pts = $('care-patients');
    pts.innerHTML = st.patients.length ? st.patients.map((p) => '<li><div><strong>' + esc(p.nome) + '</strong><br><span class="small">' + esc(p.scope.join('; ')) + (p.expires_on ? ' · até ' + esc(br(p.expires_on)) : '') + '</span></div><div class="row"><a class="btn btn-sm btn-primary" href="/paciente.php?c=' + p.id + '">Ver registros (só leitura)</a> <button type="button" class="btn btn-sm btn-danger-ghost" data-care-revoke="' + p.id + '" aria-label="Encerrar acompanhamento de ' + esc(p.nome) + '">Encerrar</button></div></li>').join('') : '<li class="muted">Nenhum paciente ainda.</li>';
  }
  async function load() {
    try { st = await call(''); } catch { $('care-shares').innerHTML = '<li class="muted">Não foi possível carregar.</li>'; return; }
    renderShares(); renderLog(); renderPro();
  }
  function showLink(r, who) {
    $('care-link').value = r.link;
    $('care-link-msg').textContent = (r.email_enviado ? 'Convite enviado por e-mail para ' + who + '. ' : 'Envie este link para ' + who + ' (o e-mail automático está desligado neste servidor). ')
      + 'O link vale ' + r.dias + ' dias, só uma vez e só para a conta desse e-mail.';
    const msg = 'Olá! Quero compartilhar meus registros do diário de humor com você (só leitura). Aceite por este link: ' + r.link;
    $('care-wa').href = 'https://wa.me/?text=' + encodeURIComponent(msg);
    $('care-share').classList.toggle('hidden', !navigator.share);
    $('care-share').onclick = () => { navigator.share({ title: 'Convite', text: msg }).catch(() => {}); };
    $('care-link-box').classList.remove('hidden'); $('care-link-box').focus();
  }
  card.querySelectorAll('input[name=care_by]').forEach((r) => r.addEventListener('change', () => {
    const code = $('care-by-code').checked;
    $('care-email-f').classList.toggle('hidden', code); $('care-code-f').classList.toggle('hidden', !code);
  }));
  $('form-care-invite').addEventListener('submit', async (e) => {
    e.preventDefault();
    const byCode = $('care-by-code').checked;
    const body = { scope: [...card.querySelectorAll('input[name=care_scope]:checked')].map((c) => c.value), data_from: $('care-from').value || null, expires_on: $('care-until').value || null };
    if (byCode) body.code = $('care-code').value; else body.email = $('care-email').value.trim();
    if (!body.scope.length) { say('Escolha pelo menos uma categoria.', 'err'); return; }
    try {
      const r = await call('invite', body);
      if (r.por === 'email') showLink(r, body.email); else { $('care-link-box').classList.add('hidden'); say('Convite enviado para ' + r.profissional + '. Falta ele aceitar.'); }
      e.target.reset(); $('care-email-f').classList.remove('hidden'); $('care-code-f').classList.add('hidden');
      load();
    } catch (err) { say(err.message, 'err'); }
  });
  $('care-copy').addEventListener('click', async () => {
    try { await navigator.clipboard.writeText($('care-link').value); say('Link copiado.'); } catch { $('care-link').select(); say('Selecione e copie o link.', 'err'); }
  });
  card.addEventListener('submit', async (e) => {
    const f = e.target.closest('.care-edit'); if (!f) return;
    e.preventDefault();
    try {
      await call('update', { id: +f.dataset.id, scope: [...f.querySelectorAll('input[name=ed_scope]:checked')].map((c) => c.value), data_from: f.elements.data_from.value || null, expires_on: f.elements.expires_on.value || null });
      say('Compartilhamento atualizado. Vale a partir de agora.'); load();
    } catch (err) { say(err.message, 'err'); }
  });
  document.addEventListener('click', async (e) => {
    const t = e.target.closest('[data-care-revoke],[data-care-link],[data-care-accept],[data-care-decline]'); if (!t) return;
    try {
      if (t.dataset.careRevoke) { await call('revoke', { id: +t.dataset.careRevoke }); say('Encerrado. O acesso parou agora.'); }
      if (t.dataset.careLink) { const r = await call('link', { id: +t.dataset.careLink }); showLink(r, 'o profissional'); }
      if (t.dataset.careAccept) { await call('accept', { id: +t.dataset.careAccept }); say('Convite aceito.'); }
      if (t.dataset.careDecline) { await call('decline', { id: +t.dataset.careDecline }); say('Convite recusado.'); }
      load();
    } catch (err) { say(err.message, 'err'); }
  });
  $('form-care-profile').addEventListener('submit', async (e) => {
    e.preventDefault();
    try { await call('profile', { is_professional: $('cp-on').checked, kind: $('cp-kind').value || null, reg: $('cp-reg').value }); say('Perfil salvo.'); load(); } catch (err) { say(err.message, 'err'); }
  });
  $('care-code-btn').addEventListener('click', async () => {
    try { const r = await call('code', {}); $('care-code-out').textContent = r.code + ' (vale ' + r.horas + ' h, uso único)'; } catch (err) { say(err.message, 'err'); }
  });
  load();
})();
