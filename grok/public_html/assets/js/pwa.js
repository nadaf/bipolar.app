(function () {
  'use strict';
  // Tema
  var root = document.documentElement, btn = document.getElementById('btn-theme');
  function current() { return root.getAttribute('data-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); }
  function sync() { if (btn) { btn.setAttribute('aria-pressed', current() === 'dark' ? 'true' : 'false'); btn.title = current() === 'dark' ? 'Usar tema claro' : 'Usar tema escuro'; } }
  if (btn) btn.addEventListener('click', function () {
    var n = current() === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', n);
    try { localStorage.setItem('bp-theme', n); } catch { /* armazenamento indisponível */ }
    sync();
    var live = document.getElementById('sr-live'); if (live) live.textContent = n === 'dark' ? 'Tema escuro ativado' : 'Tema claro ativado';
  });
  sync();

  // Service worker
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {}); });
  }
  // Limpa cache ao sair
  document.querySelectorAll('form[action="/auth/logout.php"]').forEach(function (f) {
    f.addEventListener('submit', function () { if (window.caches) caches.keys().then(function (ks) { ks.forEach(function (k) { caches.delete(k); }); }); });
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
