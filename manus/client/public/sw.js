const CACHE = 'bipolar-shell-v1';
self.addEventListener('install', event => { event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(['/', '/manifest.webmanifest', '/icons/icon-192.png', '/icons/icon-512.png']))); });
self.addEventListener('activate', event => { event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('bipolar-shell-') && key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/') || url.pathname.startsWith('/__')) return;
  if (url.pathname === '/app-config.js') { event.respondWith(fetch(event.request).then(response => { if (response.ok) caches.open(CACHE).then(cache => cache.put(event.request, response.clone())); return response; }).catch(() => caches.match(event.request))); return; }
  if (event.request.mode === 'navigate') {
    event.respondWith(fetch(event.request).then(response => { if (response.ok) caches.open(CACHE).then(cache => cache.put('/', response.clone())); return response; }).catch(() => caches.match('/')));
    return;
  }
  if (/^\/(assets|icons)\//.test(url.pathname) || url.pathname === '/manifest.webmanifest') event.respondWith(caches.match(event.request).then(cached => cached || fetch(event.request).then(response => { if (response.ok) caches.open(CACHE).then(cache => cache.put(event.request, response.clone())); return response; })));
});

self.addEventListener('push', event => {
  let payload = {};
  try { payload = event.data ? event.data.json() : {}; } catch { /* A notificação continua discreta mesmo com payload inválido. */ }
  const tag = typeof payload.tag === 'string' ? payload.tag.slice(0, 120) : 'diary-reminder';
  // O texto é fixo para não expor humor, notas ou diagnóstico na tela bloqueada.
  event.waitUntil(self.registration.showNotification('Um momento para você', {
    body: 'Se quiser, faça uma anotação no seu diário.',
    icon: '/icons/icon-192.png', badge: '/icons/icon-192.png', tag,
    data: { url: '/' }, silent: false
  }));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clients => {
    const client = clients.find(candidate => new URL(candidate.url).origin === self.location.origin);
    if (client) return client.navigate('/').then(() => client.focus());
    return self.clients.openWindow('/');
  }));
});
