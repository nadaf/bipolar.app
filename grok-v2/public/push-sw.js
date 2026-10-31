// Importado pelo service worker gerado (workbox.config.cjs). Lembretes por Web Push.
// O texto e SEMPRE neutro e fixo aqui: mesmo que o servidor mande outra coisa, nada de saude aparece.
self.addEventListener('push', (event) => {
  event.waitUntil(self.registration.showNotification('Bipolar', {
    body: 'Hora do seu registro',
    icon: '/icons/icon-192.png',
    badge: '/icons/badge-96.png',
    tag: 'lembrete',
    renotify: false,
    data: { url: '/registrar' },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const alvo = new URL('/registrar', self.location.origin).href;
  event.waitUntil((async () => {
    const abertas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of abertas) {
      if (new URL(c.url).origin === self.location.origin && 'focus' in c) {
        await c.focus();
        if ('navigate' in c) return c.navigate(alvo);
        return undefined;
      }
    }
    return self.clients.openWindow(alvo);
  })());
});
