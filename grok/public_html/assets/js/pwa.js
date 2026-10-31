(function () {
  'use strict';
  // Tema (V5.1): sem escolha salva segue o sistema (prefers-color-scheme) e acompanha a mudança ao vivo.
  // A escolha manual fica em localStorage 'bp-theme' e vale em todas as páginas.
  var root = document.documentElement, btn = document.getElementById('btn-theme');
  var mq = window.matchMedia ? matchMedia('(prefers-color-scheme: dark)') : null;
  function stored() { try { var t = localStorage.getItem('bp-theme'); return t === 'dark' || t === 'light' ? t : null; } catch { return null; } }
  function current() { return root.getAttribute('data-theme') || (mq && mq.matches ? 'dark' : 'light'); }
  function sync() {
    var dark = current() === 'dark';
    if (btn) { btn.setAttribute('aria-pressed', dark ? 'true' : 'false'); btn.title = dark ? 'Usar tema claro' : 'Usar tema escuro'; btn.setAttribute('aria-label', 'Tema escuro'); var ic = btn.querySelector('span'); if (ic) ic.textContent = dark ? '☾' : '☀'; }
    var metas = document.querySelectorAll('meta[name=theme-color]');
    if (root.getAttribute('data-theme')) for (var i = 0; i < metas.length; i++) metas[i].setAttribute('media', (metas[i].getAttribute('media') || '').indexOf('dark') >= 0 ? (dark ? 'all' : 'not all') : (dark ? 'not all' : 'all'));
  }
  var s0 = stored(); if (s0) root.setAttribute('data-theme', s0); else root.removeAttribute('data-theme');
  if (btn) btn.addEventListener('click', function () {
    var n = current() === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', n);
    try { localStorage.setItem('bp-theme', n); } catch { /* armazenamento indisponível */ }
    sync();
    var live = document.getElementById('sr-live'); if (live) live.textContent = n === 'dark' ? 'Tema escuro ativado' : 'Tema claro ativado';
  });
  if (mq) { var onSys = function () { if (!stored()) { root.removeAttribute('data-theme'); sync(); } }; if (mq.addEventListener) mq.addEventListener('change', onSys); else if (mq.addListener) mq.addListener(onSys); }
  // outra aba mudou o tema
  window.addEventListener('storage', function (e) { if (e.key === 'bp-theme') { var t = stored(); if (t) root.setAttribute('data-theme', t); else root.removeAttribute('data-theme'); sync(); } });
  sync();

  // Service worker
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {}); });
  }
  // Limpa cache ao sair
  document.querySelectorAll('form[action="/auth/logout.php"]').forEach(function (f) {
    // V5.1: apaga fila offline e cache ANTES de sair (a navegação só acontece depois)
    f.addEventListener('submit', function (ev) {
      if (f.dataset.cleaned === '1') return;
      ev.preventDefault();
      try { localStorage.removeItem('bp-queue'); } catch { /* sem armazenamento */ }
      var done = function () { f.dataset.cleaned = '1'; f.submit(); };
      var t = setTimeout(done, 1500);
      if (window.caches) caches.keys().then(function (ks) { return Promise.all(ks.map(function (k) { return caches.delete(k); })); }).catch(function () {}).then(function () { clearTimeout(t); done(); });
      else { clearTimeout(t); done(); }
    });
  });
  // Botão de instalação
  var deferred = null, ib = document.getElementById('btn-install');
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; if (ib) ib.classList.remove('hidden'); });
  if (ib) ib.addEventListener('click', function () {
    if (!deferred) return;
    deferred.prompt();
    deferred.userChoice.finally(function () { deferred = null; ib.classList.add('hidden'); });
  });
  window.addEventListener('appinstalled', function () { if (ib) ib.classList.add('hidden'); });
})();
