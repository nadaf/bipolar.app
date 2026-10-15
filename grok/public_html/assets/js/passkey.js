/* Biometria do aparelho (WebAuthn/passkeys): login, desbloqueio e cadastro. JavaScript puro. */
(function () {
  'use strict';
  const b2u = (buf) => btoa(String.fromCharCode.apply(null, new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const u2b = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), (c) => c.charCodeAt(0));
  async function post(url, csrf, body) {
    const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body || {}) });
    let d = {};
    try { d = await r.json(); } catch { /* vazio */ }
    if (!r.ok) { const e = new Error(d.erro || d.mensagem || 'Erro ' + r.status); e.status = r.status; e.reauth = !!d.reauth; throw e; }
    return d;
  }
  const supported = () => !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
  function prepGet(pk) { pk.challenge = u2b(pk.challenge); (pk.allowCredentials || []).forEach((c) => { c.id = u2b(c.id); }); return pk; }
  function assertion(c) { return { rawId: b2u(c.rawId), clientDataJSON: b2u(c.response.clientDataJSON), authenticatorData: b2u(c.response.authenticatorData), signature: b2u(c.response.signature) }; }
  async function unlock(csrf) {
    const o = await post('/api/webauthn.php?action=auth_options', csrf);
    const c = await navigator.credentials.get({ publicKey: prepGet(o.publicKey) });
    return post('/api/webauthn.php?action=auth_verify', csrf, assertion(c));
  }
  async function login(csrf) {
    const o = await post('/api/webauthn.php?action=login_options', csrf);
    const c = await navigator.credentials.get({ publicKey: prepGet(o.publicKey) });
    return post('/api/webauthn.php?action=login_verify', csrf, assertion(c));
  }
  async function register(csrf, name, confirmacao) {
    const o = await post('/api/webauthn.php?action=reg_options', csrf, confirmacao ? { confirmacao } : {});
    const pk = o.publicKey;
    pk.challenge = u2b(pk.challenge); pk.user.id = u2b(pk.user.id);
    (pk.excludeCredentials || []).forEach((c) => { c.id = u2b(c.id); });
    const c = await navigator.credentials.create({ publicKey: pk });
    return post('/api/webauthn.php?action=reg_verify', csrf, { name, clientDataJSON: b2u(c.response.clientDataJSON), attestationObject: b2u(c.response.attestationObject) });
  }
  function msg(e) {
    if (e && e.name === 'NotAllowedError') return 'A biometria foi cancelada ou o tempo acabou. Tente de novo.';
    if (e && e.name === 'InvalidStateError') return 'Este aparelho já está cadastrado.';
    if (e && e.name === 'NotSupportedError') return 'Este aparelho ou navegador não oferece biometria compatível.';
    return (e && e.message) || 'Não foi possível usar a biometria.';
  }
  window.BPPasskey = { supported, unlock, login, register, msg, post };

  // Página de login: "Entrar com biometria"
  const lb = document.getElementById('btn-passkey-login');
  if (lb && supported()) {
    lb.classList.remove('hidden');
    lb.addEventListener('click', async () => {
      const out = document.getElementById('passkey-msg');
      out.textContent = '';
      try { await login(document.querySelector('input[name=csrf]').value); location.href = '/'; } catch (e) { out.textContent = msg(e); }
    });
  }
  // Tela de bloqueio do app
  const ls = document.getElementById('lock-screen');
  if (ls) {
    const csrf = ls.dataset.csrf, out = document.getElementById('lock-msg');
    const bio = document.getElementById('btn-unlock-bio');
    if (bio) {
      if (!supported()) bio.classList.add('hidden');
      bio.addEventListener('click', async () => { out.textContent = ''; try { await unlock(csrf); location.reload(); } catch (e) { out.textContent = msg(e); } });
    }
    const pf = document.getElementById('form-unlock-pin');
    if (pf) pf.addEventListener('submit', async (ev) => {
      ev.preventDefault(); out.textContent = '';
      try { await post('/api/day.php?action=unlock', csrf, { pin: document.getElementById('unlock-pin').value }); location.reload(); } catch (e) { out.textContent = e.message; }
    });
  }
})();
