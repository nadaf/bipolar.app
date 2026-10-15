/* V4.4: Conta → Verificação em duas etapas (TOTP + códigos de recuperação). JavaScript puro. */
(function () {
  'use strict';
  const card = document.getElementById('conta-mfa');
  const boot = document.getElementById('boot');
  if (!card || !boot) return;
  let csrf = JSON.parse(boot.textContent).csrf;
  document.addEventListener('bp:csrf', (e) => { csrf = e.detail; });
  const $ = (s) => document.getElementById(s);
  const say = (msg, type) => {
    let t = $('toast');
    if (!t) { t = document.createElement('div'); t.id = 'toast'; t.className = 'toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
    t.textContent = msg; t.dataset.type = type || 'ok'; t.classList.add('show');
    clearTimeout(t._h); t._h = setTimeout(() => t.classList.remove('show'), type === 'err' ? 7000 : 4000);
  };
  async function call(action, body) {
    const r = await fetch('/api/mfa.php?action=' + action, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body || {}) });
    let d = {};
    try { d = await r.json(); } catch { /* vazio */ }
    if (!r.ok) throw new Error(d.erro || d.mensagem || 'Erro ' + r.status);
    return d;
  }
  let codes = [];
  function showCodes(list) {
    codes = list;
    const ul = $('mfa-codes-list');
    ul.textContent = '';
    list.forEach((c) => { const li = document.createElement('li'); const code = document.createElement('code'); code.textContent = c; li.appendChild(code); ul.appendChild(li); });
    $('mfa-codes').classList.remove('hidden');
    $('mfa-codes').focus();
  }
  const start = $('form-mfa-start');
  if (start) start.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const d = await call('start', { senha: start.elements.senha.value });
      start.reset(); start.classList.add('hidden');
      $('mfa-qr').src = d.qr;
      $('mfa-secret').textContent = d.secret;
      $('mfa-account').textContent = d.account;
      $('mfa-setup').classList.remove('hidden');
      $('mfa-code').focus();
    } catch (err) { say(err.message, 'err'); }
  });
  const conf = $('form-mfa-confirm');
  if (conf) conf.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const d = await call('confirm', { codigo: conf.elements.codigo.value });
      csrf = d.csrf;
      document.dispatchEvent(new CustomEvent('bp:csrf', { detail: d.csrf }));
      $('mfa-setup').classList.add('hidden');
      $('mfa-status').innerHTML = '<strong>Ativa.</strong> Guarde os códigos de recuperação abaixo.';
      showCodes(d.recovery_codes);
      say('Verificação em duas etapas ativada.');
    } catch (err) { say(err.message, 'err'); }
  });
  const manage = $('form-mfa-manage');
  if (manage) manage.addEventListener('submit', async (e) => {
    e.preventDefault();
    const action = e.submitter && e.submitter.value === 'disable' ? 'disable' : 'regen';
    try {
      const d = await call(action, { senha: manage.elements.senha.value, codigo: manage.elements.codigo.value });
      manage.reset();
      if (action === 'disable') { say('Verificação em duas etapas desativada.'); setTimeout(() => location.reload(), 1200); return; }
      $('mfa-left').textContent = String(d.recovery_codes.length);
      showCodes(d.recovery_codes);
      say('Novos códigos gerados. Os anteriores deixaram de valer.');
    } catch (err) { say(err.message, 'err'); }
  });
  const text = () => 'Bipolar — códigos de recuperação da verificação em duas etapas\nCada código vale uma vez.\n\n' + codes.join('\n') + '\n';
  $('mfa-copy') && $('mfa-copy').addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(text()); say('Códigos copiados.'); } catch { say('Não foi possível copiar. Selecione e copie à mão.', 'err'); }
  });
  $('mfa-download') && $('mfa-download').addEventListener('click', () => {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([text()], { type: 'text/plain' }));
    a.download = 'bipolar-codigos-de-recuperacao.txt';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  });
  $('mfa-done') && $('mfa-done').addEventListener('click', () => { codes = []; location.reload(); });
})();
