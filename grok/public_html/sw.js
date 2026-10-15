/* Service worker — Bipolar */
const VERSION = 'bp-v10';
const SHELL = ['/', '/offline.html', '/assets/css/app.css?v=10', '/assets/js/app.js?v=10', '/assets/js/passkey.js?v=4', '/assets/js/mfa.js?v=1', '/assets/js/quick.js?v=2', '/assets/js/pwa.js?v=1', '/assets/js/theme.js?v=1',
  '/assets/icons/logo.svg', '/assets/icons/icon-192.png', '/manifest.webmanifest'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => Promise.allSettled(SHELL.map((u) => c.add(u)))).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== VERSION).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin) return;
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/auth/')) return; // nunca cachear dados privados
  if (req.mode === 'navigate') {
    // Rede primeiro; página inicial em cache se offline
    e.respondWith(fetch(req).then((res) => {
      if (url.pathname === '/' && res.ok) { const copy = res.clone(); caches.open(VERSION).then((c) => c.put('/', copy)); }
      return res;
    }).catch(() => caches.match('/').then((r) => r || caches.match('/offline.html'))));
    return;
  }
  e.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => {
    if (res.ok && url.pathname.startsWith('/assets/')) { const copy = res.clone(); caches.open(VERSION).then((c) => c.put(req, copy)); }
    return res;
  })));
});
// Push: payload criptografado (RFC 8291) com texto genérico; sem payload, notificação padrão.
// Nunca há dados de saúde no texto — ele aparece na tela bloqueada.
self.addEventListener('push', (e) => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch { d = {}; }
  const title = d.title || 'Hora do seu registro';
  const opts = {
    body: d.body || 'Como está seu humor agora? Leva menos de um minuto.',
    icon: '/assets/icons/icon-192.png', badge: '/assets/icons/favicon-32.png', tag: d.tag || 'lembrete-humor', lang: 'pt-BR', renotify: true,
    data: { url: d.url || '/registro-rapido.php' },
    actions: Array.isArray(d.actions) ? d.actions.slice(0, 2) : [],
  };
  e.waitUntil(self.registration.showNotification(title, opts));
});
function b64u(buf) { return btoa(String.fromCharCode.apply(null, new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
async function snooze() {
  const sub = await self.registration.pushManager.getSubscription();
  if (!sub) return;
  await fetch('/api/push.php?action=snooze', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: sub.endpoint, auth: b64u(sub.getKey('auth')), minutes: 30 }) });
}
self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  if (e.action === 'snooze') { e.waitUntil(snooze().catch(() => {})); return; }
  const target = new URL((e.notification.data && e.notification.data.url) || '/?aba=registrar', self.location.origin);
  if (target.origin !== self.location.origin) return;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((cs) => {
    for (const c of cs) { if ('navigate' in c && 'focus' in c) return c.navigate(target.href).then((w) => (w || c).focus()); }
    return self.clients.openWindow(target.href);
  }));
});
// Background Sync: pede para as páginas abertas enviarem a fila offline
self.addEventListener('sync', (e) => {
  if (e.tag === 'sync-entries') e.waitUntil(self.clients.matchAll().then((cs) => cs.forEach((c) => c.postMessage('sync-entries'))));
});
