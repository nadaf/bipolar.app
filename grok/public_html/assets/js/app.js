/* Bipolar — Diário de Humor | JavaScript puro, sem build */
(function () {
  'use strict';
  const B = JSON.parse(document.getElementById('boot').textContent);
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const LV = Object.fromEntries(B.levels.map((l) => [l.v, l]));
  const PERIOD_ORDER = ['manha', 'tarde', 'noite'];
  const SVGNS = 'http://www.w3.org/2000/svg';
  const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;

  function announce(msg) { const l = $('#sr-live'); l.textContent = ''; setTimeout(() => { l.textContent = msg; }, 50); }
  function toast(msg, type) {
    let t = $('#toast');
    if (!t) { t = document.createElement('div'); t.id = 'toast'; t.className = 'toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
    if (!t.dataset.bound) { t.dataset.bound = '1'; t.title = 'Toque para fechar'; t.addEventListener('click', hideToast); }
    t.textContent = msg; t.dataset.type = type || 'ok'; t.classList.add('show');
    clearTimeout(t._h); t._h = setTimeout(hideToast, type === 'err' || type === 'warn' ? 7000 : 4000);
  }
  function hideToast() { const t = $('#toast'); if (t) { clearTimeout(t._h); t.classList.remove('show'); } }
  // V4.2: o toast nunca cobre a barra de abas inferior (celular): mede a altura real da barra
  (function watchTabbar() {
    const bar = $('.tabs'); if (!bar) return;
    const upd = () => { const fixed = getComputedStyle(bar).position === 'fixed'; document.documentElement.style.setProperty('--tabbar-h', fixed ? bar.offsetHeight + 'px' : '0px'); };
    upd(); if (window.ResizeObserver) new ResizeObserver(upd).observe(bar); addEventListener('resize', upd);
  })();
  // V4.2: prende o foco do teclado dentro de um diálogo (Tab e Shift+Tab circulam só pelos controles dele)
  const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  function trapFocus(dlg) {
    if (dlg.dataset.trap) return; dlg.dataset.trap = '1';
    dlg.addEventListener('keydown', (e) => {
      if (e.key !== 'Tab') return;
      const f = $$(FOCUSABLE, dlg).filter((x) => x.offsetParent !== null || x === document.activeElement);
      if (!f.length) { e.preventDefault(); return; }
      const first = f[0], last = f[f.length - 1];
      if (!dlg.contains(document.activeElement)) { e.preventDefault(); first.focus(); return; }
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
    dlg.addEventListener('focusout', (e) => { if (dlg.open && e.relatedTarget && !dlg.contains(e.relatedTarget)) { const f = $$(FOCUSABLE, dlg)[0]; if (f) f.focus(); } });
  }
  addEventListener('keydown', (e) => { if (e.key === 'Escape') hideToast(); });
  // Diálogo de confirmação acessível (substitui window.confirm): <dialog> modal, foco no botão seguro, Esc cancela.
  function askConfirm(o) {
    if (typeof o === 'string') o = { text: o };
    let dlg = $('#confirm-dlg');
    if (!dlg) {
      dlg = document.createElement('dialog'); dlg.id = 'confirm-dlg'; dlg.className = 'dlg';
      dlg.setAttribute('aria-labelledby', 'confirm-dlg-title'); dlg.setAttribute('aria-describedby', 'confirm-dlg-body');
      dlg.innerHTML = '<form method="dialog"><h2 id="confirm-dlg-title"></h2><div id="confirm-dlg-body"></div><p class="row dlg-actions"><button value="cancel" class="btn" id="confirm-dlg-cancel">Cancelar</button> <button value="ok" class="btn btn-primary" id="confirm-dlg-ok">Confirmar</button></p></form>';
      document.body.appendChild(dlg);
      trapFocus(dlg);
    }
    $('#confirm-dlg-title').textContent = o.title || (o.danger ? 'Confirmar exclusão' : 'Confirmar');
    $('#confirm-dlg-body').innerHTML = '<p>' + esc(o.text || '') + '</p>' + (o.items ? '<ul>' + o.items.map((i) => '<li>' + esc(i) + '</li>').join('') + '</ul>' : '');
    const ok = $('#confirm-dlg-ok'); ok.textContent = o.ok || 'Confirmar'; ok.className = 'btn ' + (o.danger ? 'btn-danger' : 'btn-primary');
    const back = document.activeElement;
    return new Promise((resolve) => {
      dlg.returnValue = 'cancel';
      dlg.addEventListener('close', () => { resolve(dlg.returnValue === 'ok'); if (back && back.focus) back.focus(); }, { once: true });
      if (dlg.showModal) dlg.showModal(); else { resolve(window.confirm(o.text)); return; }
      $('#confirm-dlg-cancel').focus();
    });
  }
  // Pede PIN/senha num diálogo acessível. Devolve '' se cancelado.
  function askSecret(o) {
    let dlg = $('#secret-dlg');
    if (!dlg) {
      dlg = document.createElement('dialog'); dlg.id = 'secret-dlg'; dlg.className = 'dlg';
      dlg.setAttribute('aria-labelledby', 'secret-dlg-title');
      dlg.innerHTML = '<form method="dialog"><h2 id="secret-dlg-title"></h2><p id="secret-dlg-text"></p><div class="field"><label for="secret-dlg-in" id="secret-dlg-label"></label><input type="password" id="secret-dlg-in" autocomplete="current-password"></div><p class="row dlg-actions"><button value="cancel" class="btn" formnovalidate>Cancelar</button> <button value="ok" class="btn btn-primary">Confirmar</button></p></form>';
      document.body.appendChild(dlg);
      trapFocus(dlg);
    }
    $('#secret-dlg-title').textContent = o.title; $('#secret-dlg-text').textContent = o.text; $('#secret-dlg-label').textContent = o.label;
    const inp = $('#secret-dlg-in'); inp.value = '';
    const back = document.activeElement;
    return new Promise((resolve) => {
      dlg.returnValue = 'cancel';
      dlg.addEventListener('close', () => { resolve(dlg.returnValue === 'ok' ? inp.value : ''); inp.value = ''; if (back && back.focus) back.focus(); }, { once: true });
      if (dlg.showModal) dlg.showModal(); else { resolve(window.prompt(o.text) || ''); return; }
      inp.focus();
    });
  }
  const cap = (t) => (t ? t.charAt(0).toUpperCase() + t.slice(1) : t);
  const plural = (n, one, many) => n + ' ' + (n === 1 ? one : many);
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
  async function api(path, opts = {}) {
    const o = { method: opts.method || 'GET', headers: { 'X-CSRF-Token': B.csrf, Accept: 'application/json' }, credentials: 'same-origin' };
    if (opts.json !== undefined) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(opts.json); }
    if (opts.form) o.body = opts.form;
    const res = await fetch(path, o);
    let data = {};
    try { data = await res.json(); } catch { /* vazio */ }
    if (res.status === 423 && data.bloqueado_app) { location.reload(); }
    if (res.status === 401) { toast('Sua sessão expirou. Entre novamente.', 'err'); setTimeout(() => location.reload(), 1500); }
    if (!res.ok) { const err = new Error(data.erro || data.mensagem || 'Erro ' + res.status); err.status = res.status; err.data = data; throw err; }
    return data;
  }
  // V4.3.1: o registro mudou em outra aba/aparelho (concorrência otimista). Oferece recarregar.
  const isVersionConflict = (err) => !!(err && err.status === 409 && err.data && err.data.versao_conflito);
  async function offerReload(err) {
    if (await askConfirm({ title: 'Registro alterado em outro lugar', text: err.message, ok: 'Recarregar agora' })) location.reload();
  }
  const todayISO = (d = new Date()) => new Intl.DateTimeFormat('en-CA', { timeZone: B.tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(d);
  const hourNow = () => +new Intl.DateTimeFormat('en-GB', { timeZone: B.tz, hour: '2-digit', hour12: false }).format(new Date()) % 24;
  const addDays = (iso, n) => { const d = new Date(iso + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
  const fmtBR = (iso) => iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4);
  const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }));
  const sign = (v) => (v > 0 ? '+' + v : v < 0 ? '−' + Math.abs(v) : '0');
  const NF = {};
  // Números no padrão brasileiro (vírgula decimal)
  const fmtNum = (v, dec = 1) => { if (v === null || v === undefined || v === '') return ''; NF[dec] = NF[dec] || new Intl.NumberFormat('pt-BR', { minimumFractionDigits: dec, maximumFractionDigits: dec }); return NF[dec].format(+v).replace('-', '−'); };
  const isoToBR = (iso) => (iso && /^\d{4}-\d{2}-\d{2}$/.test(iso) ? fmtBR(iso) : '');
  const brToISO = (br) => {
    const m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec((br || '').trim()); if (!m) return null;
    const iso = m[3] + '-' + m[2].padStart(2, '0') + '-' + m[1].padStart(2, '0');
    const d = new Date(iso + 'T12:00:00Z');
    return !isNaN(d) && d.toISOString().slice(0, 10) === iso ? iso : null;
  };


  /* ---------- Calendário próprio em pt-BR (o nativo segue o idioma do navegador) ---------- */
  const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
  const DIAS = [['D', 'domingo'], ['S', 'segunda'], ['T', 'terça'], ['Q', 'quarta'], ['Q', 'quinta'], ['S', 'sexta'], ['S', 'sábado']];
  function calPick(startIso, maxIso, opener) {
    return new Promise((resolve) => {
      const dlg = document.createElement('dialog');
      dlg.className = 'dlg cal'; dlg.setAttribute('aria-label', 'Escolher data');
      let cur = startIso, done = false;
      const finish = (v) => { if (done) return; done = true; dlg.close(); dlg.remove(); if (!v && opener) opener.focus(); resolve(v); };
      function draw(focus) {
        const y = +cur.slice(0, 4), m = +cur.slice(5, 7) - 1;
        const first = new Date(Date.UTC(y, m, 1)), days = new Date(Date.UTC(y, m + 1, 0)).getUTCDate();
        let h = '<div class="cal-head"><button type="button" class="btn btn-ghost btn-sm" data-nav="-1" aria-label="Mês anterior">‹</button><h2 class="cal-title" aria-live="polite">' + MESES[m] + ' de ' + y + '</h2><button type="button" class="btn btn-ghost btn-sm" data-nav="1" aria-label="Próximo mês">›</button></div>';
        h += '<table class="cal-grid"><thead><tr>' + DIAS.map((d) => '<th scope="col"><abbr title="' + d[1] + '">' + d[0] + '</abbr></th>').join('') + '</tr></thead><tbody><tr>';
        for (let i = 0; i < first.getUTCDay(); i++) h += '<td></td>';
        for (let d = 1; d <= days; d++) {
          const iso = y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
          const off = maxIso && iso > maxIso, sel = iso === cur;
          h += '<td><button type="button" class="cal-day' + (iso === todayISO() ? ' today' : '') + '" data-iso="' + iso + '" tabindex="' + (sel ? 0 : -1) + '"' + (sel ? ' aria-pressed="true"' : '') + (iso === todayISO() ? ' aria-current="date"' : '') + (off ? ' disabled' : '') + ' aria-label="' + d + ' de ' + MESES[m] + ' de ' + y + '">' + d + '</button></td>';
          if ((first.getUTCDay() + d) % 7 === 0 && d < days) h += '</tr><tr>';
        }
        h += '</tr></tbody></table><div class="dlg-actions"><button type="button" class="btn btn-ghost" data-today>Hoje</button><button type="button" class="btn btn-ghost" data-cancel>Cancelar</button></div>';
        dlg.innerHTML = h;
        if (focus) { const b = $('[data-iso="' + cur + '"]', dlg); if (b) b.focus(); }
      }
      dlg.addEventListener('click', (e) => {
        const t = e.target.closest('button'); if (!t) return;
        if (t.dataset.nav) { const d = new Date(cur + 'T12:00:00Z'); d.setUTCDate(1); d.setUTCMonth(d.getUTCMonth() + +t.dataset.nav); cur = d.toISOString().slice(0, 10); draw(false); $('[data-nav="' + t.dataset.nav + '"]', dlg).focus(); return; }
        if (t.dataset.iso) return finish(t.dataset.iso);
        if (t.hasAttribute('data-today')) return finish(todayISO());
        if (t.hasAttribute('data-cancel')) finish(null);
      });
      dlg.addEventListener('keydown', (e) => {
        const step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
        if (step && e.target.dataset.iso) {
          e.preventDefault(); const n = addDays(cur, step);
          if (!maxIso || n <= maxIso) { cur = n; draw(true); }
        }
      });
      dlg.addEventListener('cancel', (e) => { e.preventDefault(); finish(null); });
      document.body.appendChild(dlg); trapFocus(dlg); draw(false); dlg.showModal();
      const b = $('[data-iso="' + cur + '"]', dlg); if (b) b.focus();
    });
  }

  /* ---------- Campos de data dd/mm/aaaa ---------- */
  // O texto visível é a fonte para o usuário; o input date escondido guarda o ISO e abre o calendário nativo.
  function dateField(id) {
    const nat = $('#' + id), txt = $('#' + id + '-br'), err = $('#' + id + '-err');
    const api = {
      get: () => nat.value,
      set(iso) { nat.value = iso || ''; txt.value = isoToBR(iso); txt.removeAttribute('aria-invalid'); err.textContent = ''; },
      validate() {
        const iso = brToISO(txt.value);
        let msg = '';
        if (!iso) msg = !txt.value.trim() ? 'Informe a data.' : /^\d{1,2}\/\d{1,2}\/\d{4}$/.test(txt.value.trim()) ? 'Data inválida: esse dia não existe.' : 'Data inválida. Use o formato dd/mm/aaaa.';
        else if (nat.max && iso > nat.max) msg = 'A data não pode ser no futuro.';
        if (msg) { txt.setAttribute('aria-invalid', 'true'); err.textContent = msg; nat.value = ''; return null; }
        txt.removeAttribute('aria-invalid'); err.textContent = ''; nat.value = iso; return iso;
      },
    };
    txt.addEventListener('input', (e) => {
      if (e.inputType && e.inputType.startsWith('delete')) return;
      const d = txt.value.replace(/\D/g, '').slice(0, 8);
      txt.value = d.length > 4 ? d.slice(0, 2) + '/' + d.slice(2, 4) + '/' + d.slice(4) : d.length > 2 ? d.slice(0, 2) + '/' + d.slice(2) : d;
    });
    txt.addEventListener('change', () => { if (api.validate()) nat.dispatchEvent(new CustomEvent('datechange')); });
    nat.addEventListener('change', () => { api.set(nat.value); nat.dispatchEvent(new CustomEvent('datechange')); txt.focus(); });
    const btn = $('.date-pick[data-for="' + id + '"]');
    btn.addEventListener('click', () => {
      calPick(brToISO(txt.value) || nat.value || todayISO(), nat.max || '', btn).then((iso) => {
        if (!iso) return;
        api.set(iso); nat.dispatchEvent(new CustomEvent('datechange')); txt.focus();
      });
    });
    return api;
  }
  // V4.2: horários sempre em 24 h (o campo nativo "time" mostra AM/PM em navegadores em inglês)
  function time24(inp) {
    inp.addEventListener('input', (e) => {
      if (e.inputType && e.inputType.startsWith('delete')) return;
      const d = inp.value.replace(/\D/g, '').slice(0, 4);
      inp.value = d.length > 2 ? d.slice(0, 2) + ':' + d.slice(2) : d;
    });
    inp.addEventListener('change', () => {
      const m = /^(\d{1,2}):?(\d{2})$/.exec(inp.value.trim());
      if (m && +m[1] < 24 && +m[2] < 60) { inp.value = m[1].padStart(2, '0') + ':' + m[2]; inp.removeAttribute('aria-invalid'); }
      else if (inp.value.trim() !== '') inp.setAttribute('aria-invalid', 'true');
    });
  }
  const validTime = (v) => /^([01]\d|2[0-3]):[0-5]\d$/.test(v);
  $$('.time24').forEach(time24);
  const entryDate = dateField('entry-date');
  const rFrom = dateField('r-from'), rTo = dateField('r-to');

  /* ---------- Abas (padrão ARIA tabs) ---------- */
  const tabs = $$('[role=tab]');
  function selectTab(tab, focus) {
    tabs.forEach((t) => {
      const on = t === tab;
      t.setAttribute('aria-selected', on); t.tabIndex = on ? 0 : -1;
      $('#' + t.getAttribute('aria-controls')).hidden = !on;
    });
    hideToast();
    if (focus) tab.focus();
    const id = tab.id.replace('tab-', '');
    history.replaceState(null, '', '?aba=' + id);
    if (id === 'historico') loadHistory();
    if (id === 'relatorios') loadRecipients();
    if (id === 'lembretes') loadReminders();
  }
  tabs.forEach((t, i) => {
    t.addEventListener('click', () => selectTab(t));
    t.addEventListener('keydown', (e) => {
      let n = null;
      if (e.key === 'ArrowRight') n = tabs[(i + 1) % tabs.length];
      if (e.key === 'ArrowLeft') n = tabs[(i - 1 + tabs.length) % tabs.length];
      if (e.key === 'Home') n = tabs[0];
      if (e.key === 'End') n = tabs[tabs.length - 1];
      if (n) { e.preventDefault(); selectTab(n, true); }
    });
  });

  /* ---------- Formulário de registro ---------- */
  const form = $('#form-entry');
  let editing = null; // registro salvo carregado no formulário (para edição)
  function defaultPeriod() { const h = hourNow(); return h < 12 ? 'manha' : h < 18 ? 'tarde' : 'noite'; }
  function clearFields() {
    const d = entryDate.get(), p = (form.querySelector('input[name=period]:checked') || {}).value;
    form.reset();
    entryDate.set(d); if (p) $('#p-' + p).checked = true;
    $$('.range-field input').forEach(updateRange);
  }
  // Estado do formulário (V4.1): "novo" (editing = null) ou "editando o registro X" (editing = registro com id).
  // Um registro existente nunca entra em edição sozinho: aparece em leitura com o botão Editar.
  // O período/data marcados no formulário e o registro em edição são sempre conferidos antes de salvar.
  function curSlot() { return { d: entryDate.get(), p: (form.querySelector('input[name=period]:checked') || {}).value }; }
  function setPeriod(p) { const r = $('#p-' + p); if (r) r.checked = true; }
  // V4.3: keepDate mantém a data escolhida depois de salvar um dia passado (antes voltava para hoje)
  function resetForm(preferFree = false, keepDate = null, lastPeriod = null) {
    $('#entry-date').max = todayISO();
    const d = keepDate && keepDate <= todayISO() ? keepDate : todayISO();
    entryDate.set(d);
    if (d !== todayISO() && lastPeriod) {
      const i = PERIOD_ORDER.indexOf(lastPeriod);
      setPeriod(i >= 0 && i < PERIOD_ORDER.length - 1 ? PERIOD_ORDER[i + 1] : lastPeriod);
    } else setPeriod(defaultPeriod());
    clearFields();
    setEditing(null);
    formTouched = 0;
    return loadSlot(false, preferFree);
  }
  function setEditing(e) {
    editing = e;
    const n = $('#edit-note');
    $('#btn-save-entry').textContent = e ? 'Salvar alterações' : 'Salvar registro';
    $('#btn-new-entry').classList.toggle('hidden', !e);
    if (e) {
      n.innerHTML = '<span aria-hidden="true">✎</span> Editando o registro de <strong>' + esc(B.periods[e.period]) + '</strong> de ' + fmtBR(e.entry_date) + (e.id ? '' : ' (ainda não enviado)') + '. Os campos abaixo mostram o que foi salvo.';
      n.classList.remove('hidden');
    } else n.classList.add('hidden');
    markTodayList();
  }
  function markTodayList() {
    const e = editing;
    $$('#today-list li').forEach((li) => li.classList.toggle('editing', !!e && li.dataset.period === e.period && e.entry_date === todayISO()));
  }
  function showBody(on) { $('#entry-body').classList.toggle('hidden', !on); }
  function viewSlot(e) {
    const v = $('#slot-view');
    if (!e) { v.classList.add('hidden'); v.innerHTML = ''; showBody(true); return; }
    const l = LV[+e.mood];
    v.innerHTML = '<p>Você já registrou <strong>' + esc(B.periods[e.period]) + '</strong> de ' + fmtBR(e.entry_date) + ': <span class="chip lvl' + (+e.mood + 3) + '"><span aria-hidden="true">' + l.icon + '</span> ' + sign(+e.mood) + ' ' + esc(l.label) + '</span>' +
      (e.sleep_hours != null && e.sleep_hours !== '' ? ' · sono ' + fmtNum(+e.sleep_hours, 1) + ' h' : '') + (e.id ? '' : ' <span class="muted">(pendente)</span>') + '</p>' +
      '<p class="row"><button type="button" class="btn btn-primary" id="slot-edit">✎ Editar este registro</button></p>';
    v.classList.remove('hidden');
    showBody(false);
    $('#slot-edit').addEventListener('click', () => { fillForm(e); focusForm(); });
  }
  function fillForm(e) {
    viewSlot(null);
    clearFields();
    setPeriod(e.period); entryDate.set(e.entry_date);
    const r = form.querySelector('input[name=mood][value="' + (+e.mood) + '"]'); if (r) r.checked = true;
    $('#sleep').value = e.sleep_hours === null || e.sleep_hours === undefined ? '' : +e.sleep_hours;
    const med = e.medication_taken === null || e.medication_taken === undefined || e.medication_taken === '' ? 'med-na' : +e.medication_taken ? 'med-s' : 'med-n';
    $('#' + med).checked = true;
    ['irritability', 'anxiety', 'energy'].forEach((k) => { const i = $('#' + k); i.value = e[k] === null || e[k] === undefined ? -1 : +e[k]; updateRange(i); });
    $('#tags').value = e.tags || ''; $('#note').value = e.note || '';
    const hasExtras = e.sleep_hours != null || (e.medication_taken != null && e.medication_taken !== '') || e.irritability != null || e.anxiety != null || e.energy != null || e.tags || e.note;
    if (hasExtras) form.querySelector('details.extras').open = true;
    setEditing(e);
  }
  function blankForm() { viewSlot(null); clearFields(); setEditing(null); }
  async function findSlot(d, p) {
    let e = queue().filter((x) => x.entry_date === d && x.period === p).pop() || null;
    if (!e && navigator.onLine) {
      try { const r = await api('/api/entries.php?from=' + d + '&to=' + d); e = r.entries.find((x) => x.period === p) || null; } catch { /* mantém formulário */ }
    }
    return e;
  }
  // Ao escolher data + período: existe registro → mostra em leitura (ou abre edição, se pedido); senão → formulário novo.
  let slotReq = 0, formTouched = 0;
  form.addEventListener('input', () => { formTouched++; });
  async function loadSlot(edit = false, preferFree = false) {
    const { d, p } = curSlot();
    if (!d || !p) return;
    const my = ++slotReq, touched = formTouched;
    // troca imediata de estado: nada do registro anterior fica no formulário enquanto a busca acontece
    if (editing && (editing.period !== p || editing.entry_date !== d)) blankForm();
    let e = await findSlot(d, p);
    if (my !== slotReq) return; // o usuário já mudou a escolha
    if (e && preferFree && d === todayISO()) {
      // V4.2: depois de salvar, vai para o próximo período de hoje ainda sem registro (o botão Salvar continua visível)
      const free = PERIOD_ORDER.find((x) => !todayMap[x]);
      if (free) { setPeriod(free); e = null; announce('Pronto para registrar ' + B.periods[free] + '.'); }
    }
    if (!e && !edit && !editing && touched !== formTouched) return; // V4.2: a pessoa já começou a preencher um período livre; não apaga
    if (e && edit) fillForm(e);
    else if (e) { if (editing) { clearFields(); setEditing(null); } viewSlot(e); }
    else blankForm();
  }
  $('#entry-date').addEventListener('datechange', () => loadSlot());
  $$('input[name=period]').forEach((r) => r.addEventListener('change', () => loadSlot()));
  $('#btn-new-entry').addEventListener('click', () => { resetForm(); $('#entry-date-br').focus(); });
  function focusForm() {
    form.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
    const m = form.querySelector('input[name=mood]:checked') || form.querySelector('input[name=mood]');
    m.focus({ preventScroll: true });
  }
  function editToday(period, edit) {
    entryDate.set(todayISO()); setPeriod(period);
    return loadSlot(edit).then(() => {
      focusForm();
      announce(edit ? 'Editando o registro de ' + B.periods[period] + '. Altere o que quiser e salve.' : 'Novo registro de ' + B.periods[period] + '.');
    });
  }
  const RANGE_TXT = ['não informado', 'nenhuma', 'leve', 'moderada', 'intensa'];
  function updateRange(inp) {
    const t = RANGE_TXT[+inp.value + 1];
    $('#' + inp.id + '-out').textContent = t;
    inp.setAttribute('aria-valuetext', t);
  }
  $$('.range-field input').forEach((i) => i.addEventListener('input', () => updateRange(i)));

  function formPayload() {
    const fd = new FormData(form);
    const num = (k) => { const v = fd.get(k); return v === null || v === '' || +v < 0 ? null : +v; };
    return {
      client_uuid: uuid(), entry_date: fd.get('entry_date'), period: fd.get('period'),
      mood: fd.get('mood') === null ? null : +fd.get('mood'),
      sleep_hours: fd.get('sleep_hours') || null, medication_taken: fd.get('medication_taken'),
      irritability: num('irritability'), anxiety: num('anxiety'), energy: num('energy'),
      tags: fd.get('tags') || '', note: fd.get('note') || '',
    };
  }
  // Fila offline (localStorage)
  const Q_KEY = 'bp-queue';
  const queue = () => { try { return JSON.parse(localStorage.getItem(Q_KEY)) || []; } catch { return []; } };
  const saveQueue = (q) => localStorage.setItem(Q_KEY, JSON.stringify(q));
  async function flushQueue() {
    const q = queue(); if (!q.length || !navigator.onLine) return;
    const rest = [];
    for (const item of q) {
      try { const it = Object.assign({}, item); delete it.version; await api('/api/entries.php', { method: 'POST', json: it }); } catch (e) { if (!e.status || e.status >= 500) rest.push(item); }
    }
    saveQueue(rest);
    if (q.length !== rest.length) { toast(plural(q.length - rest.length, 'registro offline sincronizado', 'registros offline sincronizados') + '.'); loadToday(); }
  }
  function updateOnline() { $('#offline-banner').classList.toggle('hidden', navigator.onLine); if (navigator.onLine) flushQueue(); }
  addEventListener('online', updateOnline); addEventListener('offline', updateOnline);
  if (navigator.serviceWorker) navigator.serviceWorker.addEventListener('message', (e) => { if (e.data === 'sync-entries') flushQueue(); });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!entryDate.validate()) { $('#entry-date-br').focus(); return toast($('#entry-date-err').textContent, 'err'); }
    const p = formPayload();
    if (editing && (editing.period !== p.period || editing.entry_date !== p.entry_date)) {
      // estado inconsistente: nunca salva por cima de outro período
      toast('O período mudou durante a edição. Confira os dados e salve de novo.', 'err');
      return loadSlot();
    }
    if (editing && editing.id) p.id = +editing.id;
    if (editing && editing.version != null) p.version = +editing.version; // V4.3.1
    if (p.mood === null) { $('.mood-options input').focus(); announce('Escolha um nível de humor.'); return toast('Escolha um nível de humor.', 'err'); }
    if (saving) return; // V4.2: um salvamento por vez (toque duplo ou Enter repetido)
    saving = true;
    slotReq++; // descarta buscas de período ainda em andamento: não podem mais trocar o formulário
    const btn = form.querySelector('[type=submit]'); btn.disabled = true; btn.setAttribute('aria-busy', 'true');
    try {
      if (!navigator.onLine) throw Object.assign(new Error('offline'), { offline: true });
      const r = await api('/api/entries.php', { method: 'POST', json: p });
      await uploadPending(r.id);
      const wasEditing = !!editing;
      rememberSaved(r.entry || Object.assign({ id: r.id }, p));
      toast((wasEditing ? 'Registro atualizado: ' : 'Registro salvo: ') + LV[p.mood].label + ' · ' + B.periods[p.period]);
      announce('Registro salvo com sucesso.');
      showAlert(r.alerta);
      celebrate(); maybeOnboardPush();
      await loadToday();
      await resetForm(true, p.entry_date, p.period);
      afterDataSave(r);
    } catch (err) {
      if (err.offline || !err.status) {
        // fila offline: sem versão (é reenviada depois; mantém o comportamento anterior, sem 409 de versão)
        const qp = Object.assign({}, p); delete qp.version;
        const q = queue().filter((x) => !(x.entry_date === p.entry_date && x.period === p.period)); q.push(qp); saveQueue(q);
        if (pending.length) toast('Salvo no aparelho. Áudio/vídeo não pode ser guardado offline; grave novamente quando estiver online.', 'warn');
        else toast('Sem conexão: registro guardado no aparelho e será enviado depois.', 'warn');
        try { (await navigator.serviceWorker.ready).sync.register('sync-entries'); } catch { /* opcional */ }
        resetForm(false, p.entry_date, p.period); loadToday();
      } else if (isVersionConflict(err)) { toast(err.message, 'err'); offerReload(err); } else if (err.status === 409) { toast(err.message, 'err'); loadSlot(); } else toast(err.message || 'Não foi possível salvar. Tente de novo.', 'err');
    } finally { saving = false; btn.disabled = false; btn.removeAttribute('aria-busy'); }
  });
  let saving = false;
  // V4.2: o registro recém-salvo aparece em "Hoje" na hora, mesmo que a lista chegue do servidor antes da gravação terminar
  const justSaved = {};
  function rememberSaved(e) { if (e && e.entry_date && e.period) justSaved[e.entry_date + '|' + e.period] = Object.assign({}, e, { _t: Date.now() }); }
  // Depois de salvar qualquer dado: alertas recalculados na hora (Histórico e aviso no topo)
  function afterDataSave(r) {
    if (r && r.alertas_personalizados > 0) toast(r.alertas_personalizados === 1 ? 'Um alerta que você criou foi atendido. Veja em Histórico.' : r.alertas_personalizados + ' alertas que você criou foram atendidos. Veja em Histórico.', 'warn');
    loadAlerts();
  }
  function showAlert(msg) {
    const n = $('#extreme-note');
    if (!msg) { n.classList.add('hidden'); return; }
    n.innerHTML = '<span aria-hidden="true">💙</span> ' + esc(msg) + ' <a href="tel:188">Ligar 188</a> <button type="button" class="btn btn-ghost btn-sm" id="close-note">Entendi</button>';
    n.classList.remove('hidden');
    $('#close-note').addEventListener('click', () => n.classList.add('hidden'));
  }
  function celebrate() {
    if (reduceMotion) return;
    const b = form.querySelector('[type=submit]'); b.classList.add('pulse'); setTimeout(() => b.classList.remove('pulse'), 700);
  }

  let todayReq = 0, todayMap = {};
  async function loadToday() {
    const ul = $('#today-list');
    const my = ++todayReq; // V4.2: só a resposta mais recente desenha a lista (respostas antigas chegando depois eram o "some até recarregar")
    try {
      const t = todayISO();
      const { entries } = await api('/api/entries.php?from=' + t + '&to=' + t);
      if (my !== todayReq) return;
      const q = queue().filter((x) => x.entry_date === t);
      const map = {};
      entries.forEach((e) => { map[e.period] = e; });
      PERIOD_ORDER.forEach((p) => { const js = justSaved[t + '|' + p]; if (js && Date.now() - js._t < 120000 && (!map[p] || +map[p].id === +js.id || !js.id)) map[p] = Object.assign({}, map[p] || {}, js); });
      todayMap = Object.assign({}, map); q.forEach((x) => { todayMap[x.period] = x; });
      ul.innerHTML = PERIOD_ORDER.map((p) => {
        const e = q.filter((x) => x.period === p).pop() || map[p];
        if (!e) return '<li class="empty" data-period="' + p + '"><span class="per">' + esc(B.periods[p]) + '</span> <span class="muted">sem registro</span> <button type="button" class="btn btn-ghost btn-sm edit" data-new="' + p + '" aria-label="Registrar ' + esc(B.periods[p]) + '">Registrar</button></li>';
        const l = LV[+e.mood];
        return '<li data-period="' + p + '"><span class="per">' + esc(B.periods[p]) + '</span> <span class="chip lvl' + (+e.mood + 3) + '"><span aria-hidden="true">' + l.icon + '</span> ' + sign(+e.mood) + ' ' + esc(l.label) + '</span>' + (e.id ? '' : ' <span class="muted">(pendente)</span>') +
          ' <button type="button" class="btn btn-sm edit" data-edit="' + p + '" aria-label="Editar registro da ' + esc(B.periods[p]) + '">✎ Editar</button></li>';
      }).join('');
    } catch { if (my === todayReq) ul.innerHTML = '<li class="muted">Não foi possível carregar.</li>'; }
    ul.removeAttribute('aria-busy');
    markTodayList();
  }
  $('#today-list').addEventListener('click', (e) => {
    const b = e.target.closest('button'); if (!b) return;
    if (b.dataset.edit) editToday(b.dataset.edit, true); else editToday(b.dataset.new, false);
  });

  /* ---------- Mídia: gravação e upload ---------- */
  let pending = []; let recorder = null; let stream = null;
  function renderPending() {
    $('#pending-media').innerHTML = pending.map((p, i) => '<li>' + (p.kind === 'audio' ? '🎙' : p.kind === 'video' ? '🎥' : '📎') + ' ' + esc(p.name) + ' (' + (p.blob.size / 1048576).toFixed(1) + ' MB) <button type="button" class="btn btn-ghost btn-sm" data-rm="' + i + '" aria-label="Remover ' + esc(p.name) + '">Remover</button></li>').join('');
  }
  $('#pending-media').addEventListener('click', (e) => { const i = e.target.dataset.rm; if (i !== undefined) { pending.splice(+i, 1); renderPending(); } });
  function pickMime(kind) {
    const c = kind === 'audio' ? ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'] : ['video/webm;codecs=vp9,opus', 'video/webm', 'video/mp4'];
    return c.find((m) => window.MediaRecorder && MediaRecorder.isTypeSupported(m)) || '';
  }
  async function toggleRec(kind) {
    const b = $('#rec-' + kind), status = $('#rec-status'), prev = $('#video-preview');
    if (recorder) { recorder.stop(); return; }
    if (!window.MediaRecorder || !navigator.mediaDevices) { toast('Seu navegador não permite gravação. Use "Enviar arquivo".', 'err'); return; }
    try {
      stream = await navigator.mediaDevices.getUserMedia(kind === 'audio' ? { audio: true } : { audio: true, video: { facingMode: 'user', width: { ideal: 1280 } } });
    } catch { toast('Permissão de ' + (kind === 'audio' ? 'microfone' : 'câmera') + ' negada.', 'err'); return; }
    const mime = pickMime(kind); const chunks = [];
    recorder = new MediaRecorder(stream, mime ? { mimeType: mime, videoBitsPerSecond: 1500000 } : undefined);
    recorder.ondataavailable = (e) => { if (e.data.size) chunks.push(e.data); };
    let secs = 0; const timer = setInterval(() => { secs++; status.textContent = 'Gravando… ' + Math.floor(secs / 60) + ':' + String(secs % 60).padStart(2, '0'); }, 1000);
    recorder.onstop = () => {
      clearInterval(timer);
      stream.getTracks().forEach((t) => t.stop());
      prev.srcObject = null; prev.classList.add('hidden');
      const type = (recorder.mimeType || mime || (kind + '/webm')).split(';')[0];
      const blob = new Blob(chunks, { type });
      const ext = type.includes('mp4') ? (kind === 'audio' ? 'm4a' : 'mp4') : type.includes('ogg') ? 'ogg' : 'webm';
      pending.push({ kind, blob, name: (kind === 'audio' ? 'audio' : 'video') + '-' + new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-') + '.' + ext });
      renderPending();
      recorder = null; b.setAttribute('aria-pressed', 'false'); b.textContent = kind === 'audio' ? '🎙 Gravar áudio' : '🎥 Gravar vídeo';
      status.textContent = 'Gravação pronta. Será enviada ao salvar o registro.'; announce(status.textContent);
    };
    if (kind === 'video') { prev.srcObject = stream; prev.classList.remove('hidden'); prev.play().catch(() => {}); }
    recorder.start(1000);
    b.setAttribute('aria-pressed', 'true'); b.textContent = '⏹ Parar gravação';
    status.textContent = 'Gravando…'; announce('Gravação iniciada. Pressione o botão novamente para parar.');
  }
  $('#rec-audio').addEventListener('click', () => toggleRec('audio'));
  $('#rec-video').addEventListener('click', () => toggleRec('video'));
  $('#media-file').addEventListener('change', (e) => {
    const f = e.target.files[0]; if (!f) return;
    const kind = f.type.split('/')[0];
    const lim = (B.media['max_' + kind + '_mb'] || 10) * 1048576;
    if (f.size > lim) { toast('Arquivo acima do limite de ' + (lim / 1048576) + ' MB.', 'err'); return; }
    pending.push({ kind, blob: f, name: f.name }); renderPending(); e.target.value = '';
  });
  async function uploadPending(entryId) {
    while (pending.length) {
      const p = pending[0];
      const fd = new FormData(); fd.append('file', p.blob, p.name); fd.append('entry_id', entryId);
      $('#rec-status').textContent = 'Enviando ' + p.name + '…';
      try { await api('/api/media.php', { method: 'POST', form: fd }); pending.shift(); }
      catch (e) { toast('Falha ao enviar ' + p.name + ': ' + e.message, 'err'); break; }
    }
    renderPending(); $('#rec-status').textContent = '';
  }

  /* ---------- Histórico: life chart SVG + heatmap ---------- */
  function el(tag, attrs, parent) { const n = document.createElementNS(SVGNS, tag); for (const k in attrs) n.setAttribute(k, attrs[k]); if (parent) parent.appendChild(n); return n; }
  $('#legend').innerHTML = B.levels.slice().reverse().map((l) => '<li><span class="chip lvl' + (l.v + 3) + '"><span aria-hidden="true">' + l.icon + '</span> ' + sign(l.v) + '</span> ' + esc(l.label) + '</li>').join('');
  $('#range-days').addEventListener('change', loadHistory);
  let lastChart = null, rsz = null;
  addEventListener('resize', () => { clearTimeout(rsz); rsz = setTimeout(() => { if (lastChart && !$('#painel-historico').hidden) drawLifeChart(...lastChart); }, 200); });

  async function loadHistory() {
    const days = +$('#range-days').value; const to = todayISO(); const from = addDays(to, -(days - 1));
    let entries = [];
    try { entries = (await api('/api/entries.php?from=' + from + '&to=' + to)).entries; } catch (e) { toast(e.message, 'err'); }
    drawLifeChart(entries, from, days);
    let hm = entries;
    if (days < 126) { try { hm = (await api('/api/entries.php?from=' + addDays(to, -125) + '&to=' + to)).entries; } catch { /* usa o período atual */ } }
    drawHeatmap(hm);
    drawTable(entries);
    loadIndicators(from, to);
    loadAlerts(); loadCustomAlerts(); // V4.2: alertas calculados na hora ao abrir o Histórico
    if ($('#subst-hist')) loadSubstHist(from, to);
    loadMediaHistory();
  }
  function drawLifeChart(entries, from, days) {
    lastChart = [entries, from, days];
    const box = $('#lifechart'); box.innerHTML = '';
    // Ocupa toda a largura disponível; em períodos longos, rola na horizontal (começando em hoje)
    const minSlot = days <= 30 ? 7 : days <= 90 ? 5 : 3;
    const W = Math.max(box.clientWidth || 640, 320, days * 3 * minSlot), H = 300, padL = 48, padR = 12, padT = 14, padB = 44;
    const svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, 'aria-hidden': 'true', focusable: 'false' }, box);
    const y = (v) => padT + (3 - v) * (H - padT - padB) / 6;
    const slots = days * 3; const x = (i) => padL + (i + 0.5) * (W - padL - padR) / slots;
    for (let v = -3; v <= 3; v++) {
      el('rect', { x: padL, y: y(v) - (H - padT - padB) / 12, width: W - padL - padR, height: (H - padT - padB) / 6, class: 'band lvl' + (v + 3) }, svg);
      const t = el('text', { x: padL - 8, y: y(v) + 4, 'text-anchor': 'end', class: 'axis' }, svg); t.textContent = sign(v);
    }
    el('line', { x1: padL, x2: W - padR, y1: y(0), y2: y(0), class: 'zero' }, svg);
    const step = days <= 14 ? 1 : days <= 30 ? 3 : days <= 90 ? 7 : 30;
    for (let d = 0; d < days; d += step) {
      const t = el('text', { x: x(d * 3 + 1), y: H - 14, 'text-anchor': 'middle', class: 'axis' }, svg);
      t.textContent = fmtBR(addDays(from, d)).slice(0, 5);
    }
    const pts = entries.map((e) => {
      const di = Math.round((new Date(e.entry_date + 'T12:00:00Z') - new Date(from + 'T12:00:00Z')) / 864e5);
      return { e, cx: x(di * 3 + PERIOD_ORDER.indexOf(e.period)), cy: y(+e.mood) };
    }).sort((a, b) => a.cx - b.cx);
    if (pts.length) {
      el('path', { d: pts.map((p, i) => (i ? 'L' : 'M') + p.cx.toFixed(1) + ' ' + p.cy.toFixed(1)).join(' '), class: 'line' }, svg);
      pts.forEach((p) => { const c = el('circle', { cx: p.cx, cy: p.cy, r: days > 90 ? 2.5 : 4.5, class: 'dot lvl' + (+p.e.mood + 3) }, svg); el('title', {}, c).textContent = fmtBR(p.e.entry_date) + ' ' + B.periods[p.e.period] + ': ' + LV[+p.e.mood].label; });
    } else {
      const t = el('text', { x: W / 2, y: H / 2, 'text-anchor': 'middle', class: 'axis' }, svg); t.textContent = 'Sem registros neste período';
    }
    const avg = entries.length ? (entries.reduce((s, e) => s + +e.mood, 0) / entries.length).toFixed(1) : null;
    box.setAttribute('aria-label', 'Gráfico de humor dos últimos ' + days + ' dias: ' + entries.length + ' registros' + (avg !== null ? ', média ' + fmtNum(avg, 1) : '') + '. Veja a tabela abaixo para os dados completos.');
    box.scrollLeft = box.scrollWidth; // mostra o dia de hoje (fim do gráfico)
  }
  const MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
  const WD_FULL = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
  // Calendário: visualmente cada semana é uma coluna (dom → sáb, de cima para baixo), com o mês no topo.
  // Para leitores de tela é uma tabela: cada semana é uma linha (rowheader "Semana de dd/mm") e os dias da semana são as colunas.
  function drawHeatmap(entries) {
    const byDay = {};
    entries.forEach((e) => { (byDay[e.entry_date] = byDay[e.entry_date] || []).push(+e.mood); });
    const to = todayISO(); const weeks = 18;
    const lastSun = addDays(to, -new Date(to + 'T12:00:00Z').getUTCDay());
    const start = addDays(lastSun, -7 * (weeks - 1));
    let html = '<div class="hm-scroll" tabindex="0" role="region" aria-label="Calendário de humor (role para ver semanas anteriores)"><div class="hm-table" role="table" aria-label="Calendário de humor das últimas ' + weeks + ' semanas">';
    html += '<div role="row"><span role="columnheader" class="hm-corner"><span class="sr-only">Semana</span></span>' + ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'].map((d, i) => '<span role="columnheader" class="hm-wd" aria-label="' + WD_FULL[i] + '">' + d + '</span>').join('') + '</div>';
    let prevMonth = -1;
    for (let w = 0; w < weeks; w++) {
      const ws = addDays(start, w * 7); const mo = +ws.slice(5, 7) - 1;
      const showMonth = mo !== prevMonth; prevMonth = mo;
      html += '<div role="row"><span role="rowheader" class="hm-month">' + (showMonth ? '<span aria-hidden="true">' + MONTHS[mo] + '</span>' : '') + '<span class="sr-only">Semana de ' + fmtBR(ws) + '</span></span>';
      for (let i = 0; i < 7; i++) {
        const d = addDays(ws, i); const v = byDay[d];
        if (d > to) { html += '<span role="cell" class="hm-cell future"><span class="sr-only">—</span></span>'; continue; }
        if (!v) { html += '<span role="cell" class="hm-cell none" title="' + fmtBR(d) + ': sem registro"><span class="sr-only">' + fmtBR(d) + ': sem registro</span></span>'; continue; }
        const avg = v.reduce((a, b) => a + b, 0) / v.length; const r = Math.round(avg);
        const lab = fmtBR(d) + ': média ' + fmtNum(avg, 1) + ' (' + LV[r].label + '), ' + plural(v.length, 'registro', 'registros');
        html += '<span role="cell" class="hm-cell lvl' + (r + 3) + '" title="' + esc(lab) + '"><span aria-hidden="true">' + (r > 0 ? '▲' : r < 0 ? '▼' : '') + '</span><span class="sr-only">' + esc(lab) + '</span></span>';
      }
      html += '</div>';
    }
    $('#heatmap').innerHTML = html + '</div></div>';
    const sc = $('#heatmap .hm-scroll'); sc.scrollLeft = sc.scrollWidth;
  }
  function drawTable(entries) {
    if (!entries.length) { $('#history-table').innerHTML = '<p class="muted">Sem registros.</p>'; return; }
    const rows = entries.slice().reverse().map((e) => '<tr><td>' + fmtBR(e.entry_date) + '</td><td>' + esc(B.periods[e.period]) + '</td><td><span class="chip lvl' + (+e.mood + 3) + '"><span aria-hidden="true">' + LV[+e.mood].icon + '</span> ' + sign(+e.mood) + '</span> ' + esc(LV[+e.mood].label) + '</td><td>' + (e.sleep_hours !== null ? fmtNum(e.sleep_hours, 1) + ' h' : '—') + '</td><td>' + (e.medication_taken === null ? '—' : +e.medication_taken ? 'Sim' : 'Não') + '</td><td>' + esc(e.tags || '') + (e.note ? '<br><small>' + esc(e.note) + '</small>' : '') + '</td></tr>').join('');
    $('#history-table').innerHTML = '<table><caption class="sr-only">Registros de humor</caption><thead><tr><th scope="col">Data</th><th scope="col">Período</th><th scope="col">Humor</th><th scope="col">Sono</th><th scope="col">Medicação</th><th scope="col">Notas</th></tr></thead><tbody>' + rows + '</tbody></table>';
  }
  async function loadMediaHistory() {
    const ul = $('#media-history');
    try {
      const { media } = await api('/api/media.php');
      ul.innerHTML = media.length ? media.map((m) => {
        const src = '/api/media.php?id=' + m.id; const when = m.entry_date ? fmtBR(m.entry_date) + ' · ' + esc(B.periods[m.period]) : esc(m.created_at);
        const player = m.kind === 'audio' ? '<audio controls preload="none" src="' + src + '"></audio>' : m.kind === 'video' ? '<video controls preload="none" playsinline src="' + src + '"></video>' : '<img src="' + src + '" alt="Imagem anexada em ' + when + '" loading="lazy">';
        return '<li><div><strong>' + when + '</strong> <span class="muted">' + esc(m.original_name || '') + '</span></div>' + player + '<div><a class="btn btn-sm" href="' + src + '" download>Baixar</a> <button type="button" class="btn btn-ghost btn-sm" data-del-media="' + m.id + '">Excluir</button></div></li>';
      }).join('') : '<li class="muted">Nenhum registro detalhado ainda.</li>';
    } catch { ul.innerHTML = '<li class="muted">Não foi possível carregar.</li>'; }
  }
  $('#media-history').addEventListener('click', async (e) => {
    const id = e.target.dataset.delMedia; if (!id || !(await askConfirm({ text: 'Excluir este arquivo definitivamente?', ok: 'Apagar', danger: true }))) return;
    await api('/api/media.php?id=' + id, { method: 'DELETE' }); toast('Arquivo excluído.'); loadMediaHistory();
  });

  /* ---------- Relatórios / destinatários ---------- */
  rTo.set(todayISO()); rFrom.set(addDays(todayISO(), -29));
  $('#form-print').addEventListener('submit', (e) => {
    const a = rFrom.validate(), b = rTo.validate();
    if (!a || !b) { e.preventDefault(); (a ? $('#r-to-br') : $('#r-from-br')).focus(); }
  });
  const FREQ = { diaria: 'diária', semanal: 'semanal', mensal: 'mensal', personalizada: 'personalizada' };
  const WD = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
  $('#rc-freq').addEventListener('change', (e) => {
    $('#rc-weekday-f').classList.toggle('hidden', e.target.value !== 'semanal');
    $('#rc-interval-f').classList.toggle('hidden', e.target.value !== 'personalizada');
  });
  async function loadRecipients() {
    const ul = $('#recipients');
    try {
      const { recipients } = await api('/api/recipients.php');
      ul.innerHTML = recipients.length ? recipients.map((r) => {
        // V4.2: convite recusado/não enviado não aparece mais como "aguardando confirmação"
        const bad = !r.confirmed_at && (r.invite_status === 'recusado' || r.invite_status === 'falhou');
        const st = r.unsubscribed_at ? '<span class="badge warn">cancelado pelo destinatário</span>' : r.confirmed_at ? '<span class="badge ok"><span aria-hidden="true">✓</span> confirmado</span>'
          : bad ? '<span class="badge warn">' + (r.invite_status === 'recusado' ? 'convite recusado: confira o e-mail' : 'convite não enviado') + '</span> <button type="button" class="btn btn-ghost btn-sm" data-resend-rc="' + r.id + '" aria-label="Reenviar convite para ' + esc(r.name) + '">Reenviar convite</button>'
          : '<span class="badge"><span aria-hidden="true">⏳</span> aguardando confirmação</span>';
        const f = FREQ[r.frequency] + (r.frequency === 'semanal' ? ' (' + WD[r.weekday] + ')' : r.frequency === 'personalizada' ? ' (a cada ' + r.interval_days + ' dias)' : '');
        return '<li><div><strong>' + esc(r.name) + '</strong> · ' + esc(B.roles[r.role]) + '<br><span class="muted">' + esc(r.email) + ' · ' + f + (+r.include_notes ? ' · com anotações' : ' · sem anotações') + (+r.include_markers ? ' · com marcadores' : '') + (+r.include_sexuality ? ' · com resumo de sexualidade' : '') + (+r.include_substances ? ' · com resumo de substâncias' : '') + (+r.include_alerts ? ' · recebe alertas de apoio' : '') + (+r.include_custom_alerts ? ' · com contagem de alertas personalizados' : '') + '</span></div>' + st + ' <button type="button" class="btn btn-ghost btn-sm" data-del-rc="' + r.id + '" aria-label="Remover ' + esc(r.name) + '">Remover</button></li>';
      }).join('') : '<li class="muted">Nenhum destinatário cadastrado.</li>';
    } catch { ul.innerHTML = '<li class="muted">Não foi possível carregar.</li>'; }
  }
  $('#recipients').addEventListener('click', async (e) => {
    const rs = e.target.dataset.resendRc;
    if (rs) {
      try { const r = await api('/api/recipients.php?action=resend&id=' + rs, { method: 'POST', json: {} }); toast(r.email_enviado ? 'Convite reenviado.' : r.aviso, r.email_enviado ? 'ok' : 'warn'); } catch (err) { toast(err.message, 'err'); }
      return loadRecipients();
    }
    const id = e.target.dataset.delRc; if (!id || !(await askConfirm('Remover este destinatário? Ele não receberá mais relatórios.'))) return;
    await api('/api/recipients.php?id=' + id, { method: 'DELETE' }); toast('Destinatário removido.'); loadRecipients();
  });
  $('#form-recipient').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target; if (!f.reportValidity()) return;
    const d = Object.fromEntries(new FormData(f));
    d.include_markers = !!d.include_markers; d.include_sexuality = !!d.include_sexuality; d.include_substances = !!d.include_substances; d.include_alerts = !!d.include_alerts; d.include_custom_alerts = !!d.include_custom_alerts;
    const sens = [];
    if (d.include_alerts) sens.push('Alertas: no máximo uma vez por semana, um e-mail curto dizendo que você pode precisar de apoio — sem detalhes.');
    if (d.include_substances) sens.push('Substâncias: só a contagem de dias com uso por tipo.');
    if (d.include_sexuality) sens.push('Sexualidade: só a média do desejo e os dias com sinais de risco.');
    if (d.include_custom_alerts) sens.push('Alertas personalizados: só quantos foram atendidos no período.');
    if (sens.length && !(await askConfirm({ title: 'Compartilhar com ' + d.name + '?', text: d.name + ' vai receber, além do relatório de humor:', items: sens, ok: 'Confirmar e enviar convite' }))) return;
    try {
      const r = await api('/api/recipients.php', { method: 'POST', json: d });
      toast(r.email_enviado ? 'Convite enviado. O destinatário precisa confirmar pelo e-mail.' : (r.aviso || 'Destinatário salvo, mas não conseguimos enviar o convite agora. Tente novamente mais tarde.'), r.email_enviado ? 'ok' : 'warn');
      // V4.2: o formulário sempre volta ao estado inicial depois de adicionar (inclusive quando o convite falha)
      f.reset(); $$('input[type=text], input[type=email], input:not([type])', f).forEach((i) => { i.value = ''; });
      $('#rc-freq').dispatchEvent(new Event('change')); loadRecipients(); $('#rc-name').focus();
    } catch (err) { toast(err.message, 'err'); if (err.status !== 409 && err.status !== 422) loadRecipients(); }
  });

  /* ---------- Lembretes e push ---------- */
  $$('input[name=preset]').forEach((r) => r.addEventListener('change', () => {
    const custom = $('#pr-c').checked; $('#rm-time').classList.toggle('hidden', !custom);
    if (!custom) $('#rm-time').value = $('input[name=preset]:checked').value; else $('#rm-time').focus();
  }));
  const CH = B.vapid ? { push: 'notificação', email: 'e-mail', ambos: 'notificação e e-mail' } : { push: 'e-mail (push indisponível)', email: 'e-mail', ambos: 'e-mail (push indisponível)' };
  async function loadReminders() {
    const ul = $('#reminders');
    try {
      const { reminders } = await api('/api/reminders.php');
      ul.innerHTML = reminders.length ? reminders.map((r) => '<li><div><strong>' + esc(r.time_local) + '</strong> · ' + r.days.split(',').map((d) => WD[+d].slice(0, 3)).join(', ') + ' · ' + CH[r.channel] + '</div><button type="button" class="btn btn-ghost btn-sm" data-del-rm="' + r.id + '" aria-label="Remover lembrete das ' + esc(r.time_local) + '">Remover</button></li>').join('') : '<li class="muted">Nenhum lembrete.</li>';
    } catch { ul.innerHTML = '<li class="muted">Não foi possível carregar.</li>'; }
    refreshPushStatus();
  }
  $('#reminders').addEventListener('click', async (e) => {
    const id = e.target.dataset.delRm; if (!id) return;
    await api('/api/reminders.php?id=' + id, { method: 'DELETE' }); toast('Lembrete removido.'); loadReminders();
  });
  $('#form-reminder').addEventListener('submit', async (e) => {
    e.preventDefault();
    const days = $$('input[name=days]:checked').map((c) => +c.value);
    $('#rm-time').dispatchEvent(new Event('change'));
    if (!validTime($('#rm-time').value)) { toast('Informe o horário no formato 24 horas, por exemplo 21:00.', 'err'); $('#rm-time').classList.remove('hidden'); $('#rm-time').focus(); return; }
    try {
      await api('/api/reminders.php', { method: 'POST', json: { time_local: $('#rm-time').value, days, channel: $('#rm-channel').value } });
      toast('Lembrete adicionado.'); loadReminders();
      if (B.vapid && $('#rm-channel').value !== 'email') enablePush(true);
    } catch (err) { toast(err.message, 'err'); }
  });
  // Sem chaves VAPID no servidor o push fica oculto (B.vapid vazio)
  function pushSupported() { return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && B.vapid; }
  async function refreshPushStatus() {
    const s = $('#push-status'), b = $('#btn-push');
    if (!B.vapid || !b) return; // aviso fixo já exibido no HTML
    if (!pushSupported()) { s.textContent = B.vapid ? 'Este navegador não suporta notificações push. No iPhone, instale o app na tela de início (iOS 16.4+). Use lembretes por e-mail.' : 'Notificações push ainda não configuradas no servidor. Use lembretes por e-mail.'; s.classList.remove('hidden'); b.classList.add('hidden'); return; }
    const reg = await navigator.serviceWorker.ready; const sub = await reg.pushManager.getSubscription();
    b.textContent = sub ? 'Desativar notificações neste aparelho' : 'Ativar notificações neste aparelho';
    b.dataset.on = sub ? '1' : '';
    if (Notification.permission === 'denied') { s.textContent = 'As notificações estão bloqueadas nas configurações do navegador.'; s.classList.remove('hidden'); } else s.classList.add('hidden');
  }
  function urlB64ToUint8(b) { const p = '='.repeat((4 - b.length % 4) % 4); const r = atob((b + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from(r, (c) => c.charCodeAt(0)); }
  async function enablePush(silent) {
    if (!pushSupported()) return;
    try {
      const reg = await navigator.serviceWorker.ready;
      let sub = await reg.pushManager.getSubscription();
      if (!sub) {
        if (await Notification.requestPermission() !== 'granted') { if (!silent) toast('Permissão de notificação negada.', 'err'); return refreshPushStatus(); }
        sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToUint8(B.vapid) });
      }
      await api('/api/push.php', { method: 'POST', json: Object.assign(sub.toJSON(), { label: deviceLabel() }) });
      if (!silent) toast('Notificações ativadas neste aparelho.');
      loadPushDevices();
    } catch (e) { toast('Não foi possível ativar notificações: ' + e.message, 'err'); }
    refreshPushStatus();
  }
  if ($('#btn-push')) $('#btn-push').addEventListener('click', async (e) => {
    if (e.target.dataset.on) {
      const sub = await (await navigator.serviceWorker.ready).pushManager.getSubscription();
      if (sub) { await api('/api/push.php', { method: 'POST', json: { action: 'unsubscribe', endpoint: sub.endpoint } }); await sub.unsubscribe(); }
      toast('Notificações desativadas.'); refreshPushStatus();
    } else enablePush(false);
  });

  /* ---------- Conta ---------- */
  $('#form-settings').addEventListener('submit', async (e) => {
    e.preventDefault();
    const d = Object.fromEntries(new FormData(e.target)); d.extreme_alerts = !!d.extreme_alerts;
    try { await api('/api/account.php?action=settings', { method: 'POST', json: d }); toast('Preferências salvas.'); B.tz = d.timezone; $('#tz-label').textContent = $('#st-tz').selectedOptions[0].textContent; } catch (err) { toast(err.message, 'err'); }
  });
  const pwForm = $('#form-password');
  if (pwForm) pwForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const d = Object.fromEntries(new FormData(pwForm));
    try {
      const r = await api('/api/account.php?action=password', { method: 'POST', json: d });
      B.csrf = r.csrf; pwForm.reset(); toast('Senha alterada.'); announce('Senha alterada com sucesso.');
    } catch (err) { toast(err.message, 'err'); }
  });
  $('#form-delete').addEventListener('submit', async (e) => {
    e.preventDefault();
    if ($('#del-confirm').value !== 'EXCLUIR') { toast('Digite EXCLUIR para confirmar.', 'err'); $('#del-confirm').focus(); return; }
    if (!(await askConfirm({ text: 'Tem certeza? Todos os seus dados serão apagados para sempre.', ok: 'Apagar', danger: true }))) return;
    try { await api('/api/account.php?action=delete', { method: 'POST', json: { confirm: 'EXCLUIR' } }); localStorage.removeItem(Q_KEY); if (window.caches) (await caches.keys()).forEach((k) => caches.delete(k)); location.href = '/'; } catch (err) { toast(err.message, 'err'); }
  });


  /* ---------- V2: Meu dia, Sexualidade (privado), indicadores ---------- */
  const dayForm = $('#form-day'), sexForm = $('#form-sex');
  const LEVEL = { ok: ['Sem sinais', 'lv-ok'], atencao: ['Atenção', 'lv-att'], alerta: ['Alerta', 'lv-alert'] };
  function setRadio(f, name, v) { const r = f.querySelector('input[name=' + name + '][value="' + (v === null || v === undefined ? '' : v) + '"]'); if (r) r.checked = true; }
  function sleepCalc() {
    const b = $('#d-bed').value, w = $('#d-wake').value, out = $('#d-sleep-calc');
    if (!validTime(b) || !validTime(w)) { out.textContent = ''; return; }
    const m = ((+w.slice(0, 2) * 60 + +w.slice(3)) - (+b.slice(0, 2) * 60 + +b.slice(3)) + 1440) % 1440;
    out.textContent = 'Total: ' + fmtNum(m / 60, 1) + ' h de sono.';
  }
  $('#d-bed').addEventListener('change', sleepCalc); $('#d-wake').addEventListener('change', sleepCalc);
  $('#d-bed').addEventListener('input', sleepCalc); $('#d-wake').addEventListener('input', sleepCalc);
  let sexState = { enabled: false, locked: false };
  async function loadDay() {
    const d = entryDate.get(); if (!d || !navigator.onLine) return;
    $('#day-date-label').textContent = '(' + fmtBR(d) + ')';
    let r;
    try { r = await api('/api/day.php?from=' + d + '&to=' + d); } catch { return; }
    const m = r.markers[0] || {};
    dayForm.reset();
    ['bed_time', 'wake_time', 'sleep_quality', 'sun_minutes', 'sun_period', 'caffeine_cups', 'alcohol_units', 'substances', 'exercise_minutes', 'spending_amount', 'cycle_phase', 'stress_note'].forEach((k) => { const i = dayForm.elements[k]; if (i) i.value = m[k] == null ? '' : m[k]; });
    ['racing_thoughts', 'fast_speech', 'impulsive_spending', 'irritability_day', 'stress_level'].forEach((k) => setRadio(dayForm, k, m[k]));
    $$('input[name=dose]', dayForm).forEach((c) => { c.checked = !!(m.doses && m.doses[c.value]); });
    $$('input[name=sign]', dayForm).forEach((c) => { c.checked = (m.signs || []).includes(+c.value); });
    sleepCalc();
    sexState = r.sex;
    renderSubst(r);
    $('#sex-locked').classList.toggle('hidden', !r.sex.locked);
    $('#sex-body').classList.toggle('hidden', r.sex.locked);
    $('#sex-reveal').classList.toggle('hidden', r.sex.locked || !sexForm.classList.contains('blurred'));
    sexForm.reset();
    const s = r.sexuality[0];
    sexVersion = s && s.version != null ? +s.version : 0; // V4.3.1: 0 = ainda não há registro neste dia
    if (s) {
      setRadio(sexForm, 'libido', s.libido); setRadio(sexForm, 'different_from_usual', s.different_from_usual);
      const acts = (s.activities || '').split(',');
      $$('input[name=activities]', sexForm).forEach((c) => { c.checked = acts.includes(c.value); });
      sexForm.elements.frequency.value = s.frequency == null ? '' : s.frequency;
      sexForm.elements.online_minutes.value = s.online_minutes == null ? '' : s.online_minutes;
      sexForm.elements.note.value = s.note || '';
    }
    onlineToggle(); syncBlur();
    showWarning(r.warning, r.action_plan);
  }
  function showWarning(msg, plan) {
    const w = $('#early-warning');
    if (!msg || engineAlertActive) { w.classList.add('hidden'); w.textContent = ''; return; }
    w.innerHTML = '<strong>Um cuidado gentil 💛</strong><p>' + esc(msg) + '</p>' + (plan ? '<p><strong>Seu plano:</strong> ' + esc(plan) + '</p>' : '') + '<p class="small">Em crise ou risco imediato: CVV 188 (24 h) ou SAMU 192.</p>';
    w.classList.remove('hidden');
  }
  $('#entry-date').addEventListener('datechange', loadDay);
  dayForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const d = entryDate.get(); if (!d) return toast('Escolha a data no topo do formulário.', 'err');
    const p = { entry_date: d };
    $$('[data-mod]', dayForm).forEach((sec) => {
      if (sec.hidden) return;
      $$('input,select', sec).forEach((i) => {
        if (i.name === 'dose' || i.name === 'sign') return;
        if (i.type === 'radio') { if (i.checked) p[i.name] = i.value === '' ? null : +i.value; return; }
        p[i.name] = i.value === '' ? null : (i.type === 'number' ? +i.value : i.value);
      });
    });
    if (!$('[data-mod=medicacao]', dayForm).hidden) { p.doses = {}; $$('input[name=dose]', dayForm).forEach((c) => { p.doses[c.value] = c.checked ? 1 : 0; }); }
    if (!$('[data-mod=sinais]', dayForm).hidden) p.signs = $$('input[name=sign]:checked', dayForm).map((c) => +c.value);
    for (const k of ['bed_time', 'wake_time']) if (p[k] != null && !validTime(p[k])) { toast('Horário inválido. Use 24 horas, por exemplo 23:30.', 'err'); dayForm.elements[k].focus(); return; }
    try { const r = await api('/api/day.php', { method: 'POST', json: p }); toast('Seu dia foi salvo.'); announce('Marcadores do dia salvos.'); loadDay(); afterDataSave(r); } catch (err) { toast(err.message, 'err'); }
  });
  function syncBlur() { $('#sex-body').inert = sexForm.classList.contains('blurred'); }
  function onlineToggle() { $('#sx-online-f').classList.toggle('hidden', !$('input[name=activities][value=online]', sexForm).checked); }
  $$('input[name=activities]', sexForm).forEach((c) => c.addEventListener('change', () => {
    if (c.value === 'nenhuma' && c.checked) $$('input[name=activities]', sexForm).forEach((o) => { if (o !== c) o.checked = false; });
    else if (c.checked) $('input[name=activities][value=nenhuma]', sexForm).checked = false;
    onlineToggle();
  }));
  $('#sex-reveal').addEventListener('click', () => { sexForm.classList.remove('blurred'); syncBlur(); $('#sex-reveal').classList.add('hidden'); $('input[name=libido]', sexForm).focus(); });
  $('#sex-hide').addEventListener('click', async () => {
    sexForm.classList.add('blurred'); syncBlur(); $('#sex-reveal').classList.remove('hidden'); $('#sex-reveal').focus();
    if (sexState.has_pin) { try { await api('/api/day.php?action=lock', { method: 'POST', json: {} }); } catch { /* ok */ } loadDay(); }
  });
  $('#sex-unlock').addEventListener('click', async () => {
    await unlockPrivate('sex');
  });
  // Seções privadas (Sexualidade e Substâncias) com o MESMO comportamento: PIN desbloqueia as duas; ocultar/sair do app
  // volta a desfocar as que estão com "desfocar" ligado; mensagem de erro some quando o PIN dá certo.
  const PRIV = { sex: { form: sexForm, sync: syncBlur, first: () => $('input[name=libido]', sexForm) }, subst: { form: $('#form-subst'), sync: () => { $('#subst-body').inert = $('#form-subst').classList.contains('blurred'); }, first: () => $('#su-sub') } };
  const blurPref = { sex: sexForm.classList.contains('blurred'), subst: $('#form-subst').classList.contains('blurred') };
  async function unlockPrivate(kind) {
    const inp = $('#' + kind + '-pin');
    try {
      await api('/api/day.php?action=unlock', { method: 'POST', json: { pin: inp.value } });
      inp.value = ''; inp.removeAttribute('aria-invalid'); hideToast();
      Object.keys(PRIV).forEach((k) => { PRIV[k].form.classList.remove('blurred'); PRIV[k].sync(); $('#' + k + '-reveal').classList.add('hidden'); });
      await loadDay(); loadAlerts(); loadCustomAlerts();
      announce('Desbloqueado.');
      const f = PRIV[kind].first(); if (f) f.focus();
    } catch (err) { inp.setAttribute('aria-invalid', 'true'); toast(err.message, 'err'); inp.focus(); }
  }
  function reblurPrivate() {
    Object.keys(PRIV).forEach((k) => { if (!blurPref[k]) return; PRIV[k].form.classList.add('blurred'); PRIV[k].sync(); $('#' + k + '-reveal').classList.remove('hidden'); });
  }
  $('#sex-pin').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('#sex-unlock').click(); } });
  sexForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const d = entryDate.get(); if (!d) return toast('Escolha a data no topo do formulário.', 'err');
    const g = (n) => { const r = $('input[name=' + n + ']:checked', sexForm); return r && r.value !== '' ? +r.value : null; };
    const p = { entry_date: d, libido: g('libido'), different_from_usual: g('different_from_usual'), activities: $$('input[name=activities]:checked', sexForm).map((c) => c.value),
      frequency: sexForm.elements.frequency.value === '' ? null : +sexForm.elements.frequency.value, online_minutes: sexForm.elements.online_minutes.value === '' ? null : +sexForm.elements.online_minutes.value, note: sexForm.elements.note.value };
    if (sexVersion !== null) p.version = sexVersion;
    try { const r = await api('/api/day.php?action=sex', { method: 'POST', json: p }); sexVersion = r.version != null ? +r.version : null; toast('Salvo com privacidade.'); loadDay(); afterDataSave(r); } catch (err) { if (err.status === 423) loadDay(); toast(err.message, 'err'); if (isVersionConflict(err)) offerReload(err); }
  });
  let sexVersion = null;
  async function loadIndicators(from, to) {
    const box = $('#indicators');
    let r; try { r = await api('/api/day.php?from=' + from + '&to=' + to); } catch { box.innerHTML = '<p class="muted">Não foi possível carregar.</p>'; return; }
    const days = Object.keys(r.indicators).sort().reverse();
    if (!days.length) { box.innerHTML = '<p class="muted">Ainda não há dados suficientes neste período.</p>'; return; }
    box.innerHTML = '<table class="tbl"><caption class="sr-only">Indicador de sinais de (hipo)mania por dia</caption><thead><tr><th scope="col">Data</th><th scope="col">Nível</th><th scope="col">Pontos</th><th scope="col">Sono</th><th scope="col">Sinais</th></tr></thead><tbody>'
      + days.map((d) => { const x = r.indicators[d], l = LEVEL[x.level]; return '<tr><th scope="row">' + fmtBR(d) + '</th><td><span class="lvl-badge ' + l[1] + '">' + l[0] + (x.triad ? ' · tríade' : '') + '</span></td><td>' + x.score + '</td><td>' + (x.sleep == null ? '—' : fmtNum(x.sleep, 1) + ' h') + '</td><td>' + (esc(x.reasons.join(', ')) || '—') + '</td></tr>'; }).join('') + '</tbody></table>';
  }
  $('#link-modulos').addEventListener('click', (e) => { e.preventDefault(); selectTab($('#tab-conta'), true); setTimeout(() => { $('#conta-modulos').scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth' }); }, 50); });
  $('#sign-more').addEventListener('click', () => { const n = $$('#signs-list .field.hidden')[0]; if (n) { n.classList.remove('hidden'); $('input', n).focus(); } });
  $('#med-more').addEventListener('click', () => { const n = $$('#meds-list .med-row.hidden')[0]; if (n) { n.classList.remove('hidden'); $('input', n).focus(); } });
  $('#form-prefs').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target, p = { modules: {}, signs: [], meds: [] };
    $$('input[name^=mod_]', f).forEach((c) => { p.modules[c.name.slice(4)] = c.checked; });
    p.sex_blur = f.elements.sex_blur.checked; p.subst_blur = f.elements.subst_blur.checked; p.action_plan = f.elements.action_plan.value;
    $$('input[name^=sign_]', f).forEach((i) => { if (i.value.trim()) p.signs.push(i.value.trim()); });
    let badTime = null;
    $$('.med-row', f).forEach((row) => { const ti = $('.md-time', row); ti.dispatchEvent(new Event('change')); const n = $('.md-name', row).value.trim(), t = ti.value; if (n && t && !validTime(t)) badTime = ti; if (n && validTime(t)) p.meds.push({ id: row.dataset.id, name: n, dose: $('.md-dose', row).value.trim(), time: t }); });
    if (badTime) { toast('Horário da medicação inválido. Use 24 horas, por exemplo 08:00 ou 22:30.', 'err'); badTime.focus(); return; }
    try { await api('/api/account.php?action=prefs', { method: 'POST', json: p }); toast('Módulos salvos. Atualizando…'); setTimeout(() => { location.href = '/?aba=conta'; }, 800); } catch (err) { toast(err.message, 'err'); }
  });
  if ($('#sex-wipe')) $('#sex-wipe').addEventListener('click', async () => {
    if (!(await askConfirm({ text: 'Apagar para sempre todos os seus registros de sexualidade?', ok: 'Apagar', danger: true }))) return;
    try { await api('/api/day.php?action=sex_delete', { method: 'POST', json: { all: true } }); toast('Registros de sexualidade apagados.'); loadDay(); } catch (err) { toast(err.message, 'err'); }
  });
  $('#form-sexpin').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const r = await api('/api/account.php?action=sexpin', { method: 'POST', json: { atual: $('#sexpin-cur').value, pin: $('#sexpin-new').value } });
      e.target.reset(); $('#sexpin-state').textContent = r.has_pin ? '(PIN ativo)' : '(sem PIN)'; $('#sexpin-cur-f').classList.toggle('hidden', !r.has_pin);
      toast(r.has_pin ? 'PIN salvo.' : 'PIN removido.'); loadDay();
    } catch (err) { toast(err.message, 'err'); }
  });

  /* ---------- V3: Substâncias (privado, redução de danos) ---------- */
  const suForm = $('#form-subst'), SU = JSON.parse($('#su-sub').dataset.meta);
  const PER = { manha: 'manhã', tarde: 'tarde', noite: 'noite' };
  const REASON = { relaxar: 'relaxar', dormir: 'dormir', social: 'social', ansiedade: 'ansiedade', habito: 'hábito', outro: 'outro' };
  let suFavs = [];
  function suLabel(x) { const m = SU[x.substance]; return (x.substance === 'outra' && x.other_name ? x.other_name : m.label) + (x.form && m.forms[x.form] ? ' · ' + m.forms[x.form] : ''); }
  function suSync() {
    const k = $('#su-sub').value, m = SU[k], forms = m ? Object.entries(m.forms) : [];
    $('#su-form-f').classList.toggle('hidden', !forms.length);
    $('#su-form').innerHTML = '<option value="">—</option>' + forms.map(([v, l]) => '<option value="' + v + '">' + esc(l) + '</option>').join('');
    $('#su-other-f').classList.toggle('hidden', k !== 'outra');
    $('#su-unit').textContent = m ? '(' + m.unit + ')' : '';
  }
  $('#su-sub').addEventListener('change', suSync);
  function suSyncBlur() { $('#subst-body').inert = suForm.classList.contains('blurred'); }
  function renderSubst(r) {
    const st = r.subst; if (!st) return;
    $('#subst-locked').classList.toggle('hidden', !st.locked);
    $('#subst-body').classList.toggle('hidden', st.locked);
    $('#subst-reveal').classList.toggle('hidden', st.locked || !suForm.classList.contains('blurred'));
    suFavs = st.favorites || [];
    $('#subst-favs').innerHTML = suFavs.length ? '<span class="small">Registrar com 1 toque:</span> ' + suFavs.map((f, i) => '<button type="button" class="btn btn-sm" data-fav="' + i + '">＋ ' + esc(suLabel(f)) + (f.amount ? ' · ' + fmtNum(f.amount, f.amount % 1 ? 1 : 0) : '') + '</button>').join(' ') : '<span class="small">Dica: marque “Salvar como favorito” para registrar com 1 toque depois.</span>';
    const list = r.substances || [];
    $('#subst-list').innerHTML = list.length ? list.map((x) => '<li><div><strong>' + esc(suLabel(x)) + '</strong>' + (x.amount != null ? ' · ' + fmtNum(x.amount, x.amount % 1 ? 1 : 0) + ' ' + esc(SU[x.substance].unit) : '') + '<br><span class="muted">' + cap([x.period ? PER[x.period] : '', x.reason ? 'Motivo: ' + REASON[x.reason] : '', x.mood_effect != null ? 'Efeito: ' + sign(+x.mood_effect) : '', +x.above_usual === 1 ? 'acima do habitual' : ''].filter(Boolean).join(' · ')) + '</span></div><button type="button" class="btn btn-ghost btn-sm" data-su-del="' + x.id + '" aria-label="Remover ' + esc(suLabel(x)) + '">Remover</button></li>').join('') : '<li class="muted">Nenhum registro neste dia.</li>';
    suSyncBlur();
  }
  function curPeriod() { const p = $('input[name=period]:checked'); return p ? p.value : defaultPeriod(); }
  async function suAdd(p) {
    const d = entryDate.get(); if (!d) return toast('Escolha a data no topo do formulário.', 'err');
    try { const sr = await api('/api/day.php?action=subst_add', { method: 'POST', json: Object.assign({ entry_date: d }, p) }); toast('Registrado com privacidade.'); await loadDay(); afterDataSave(sr); if (p.substance === 'alcool') { const al = dayForm.elements.alcohol_units; announce('Substância registrada. Álcool em Meu dia: ' + (al.value || '0') + ' doses.'); al.classList.add('hl'); setTimeout(() => al.classList.remove('hl'), 1600); } else announce('Substância registrada.'); return true; } catch (err) { if (err.status === 423) loadDay(); toast(err.message, 'err'); return false; }
  }
  $('#subst-favs').addEventListener('click', (e) => { const i = e.target.closest('[data-fav]'); if (i) { const f = suFavs[+i.dataset.fav]; suAdd({ substance: f.substance, form: f.form, other_name: f.other_name, amount: f.amount, period: curPeriod() }); } });
  $('#subst-list').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-su-del]'); if (!b) return;
    try { await api('/api/day.php?action=subst_delete', { method: 'POST', json: { id: +b.dataset.suDel } }); toast('Registro removido.'); loadDay(); } catch (err) { toast(err.message, 'err'); }
  });
  suForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const g = (n) => { const r = $('input[name=' + n + ']:checked', suForm); return r && r.value !== '' ? r.value : null; };
    const p = { substance: $('#su-sub').value, form: $('#su-form').value || null, other_name: $('#su-other').value, amount: $('#su-amt').value === '' ? null : +$('#su-amt').value,
      reason: $('#su-reason').value || null, period: g('su_period') || curPeriod(), mood_effect: g('mood_effect') === null ? null : +g('mood_effect'), above_usual: g('above_usual') === null ? null : +g('above_usual') };
    if (!p.substance) { toast('Escolha a substância.', 'err'); $('#su-sub').focus(); return; }
    const fav = $('#su-fav').checked;
    if (!(await suAdd(p))) return;
    if (fav) { try { const r = await api('/api/day.php?action=subst_fav', { method: 'POST', json: { favorites: suFavs.concat([{ substance: p.substance, form: p.form, other_name: p.other_name, amount: p.amount }]) } }); suFavs = r.favorites; loadDay(); } catch (err) { toast(err.message, 'err'); } }
    suForm.reset(); suSync();
  });
  $('#subst-reveal').addEventListener('click', () => { suForm.classList.remove('blurred'); suSyncBlur(); $('#subst-reveal').classList.add('hidden'); $('#su-sub').focus(); });
  $('#subst-hide').addEventListener('click', async () => {
    suForm.classList.add('blurred'); suSyncBlur(); $('#subst-reveal').classList.remove('hidden'); $('#subst-reveal').focus();
    if (sexState.has_pin) { try { await api('/api/day.php?action=lock', { method: 'POST', json: {} }); } catch { /* ok */ } loadDay(); }
  });
  $('#subst-unlock').addEventListener('click', async () => {
    await unlockPrivate('subst');
  });
  $('#subst-pin').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('#subst-unlock').click(); } });
  if ($('#subst-wipe')) $('#subst-wipe').addEventListener('click', async () => {
    if (!(await askConfirm({ text: 'Apagar para sempre todos os seus registros de substâncias?', ok: 'Apagar', danger: true }))) return;
    try { await api('/api/day.php?action=subst_wipe', { method: 'POST', json: { confirm: true } }); toast('Registros de substâncias apagados.'); loadDay(); } catch (err) { toast(err.message, 'err'); }
  });
  async function loadSubstHist(from, to) {
    const box = $('#subst-hist');
    let r; try { r = await api('/api/day.php?resumo=1&from=' + from + '&to=' + to); } catch { box.innerHTML = '<p class="muted">Não foi possível carregar.</p>'; return; }
    if (r.subst.locked) { box.innerHTML = '<p class="muted">Seção protegida por PIN. Desbloqueie em Registrar → Substâncias.</p>'; return; }
    const rows = r.subst_summary;
    if (!rows.length) { box.innerHTML = '<p class="muted">Nenhum registro de substâncias neste período.</p>'; return; }
    const mv = (v) => (v == null ? '—' : fmtNum(v, 1));
    const bar = (v) => (v == null ? '' : '<span class="mbar" aria-hidden="true"><span data-w="' + Math.max(0, Math.min(100, Math.round((v + 3) / 6 * 100))) + '"></span></span>');
    box.innerHTML = '<table class="tbl"><caption class="sr-only">Humor médio por substância: dias com uso, sem uso e no dia seguinte</caption><thead><tr><th scope="col">Substância</th><th scope="col">Dias com uso</th><th scope="col">Total</th><th scope="col">Humor com uso</th><th scope="col">Humor sem uso</th><th scope="col">Humor no dia seguinte</th><th scope="col">Efeito percebido</th><th scope="col">Dias acima do habitual</th></tr></thead><tbody>'
      + rows.map((x) => '<tr><th scope="row">' + esc(x.label) + '</th><td>' + x.days + '</td><td>' + fmtNum(x.amount, x.amount % 1 ? 1 : 0) + ' <span class="muted">' + esc(x.unit) + '</span></td><td>' + mv(x.mood_with) + bar(x.mood_with) + '</td><td>' + mv(x.mood_without) + bar(x.mood_without) + '</td><td>' + mv(x.mood_next) + bar(x.mood_next) + '</td><td>' + (x.effect_avg == null ? '—' : fmtNum(x.effect_avg, 1)) + '</td><td>' + x.above_days + '</td></tr>').join('') + '</tbody></table><p class="small">Humor de −3 (depressão grave) a +3 (mania). A barra mostra a posição na escala.</p>';
    // largura via CSSOM (permitido pela CSP style-src 'self'; atributo style inline não é)
    $$('[data-w]', box).forEach((el) => { el.style.width = el.dataset.w + '%'; });
  }
  suSync();


  /* ---------- V4: alertas automáticos ---------- */
  let engineAlertActive = false;
  const SEV_CLASS = { 1: 'lv-att', 2: 'lv-alert', 3: 'lv-alert strong' };
  async function loadAlerts() {
    const ul = $('#alerts-list');
    let r;
    try { r = await api('/api/alerts.php'); } catch { ul.innerHTML = '<li class="muted">Não foi possível carregar.</li>'; ul.removeAttribute('aria-busy'); return; }
    ul.removeAttribute('aria-busy');
    const aiBtn = B.ai.configured && B.ai.consent;
    ul.innerHTML = r.alerts.length ? r.alerts.map((a) => '<li class="alert-item' + (a.active ? ' active' : '') + '"><div class="row between"><strong>' + (a.locked ? '<span aria-hidden="true">🔒</span> ' : '') + esc(a.title) + '</strong><span>' + (a.custom ? '<span class="badge custom">personalizado</span> ' : '') + '<span class="badge ' + SEV_CLASS[a.severity] + '">' + esc(a.severity_label) + '</span></span></div>'
      + '<p>' + esc(a.explanation) + '</p><p class="small muted">' + fmtBR(a.period_start) + ' a ' + fmtBR(a.period_end) + '</p>'
      + '<div class="row wrap" role="group" aria-label="Este alerta faz sentido?">'
      + (a.feedback ? '<span class="small">Você respondeu: ' + (a.feedback === 'sim' ? '👍 faz sentido' : '👎 não faz sentido') + '</span>'
        : '<button type="button" class="btn btn-sm" data-fb="1" data-id="' + a.id + '">👍 Faz sentido</button><button type="button" class="btn btn-sm btn-ghost" data-fb="0" data-id="' + a.id + '">👎 Não faz sentido</button>')
      + (aiBtn && !a.locked ? '<button type="button" class="btn btn-sm btn-ghost" data-explain="' + a.id + '">💭 Explicar com IA</button>' : '') + '</div><div class="ai-out hidden" id="ai-exp-' + a.id + '"></div></li>').join('')
      : '<li class="muted">Nenhum alerta. Quanto mais dias você registrar, melhor o app conhece o seu padrão.</li>';
    const act = r.alerts.filter((a) => a.active && !a.feedback).sort((x, y) => y.severity - x.severity)[0];
    engineAlertActive = !!r.alerts.find((a) => a.active);
    const bn = $('#alert-banner');
    if (act) {
      bn.innerHTML = '<strong>' + esc(act.title) + '</strong> · ' + esc(act.severity_label) + '<p>' + esc(act.explanation) + '</p>' + (r.plan ? '<p><strong>Seu plano:</strong> ' + esc(r.plan) + '</p>' : '') + '<p><a href="?aba=historico#alertas" id="alert-see">Ver e responder</a></p>';
      bn.className = 'notice alert-banner sev' + act.severity;
      $('#alert-see').addEventListener('click', (e) => { e.preventDefault(); selectTab($('#tab-historico'), true); setTimeout(() => $('#alertas').scrollIntoView(), 50); });
      if (engineAlertActive) { const w = $('#early-warning'); w.classList.add('hidden'); }
    } else bn.className = 'notice alert-banner hidden';
  }
  $('#alerts-list').addEventListener('click', async (e) => {
    const b = e.target.closest('button'); if (!b) return;
    if (b.dataset.fb !== undefined) {
      try { await api('/api/alerts.php?action=feedback', { method: 'POST', json: { id: +b.dataset.id, faz_sentido: b.dataset.fb === '1' } }); toast(b.dataset.fb === '1' ? 'Obrigado! Vamos manter essa sensibilidade.' : 'Obrigado! Esse tipo de alerta ficará menos sensível para você.'); loadAlerts(); } catch (err) { toast(err.message, 'err'); }
    } else if (b.dataset.explain) {
      const out = $('#ai-exp-' + b.dataset.explain); out.classList.remove('hidden'); out.textContent = 'Pensando…'; b.disabled = true;
      try { const r = await api('/api/ai.php?action=explicar', { method: 'POST', json: { id: +b.dataset.explain } }); out.innerHTML = aiHtml(r); } catch (err) { out.textContent = err.message; }
      b.disabled = false;
    }
  });

  /* ---------- V4.2: alertas personalizados ---------- */
  const CA = { data: null, editing: null };
  const caForm = $('#ca-form');
  function caMetric(k) { return (CA.data.metrics || []).find((m) => m.key === k); }
  function caMetricOptions(sel) {
    const groups = {};
    CA.data.metrics.forEach((m) => { (groups[m.group] = groups[m.group] || []).push(m); });
    return Object.keys(groups).map((g) => '<optgroup label="' + esc(g) + '">' + groups[g].map((m) => '<option value="' + m.key + '"' + (m.key === sel ? ' selected' : '') + '>' + esc(m.label) + (m.unit && m.unit !== 'R$' ? ' (' + esc(m.unit) + ')' : m.unit === 'R$' ? ' (R$)' : '') + '</option>').join('') + '</optgroup>').join('');
  }
  function caValueHint(m) {
    if (!m) return '';
    if (m.type === 'mood') return '−3 a +3';
    if (m.type === 'libido') return '−2 (bem menor) a +2 (bem maior)';
    if (m.type === 'scale') return '0 não · 1 leve · 2 moderado · 3 intenso';
    if (m.type === 'bool') return '1 sim · 0 não';
    return fmtNum(m.min, 0) + ' a ' + fmtNum(m.max, 0) + (m.unit ? ' ' + m.unit : '');
  }
  let caSeq = 0;
  function caCondRow(c) {
    c = Object.assign({ metric: 'mood_max', op: '>=', value: 2, mode: 'day', days: 7, min: 2 }, c || {});
    const i = ++caSeq, li = document.createElement('li'); li.className = 'ca-cond';
    const opts = Object.entries(CA.data.ops).map(([v, l]) => '<option value="' + esc(v) + '"' + (v === c.op ? ' selected' : '') + '>' + esc(l) + '</option>').join('');
    li.innerHTML = '<p class="ca-joiner" aria-hidden="true"></p><div class="ca-row">'
      + '<div class="field ca-metric"><label for="cam-' + i + '">O que medir</label><select id="cam-' + i + '" class="c-metric">' + caMetricOptions(c.metric) + '</select></div>'
      + '<div class="field ca-op"><label for="cao-' + i + '">Comparação</label><select id="cao-' + i + '" class="c-op">' + opts + '</select></div>'
      + '<div class="field ca-val"><label for="cav-' + i + '">Valor</label><input id="cav-' + i + '" class="c-val" type="number" inputmode="decimal" value="' + esc(c.value) + '" aria-describedby="cah-' + i + '"></div>'
      + '<div class="field ca-mode"><label for="cad-' + i + '">Quando</label><select id="cad-' + i + '" class="c-mode"><option value="day">No dia</option><option value="count">Em pelo menos K dos últimos N dias</option><option value="streak">Por N dias seguidos</option></select></div>'
      + '<div class="field ca-n c-k-f"><label for="cak-' + i + '">K (dias)</label><input id="cak-' + i + '" class="c-k" type="number" min="1" max="30" inputmode="numeric" value="' + esc(c.min) + '"></div>'
      + '<div class="field ca-n c-n-f"><label for="can-' + i + '">N (dias)</label><input id="can-' + i + '" class="c-n" type="number" min="2" max="30" inputmode="numeric" value="' + esc(c.days) + '"></div>'
      + '<button type="button" class="btn btn-ghost btn-sm c-del" aria-label="Remover esta condição">Remover</button></div><p class="small c-hint" id="cah-' + i + '"></p>';
    $('.c-mode', li).value = c.mode;
    const sync = () => {
      const m = caMetric($('.c-metric', li).value), v = $('.c-val', li), mode = $('.c-mode', li).value;
      if (m) { v.min = m.min; v.max = m.max; v.step = m.step; }
      $('.c-hint', li).textContent = 'Valores: ' + caValueHint(m) + (m && m.private ? ' · dado privado' : '');
      $('.c-k-f', li).classList.toggle('hidden', mode !== 'count');
      $('.c-n-f', li).classList.toggle('hidden', mode === 'day');
    };
    $('.c-metric', li).addEventListener('change', sync); $('.c-mode', li).addEventListener('change', sync);
    $('.c-del', li).addEventListener('click', () => { const prev = li.previousElementSibling || li.nextElementSibling; li.remove(); caJoiners(); if (prev) $('select', prev).focus(); else $('#ca-add-cond').focus(); });
    sync();
    return li;
  }
  function caJoiners() {
    const or = $('#ca-or').checked;
    $$('#ca-conds .ca-cond').forEach((li, i) => { $('.ca-joiner', li).textContent = i ? (or ? 'OU' : 'E') : 'Condição 1'; });
    $('#ca-add-cond').disabled = $$('#ca-conds .ca-cond').length >= 6;
  }
  function caCollect() {
    return {
      id: CA.editing || undefined, version: CA.editing && CA.version != null ? CA.version : undefined, name: $('#ca-name').value.trim(), logic: $('#ca-or').checked ? 'or' : 'and',
      severity: +$('#ca-sev').value, cooldown_days: +$('#ca-cool').value || 3, notify_push: $('#ca-push').checked, notify_email: $('#ca-email').checked,
      template: $('#ca-tpl').value || null,
      conditions: $$('#ca-conds .ca-cond').map((li) => ({ metric: $('.c-metric', li).value, op: $('.c-op', li).value, value: $('.c-val', li).value, mode: $('.c-mode', li).value, days: +$('.c-n', li).value, min: +$('.c-k', li).value })),
    };
  }
  function caOpen(rule) {
    CA.editing = rule && rule.id ? rule.id : null;
    CA.version = rule && rule.id && rule.version != null ? +rule.version : null;
    $('#ca-form-title').textContent = CA.editing ? 'Editar alerta' : 'Novo alerta';
    $('#ca-name').value = rule ? rule.name : '';
    $(rule && rule.logic === 'or' ? '#ca-or' : '#ca-and').checked = true;
    $('#ca-sev').value = rule ? String(rule.severity || 1) : '1';
    $('#ca-cool').value = rule ? (rule.cooldown_days || 3) : 3;
    $('#ca-push').checked = !!(rule && rule.notify_push); $('#ca-email').checked = !!(rule && rule.notify_email);
    $('#ca-push').disabled = !CA.data.push; $('#ca-email').disabled = !CA.data.email;
    $('#ca-tpl').value = (rule && rule.template) || '';
    const box = $('#ca-conds'); box.innerHTML = '';
    ((rule && rule.conditions && rule.conditions.length) ? rule.conditions : [null]).forEach((c) => box.appendChild(caCondRow(c)));
    caJoiners();
    $('#ca-preview').classList.add('hidden');
    caForm.classList.remove('hidden');
    $('#ca-name').focus();
  }
  function caClose() { caForm.classList.add('hidden'); CA.editing = null; $('#ca-new').focus(); }
  async function loadCustomAlerts() {
    const ul = $('#ca-list'); if (!ul) return;
    let r; try { r = await api('/api/custom_alerts.php'); } catch { ul.innerHTML = '<li class="muted">Não foi possível carregar.</li>'; ul.removeAttribute('aria-busy'); return; }
    CA.data = r; ul.removeAttribute('aria-busy');
    $('#ca-tpl').innerHTML = '<option value="">Montar do zero</option>' + r.templates.map((t) => '<option value="' + t.key + '">' + esc(t.name) + (t.private ? ' (privado)' : '') + '</option>').join('');
    ul.innerHTML = r.rules.length ? r.rules.map((x) => '<li class="ca-item' + (x.active ? '' : ' paused') + '"><div><strong>' + (x.private ? '<span aria-hidden="true">🔒</span> ' : '') + esc(x.name) + '</strong>' + (x.active ? '' : ' <span class="badge">pausado</span>')
      + '<span class="ca-desc">' + esc(x.description) + '</span>'
      + (x.locked ? '' : '<span class="ca-desc">' + esc([x.severity === 2 ? 'Alerta' : 'Atenção', 'no app' + (x.notify_push ? ', notificação' : '') + (x.notify_email ? ', e-mail' : ''), 'espera ' + plural(x.cooldown_days, 'dia', 'dias') + ' entre avisos', x.last_fired_on ? 'último aviso em ' + fmtBR(x.last_fired_on) : 'ainda não avisou'].join(' · ')) + '</span>') + '</div>'
      + (x.locked ? '<span class="small">Desbloqueie em Registrar → Sexualidade ou Substâncias.</span>'
        : '<div class="row wrap" role="group" aria-label="Ações do alerta ' + esc(x.name) + '"><button type="button" class="btn btn-sm" data-ca-edit="' + x.id + '">✎ Editar</button><button type="button" class="btn btn-sm btn-ghost" data-ca-prev="' + x.id + '">Prévia</button><button type="button" class="btn btn-sm btn-ghost" data-ca-toggle="' + x.id + '" data-on="' + (x.active ? '1' : '') + '">' + (x.active ? 'Pausar' : 'Retomar') + '</button><button type="button" class="btn btn-sm btn-danger-ghost" data-ca-del="' + x.id + '">Excluir</button></div>')
      + '<div class="ca-out hidden" id="ca-out-' + x.id + '" role="status"></div></li>').join('')
      : '<li class="muted">Você ainda não criou alertas. Toque em “Novo alerta” e comece por um modelo pronto, se quiser.</li>';
  }
  function caPreviewHtml(p) {
    const list = (d) => '<ul class="ca-dates">' + d.slice(-30).map((x) => '<li>' + fmtBR(x) + '</li>').join('') + '</ul>';
    const cool = +p.cooldown_days || 1;
    const dup = p.duplicate_of ? '<p class="notice warn">Você já tem um alerta com exatamente estas condições: <strong>' + esc(p.duplicate_of.name) + '</strong>. Não é possível salvar outro igual.</p>' : '';
    if (!p.matched.length) return dup + '<p>Nos últimos ' + p.dias + ' dias (' + fmtBR(p.de) + ' a ' + fmtBR(p.ate) + '), as condições <strong>não foram atendidas nenhuma vez</strong>, então este alerta não teria avisado.</p><p class="small">' + esc(p.description) + '</p>';
    const skipped = p.skipped || [];
    const rows = p.matched.slice(-60).map((d) => {
      const sk = skipped.find((x) => x.day === d);
      return '<tr><td>' + fmtBR(d) + '</td><td>' + (sk ? 'Não avisaria: ainda dentro do intervalo do aviso de ' + fmtBR(sk.after) + ' (até ' + fmtBR(sk.until) + ')' : '<strong>Avisaria</strong>') + '</td></tr>';
    }).join('');
    return dup + '<p>Nos últimos ' + p.dias + ' dias, as condições foram atendidas em <strong>' + plural(p.matched.length, 'dia', 'dias') + '</strong>, mas você teria recebido <strong>' + plural(p.fired.length, 'aviso', 'avisos') + '</strong>:</p>' + list(p.fired)
      + '<p class="small">Por quê? O <strong>intervalo mínimo entre avisos</strong> deste alerta é de ' + plural(cool, 'dia', 'dias') + '. Depois de um aviso, os dias seguintes dentro desse intervalo não geram outro aviso, mesmo que as condições continuem valendo. A prévia simula o período como se o alerta já existisse. Na prática, o alerta só avalia hoje, ontem e os dias que você registrar (até 7 dias atrás), e o próximo aviso real também depende da data do último aviso que ele já deu.</p>'
      + '<details class="small"' + (skipped.length ? ' open' : '') + '><summary>Dias atendidos × dias que avisariam (' + p.matched.length + ' / ' + p.fired.length + ')</summary><table class="ca-prev-table"><thead><tr><th scope="col">Dia atendido</th><th scope="col">Resultado</th></tr></thead><tbody>' + rows + '</tbody></table></details>'
      + '<p class="small">' + esc(p.description) + '</p>';
  }
  if (caForm) {
    $('#ca-new').addEventListener('click', () => { if (!CA.data) return; caOpen(null); });
    $('#ca-cancel').addEventListener('click', caClose);
    caForm.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.preventDefault(); caClose(); } });
    $('#ca-and').addEventListener('change', caJoiners); $('#ca-or').addEventListener('change', caJoiners);
    $('#ca-add-cond').addEventListener('click', () => { const li = caCondRow(null); $('#ca-conds').appendChild(li); caJoiners(); $('select', li).focus(); });
    $('#ca-tpl').addEventListener('change', () => {
      const t = CA.data.templates.find((x) => x.key === $('#ca-tpl').value); if (!t) return;
      const keepId = CA.editing; caOpen(Object.assign({}, t, { template: t.key, id: keepId, version: CA.version })); $('#ca-tpl').focus();
      announce('Modelo aplicado: ' + t.name + '. ' + t.description);
    });
    $('#ca-try').addEventListener('click', async () => {
      const out = $('#ca-preview'); out.classList.remove('hidden'); out.textContent = 'Calculando…';
      try { out.innerHTML = caPreviewHtml(await api('/api/custom_alerts.php?action=preview', { method: 'POST', json: Object.assign(caCollect(), { name: $('#ca-name').value.trim() || 'Prévia' }) })); }
      catch (err) { out.textContent = err.message; if (err.status === 423) toast(err.message, 'err'); }
    });
    caForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!$('#ca-name').value.trim()) { toast('Dê um nome para o alerta.', 'err'); $('#ca-name').focus(); return; }
      const btn = $('#ca-save'); btn.disabled = true;
      try {
        const r = await api('/api/custom_alerts.php?action=save', { method: 'POST', json: caCollect() });
        toast(r.disparou ? 'Alerta salvo. Ele já foi atendido hoje: veja na lista de alertas acima.' : 'Alerta salvo. Vamos avisar quando as condições forem atendidas.', r.disparou ? 'warn' : 'ok');
        caClose(); await loadCustomAlerts(); loadAlerts();
      } catch (err) { toast(err.message, 'err'); if (isVersionConflict(err)) offerReload(err); } finally { btn.disabled = false; }
    });
    $('#ca-list').addEventListener('click', async (e) => {
      const b = e.target.closest('button'); if (!b) return;
      const id = +(b.dataset.caEdit || b.dataset.caPrev || b.dataset.caToggle || b.dataset.caDel || 0); if (!id) return;
      const rule = CA.data.rules.find((x) => x.id === id);
      try {
        if (b.dataset.caEdit) caOpen(rule);
        else if (b.dataset.caPrev) { const out = $('#ca-out-' + id); out.classList.remove('hidden'); out.textContent = 'Calculando…'; out.innerHTML = caPreviewHtml(await api('/api/custom_alerts.php?action=preview', { method: 'POST', json: { id } })); }
        else if (b.dataset.caToggle) { const on = !b.dataset.on; await api('/api/custom_alerts.php?action=toggle', { method: 'POST', json: { id, active: on } }); toast(on ? 'Alerta retomado.' : 'Alerta pausado. Ele não avisa até você retomar.'); await loadCustomAlerts(); const nb = $('[data-ca-toggle="' + id + '"]'); if (nb) nb.focus(); }
        else if (b.dataset.caDel) {
          if (!(await askConfirm({ text: 'Excluir o alerta “' + rule.name + '”? Os avisos que ele já deu continuam no Histórico.', ok: 'Excluir', danger: true }))) return;
          await api('/api/custom_alerts.php?action=delete', { method: 'POST', json: { id } }); toast('Alerta excluído.'); await loadCustomAlerts(); $('#ca-new').focus();
        }
      } catch (err) { toast(err.message, 'err'); }
    });
  }

  const fap = $('#form-alert-prefs');
  if (fap) fap.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = fap.elements;
    try { await api('/api/alerts.php?action=prefs', { method: 'POST', json: { engine: f.engine.checked, push: f.push.checked, email: f.email.checked, reset_tune: f.reset_tune.checked } }); f.reset_tune.checked = false; toast('Preferências de alertas salvas.'); } catch (err) { toast(err.message, 'err'); }
  });

  /* ---------- V4: push por aparelho, teste e convite no momento certo ---------- */
  function deviceLabel() {
    const ua = navigator.userAgent;
    const os = /iPhone|iPad/.test(ua) ? 'iPhone/iPad' : /Android/.test(ua) ? 'Android' : /Mac/.test(ua) ? 'Mac' : /Windows/.test(ua) ? 'Windows' : 'Linux';
    const br = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox' : /SamsungBrowser/.test(ua) ? 'Samsung' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'Navegador';
    return br + ' no ' + os + (matchMedia('(display-mode: standalone)').matches ? ' (app instalado)' : '');
  }
  async function currentEndpoint() { try { const s = await (await navigator.serviceWorker.ready).pushManager.getSubscription(); return s ? s.endpoint : ''; } catch { return ''; } }
  async function sha256hex(t) { const h = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(t)); return Array.from(new Uint8Array(h)).map((b) => b.toString(16).padStart(2, '0')).join(''); }
  async function loadPushDevices() {
    const ul = $('#push-devices'); if (!ul || !B.vapid) return;
    let r; try { r = await api('/api/push.php'); } catch { return; }
    const mine = await currentEndpoint(); const myHash = mine && crypto.subtle ? await sha256hex(mine) : '';
    ul.innerHTML = r.devices.length ? r.devices.map((d) => '<li><div><strong>' + esc(d.label || 'Aparelho') + '</strong>' + (d.endpoint_hash === myHash ? ' <span class="badge ok">este aparelho</span>' : '') + '<br><span class="muted small">desde ' + fmtBR(d.created_at.slice(0, 10)) + (d.last_ok_at ? ' · última entrega ' + fmtBR(d.last_ok_at.slice(0, 10)) : '') + '</span></div><button type="button" class="btn btn-ghost btn-sm" data-push-test="' + d.id + '">Testar</button><button type="button" class="btn btn-ghost btn-sm" data-push-del="' + d.id + '" aria-label="Remover ' + esc(d.label || 'aparelho') + '">Remover</button></li>').join('') : '<li class="muted">Nenhum aparelho recebendo notificações.</li>';
  }
  if ($('#push-devices')) $('#push-devices').addEventListener('click', async (e) => {
    const t = e.target.dataset.pushTest, d = e.target.dataset.pushDel;
    try {
      if (t) { await api('/api/push.php?action=test', { method: 'POST', json: { id: +t } }); toast('Notificação de teste enviada.'); }
      if (d) { await api('/api/push.php?action=delete', { method: 'POST', json: { id: +d } }); toast('Aparelho removido.'); loadPushDevices(); refreshPushStatus(); }
    } catch (err) { toast(err.message, 'err'); }
  });
  if ($('#btn-push-test')) $('#btn-push-test').addEventListener('click', async () => {
    try { const r = await api('/api/push.php?action=test', { method: 'POST', json: {} }); toast('Teste enviado para ' + plural(r.enviadas, 'aparelho', 'aparelhos') + '.'); } catch (err) { toast(err.message, 'err'); }
  });
  // Convite: só depois de alguns registros salvos (quando a pessoa já viu valor), nunca no primeiro acesso.
  function maybeOnboardPush() {
    const box = $('#push-onboard');
    if (!box || !pushSupported() || Notification.permission !== 'default') return;
    let n = 0; try { n = +(localStorage.getItem('bp-saves') || 0) + 1; localStorage.setItem('bp-saves', String(n)); if (localStorage.getItem('bp-push-ask') === 'no') return; } catch { return; }
    if (n >= 2) box.classList.remove('hidden');
  }
  if ($('#po-yes')) {
    $('#po-yes').addEventListener('click', async () => { $('#push-onboard').classList.add('hidden'); await enablePush(false); loadPushDevices(); });
    $('#po-no').addEventListener('click', () => { $('#push-onboard').classList.add('hidden'); try { localStorage.setItem('bp-push-ask', 'no'); } catch { /* sem armazenamento */ } });
  }

  /* ---------- V4: biometria (WebAuthn) e bloqueio ---------- */
  const PK = window.BPPasskey;
  async function loadPasskeys() {
    const ul = $('#wa-list'); if (!ul) return;
    if (!PK || !PK.supported()) { $('#wa-unsupported').classList.remove('hidden'); $('#form-wa-add').classList.add('hidden'); }
    let r; try { r = await api('/api/webauthn.php?action=list'); } catch { ul.innerHTML = '<li class="muted">Não foi possível carregar.</li>'; return; }
    B.lock.passkeys = r.credentials.length;
    ul.innerHTML = r.credentials.length ? r.credentials.map((c) => '<li><div><strong>' + esc(c.name) + '</strong><br><span class="muted small">' + (c.alg === -7 ? 'ES256' : 'RS256') + ' · cadastrado em ' + fmtBR(c.created_at.slice(0, 10)) + (c.last_used_at ? ' · último uso ' + fmtBR(c.last_used_at.slice(0, 10)) : '') + '</span></div><button type="button" class="btn btn-ghost btn-sm" data-wa-del="' + c.id + '" aria-label="Remover ' + esc(c.name) + '">Remover</button></li>').join('') : '<li class="muted">Nenhum aparelho com biometria cadastrado.</li>';
    addBioUnlockButtons();
  }
  if ($('#wa-list')) $('#wa-list').addEventListener('click', async (e) => {
    const id = e.target.dataset.waDel; if (!id || !(await askConfirm('Remover este aparelho? A biometria dele deixará de abrir o app.'))) return;
    try { await api('/api/webauthn.php?action=delete', { method: 'POST', json: { id: +id } }); toast('Aparelho removido.'); loadPasskeys(); } catch (err) { toast(err.message, 'err'); }
  });
  if ($('#form-wa-add')) $('#form-wa-add').addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = $('#wa-name').value.trim() || deviceLabel();
    let conf = '';
    for (let i = 0; i < 3; i++) {
      try { await PK.register(B.csrf, name, conf); toast('Biometria cadastrada neste aparelho.'); $('#wa-name').value = ''; loadPasskeys(); return; } catch (err) {
        if (!err.reauth) { toast(PK.msg(err), 'err'); return; }
        conf = await askSecret({ title: 'Confirme que é você', text: err.message, label: 'PIN ou senha da conta' });
        if (!conf) return;
      }
    }
    toast('Não foi possível confirmar. Tente de novo mais tarde.', 'err');
  });
  if ($('#form-lock')) $('#form-lock').addEventListener('submit', async (e) => {
    e.preventDefault();
    const l = { minutes: +$('#lk-min').value || 15, app: $('#lk-app').checked, on_hide: $('#lk-hide').checked };
    try { await api('/api/webauthn.php?action=settings', { method: 'POST', json: l }); Object.assign(B.lock, l); toast('Bloqueio salvo.'); } catch (err) { toast(err.message, 'err'); }
  });
  function addBioUnlockButtons() {
    if (!PK || !PK.supported() || !B.lock.passkeys) return;
    ['sex', 'subst'].forEach((k) => {
      const box = $('#' + k + '-locked'); if (!box || $('.bio-unlock', box)) return;
      const b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-primary bio-unlock'; b.textContent = 'Desbloquear com biometria';
      b.addEventListener('click', async () => { try { await PK.unlock(B.csrf); await loadDay(); toast('Desbloqueado.'); } catch (err) { toast(PK.msg(err), 'err'); } });
      box.insertBefore(b, box.querySelector('.row'));
    });
  }
  // Bloqueia de novo ao esconder a aba/app (sendBeacon funciona mesmo com a página indo para segundo plano)
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') reblurPrivate();
    if (!B.lock.has_factor && !B.lock.passkeys) return;
    if (document.visibilityState === 'hidden' && B.lock.on_hide) {
      const f = new FormData(); f.append('csrf', B.csrf);
      navigator.sendBeacon('/api/webauthn.php?action=lock', f);
      B.lockedByHide = true;
    } else if (document.visibilityState === 'visible' && B.lockedByHide) {
      B.lockedByHide = false;
      if (B.lock.app) location.reload(); else loadDay();
    }
  });

  /* ---------- Reflexões com IA (V4; provedor e chave próprios na V4.3) ---------- */
  function aiHtml(r) {
    if (r.crise) return '<div class="notice crisis-box" role="alert">' + esc(r.texto) + ' <a href="tel:188">Ligar 188</a> · <a href="tel:192">Ligar 192</a></div>';
    return '<div class="ai-text">' + esc(r.texto).replace(/\n/g, '<br>') + '</div><p class="small muted">' + esc(r.aviso || '') + '</p>';
  }
  let AIP = [];
  const fkey = $('#form-ai-key');
  const fai = $('#form-ai');
  if (fai) {
    fai.addEventListener('submit', async (e) => {
      e.preventDefault();
      const cats = $$('input[name=cat]:checked', fai).map((c) => c.value), on = $('#ai-consent').checked;
      if (on && cats.some((c) => ['sexualidade', 'substancias', 'anotacoes'].includes(c)) && !(await askConfirm('Você marcou categorias muito sensíveis (anotações, sexualidade ou substâncias). Elas serão enviadas ao provedor de IA que você escolheu a cada uso. Confirmar?'))) return;
      try { await api('/api/ai.php?action=consent', { method: 'POST', json: { consent: on, categories: cats } }); toast(on ? 'IA ativada. Recarregando…' : 'IA desativada.'); setTimeout(() => { location.href = '/?aba=conta'; }, 900); } catch (err) { toast(err.message, 'err'); }
    });
    api('/api/ai.php').then((r) => {
      AIP = r.providers || []; aiFillModels(true);
      $('#ai-usage').textContent = !r.ready ? 'Escolha um provedor e salve sua chave para usar a IA.'
        : r.using_operator_key ? 'Usando a chave compartilhada deste servidor (' + r.model + ') · uso neste mês: US$ ' + fmtNum(r.month_cost, 2) + ' de US$ ' + fmtNum(r.cap, 2) + '.'
          : 'Usando sua chave · modelo ' + r.model + '. O custo é cobrado pelo provedor, na sua conta.';
    }).catch(() => {});
  }
  // V4.3: provedor, modelo e chave próprios (a chave nunca volta do servidor)
  function aiFillModels(keepTyped) {
    if (!fkey) return;
    const p = AIP.find((x) => x.key === $('#ai-provider').value);
    const sel = $('#ai-model-sel'), typed = $('#ai-model');
    const models = p ? p.models : [];
    sel.innerHTML = models.map((m) => '<option value="' + esc(m) + '">' + esc(m) + '</option>').join('') + '<option value="">Outro (digite ao lado)</option>';
    if (keepTyped && typed.value && models.includes(typed.value)) sel.value = typed.value;
    else if (keepTyped && typed.value) sel.value = '';
    else { sel.value = models[0] || ''; typed.value = models[0] || ''; }
    $('#ai-base-wrap').classList.toggle('hidden', !(p && p.needs_url));
    const kl = $('#ai-keys-link'), tl = $('#ai-terms-link');
    kl.classList.toggle('hidden', !(p && p.keys_url)); if (p && p.keys_url) kl.href = p.keys_url;
    tl.classList.toggle('hidden', !(p && p.terms_url)); if (p && p.terms_url) tl.href = p.terms_url;
  }
  if (fkey) {
    const keyBody = () => ({ provider: $('#ai-provider').value, model: $('#ai-model').value.trim(), base_url: $('#ai-base').value.trim(), api_key: $('#ai-key').value.trim() });
    $('#ai-provider').addEventListener('change', () => aiFillModels(false));
    $('#ai-model-sel').addEventListener('change', () => { const v = $('#ai-model-sel').value; if (v) $('#ai-model').value = v; else $('#ai-model').focus(); });
    $('#ai-test').addEventListener('click', async () => {
      const out = $('#ai-test-out'), b = $('#ai-test'); out.textContent = 'Testando…'; b.disabled = true;
      try { const r = await api('/api/ai.php?action=key_test', { method: 'POST', json: keyBody() }); out.textContent = '✓ ' + r.mensagem; }
      catch (err) { out.textContent = err.message; } finally { b.disabled = false; }
    });
    fkey.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        const r = await api('/api/ai.php?action=key', { method: 'POST', json: keyBody() });
        $('#ai-key').value = ''; $('#ai-key').placeholder = 'Chave salva terminando em …' + r.choice.last4 + ' (deixe vazio para manter)';
        $('#ai-key-status').textContent = 'Chave salva: …' + r.choice.last4 + '.'; $('#ai-remove').classList.remove('hidden');
        toast('Provedor e chave salvos.'); announce('Provedor e chave salvos.');
      } catch (err) { toast(err.message, 'err'); }
    });
    $('#ai-remove').addEventListener('click', async () => {
      if (!(await askConfirm({ text: 'Remover a sua chave de IA deste servidor? Os recursos de IA param até você salvar outra.', ok: 'Remover', danger: true }))) return;
      try { await api('/api/ai.php?action=key_remove', { method: 'POST', json: {} }); $('#ai-key-status').textContent = 'Nenhuma chave salva.'; $('#ai-key').placeholder = 'Cole aqui a sua chave'; $('#ai-remove').classList.add('hidden'); toast('Chave removida.'); } catch (err) { toast(err.message, 'err'); }
    });
  }
  const iaCard = $('#ia-card');
  if (iaCard) {
    const chat = $('#ia-chat'), form = $('#ia-form'), input = $('#ia-input'), prop = $('#ia-proposal');
    let msgs = [], mode = '';
    const bubble = (role, html) => { const d = document.createElement('div'); d.className = 'bubble ' + role; d.innerHTML = html; chat.appendChild(d); chat.scrollTop = chat.scrollHeight; };
    async function ask(action, body) {
      chat.classList.remove('hidden'); bubble('assistant pending', 'Pensando…');
      try {
        const r = await api('/api/ai.php?action=' + action, { method: 'POST', json: body });
        chat.lastChild.remove(); bubble('assistant', aiHtml(r));
        if (r.crise) { form.classList.add('hidden'); return; }
        if (action === 'checkin') msgs.push({ role: 'assistant', content: r.texto });
        if (r.registro) showProposal(r.registro);
      } catch (err) { chat.lastChild.remove(); bubble('assistant', '<p class="field-err">' + esc(err.message) + '</p>'); }
    }
    function showProposal(e) {
      const per = e.period || PERIOD_ORDER[hourNow() < 12 ? 0 : hourNow() < 18 ? 1 : 2];
      const parts = ['Humor ' + sign(e.mood) + ' (' + esc(LV[e.mood].label) + ')', esc(B.periods[per])];
      if (e.sleep_hours != null) parts.push('sono ' + fmtNum(e.sleep_hours, 1) + ' h');
      ['energy', 'irritability', 'anxiety'].forEach((k) => { if (e[k] != null) parts.push({ energy: 'energia', irritability: 'irritabilidade', anxiety: 'ansiedade' }[k] + ' ' + e[k]); });
      prop.innerHTML = '<strong>Registro sugerido:</strong> ' + parts.join(' · ') + (e.note ? '<br><em>' + esc(e.note) + '</em>' : '') + '<p class="row"><button type="button" class="btn btn-primary" id="ia-save">Salvar este registro</button> <button type="button" class="btn btn-ghost" id="ia-edit">Ajustar no formulário</button></p>';
      prop.classList.remove('hidden');
      const payload = Object.assign({ entry_date: todayISO(), period: per, client_uuid: uuid() }, e);
      $('#ia-save').addEventListener('click', async () => { try { await api('/api/entries.php', { method: 'POST', json: payload }); toast('Registro salvo.'); prop.classList.add('hidden'); loadToday(); } catch (err) { toast(err.message, 'err'); } });
      $('#ia-edit').addEventListener('click', async () => {
        entryDate.set(todayISO()); setPeriod(per); await loadSlot(true);
        const r = $('#m' + (e.mood + 3)); if (r) r.checked = true; if (e.sleep_hours != null) $('#sleep').value = e.sleep_hours; if (e.note) $('#note').value = e.note; focusForm();
      });
    }
    $$('[data-ai]', iaCard).forEach((b) => b.addEventListener('click', () => {
      mode = b.dataset.ai; chat.innerHTML = ''; prop.classList.add('hidden'); msgs = [];
      if (mode === 'checkin') { form.classList.remove('hidden'); ask('checkin', { messages: [] }); input.focus(); }
      else if (mode === 'consulta') { form.classList.remove('hidden'); input.placeholder = 'Algo que você quer levar para a consulta? (opcional) — depois toque em Enviar'; chat.classList.remove('hidden'); }
      else { form.classList.add('hidden'); ask('resumo', {}); }
    }));
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const t = input.value.trim();
      if (mode === 'consulta') { if (t) bubble('user', esc(t)); input.value = ''; ask('consulta', { notes: t }); return; }
      if (!t) return;
      bubble('user', esc(t)); msgs.push({ role: 'user', content: t }); input.value = '';
      ask('checkin', { messages: msgs });
    });
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (SR) {
      const vb = $('#ia-voice'); vb.classList.remove('hidden');
      let rec = null;
      vb.addEventListener('click', () => {
        if (rec) { rec.stop(); return; }
        rec = new SR(); rec.lang = 'pt-BR'; rec.interimResults = false;
        rec.onresult = (ev) => { input.value = (input.value + ' ' + ev.results[0][0].transcript).trim(); };
        rec.onend = () => { rec = null; vb.setAttribute('aria-pressed', 'false'); vb.textContent = '🎙 Falar'; };
        rec.onerror = () => { toast('Não consegui ouvir. Tente digitar.', 'warn'); };
        rec.start(); vb.setAttribute('aria-pressed', 'true'); vb.textContent = '■ Parar';
      });
    } else $('#ia-voice-note').classList.add('hidden');
  }

  /* ---------- Início ---------- */
  const aba = new URLSearchParams(location.search).get('aba');
  resetForm(); updateOnline(); loadToday(); loadDay(); loadAlerts(); loadPushDevices(); loadPasskeys();
  if (!aba || aba === 'historico') loadCustomAlerts();
  $('#tz-label').textContent = (($('#st-tz').selectedOptions[0] || {}).textContent || B.tz);
  const t0 = aba && $('#tab-' + aba); if (t0) selectTab(t0);
})();
