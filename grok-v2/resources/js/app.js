// Sem scripts inline: tudo aqui (CSP script-src 'self').
import htmx from 'htmx.org';
import Alpine from '@alpinejs/csp';

window.htmx = htmx;

// CSRF para requisicoes htmx
document.addEventListener('htmx:configRequest', (e) => {
  const t = document.querySelector('meta[name="csrf-token"]');
  if (t) e.detail.headers['X-CSRF-TOKEN'] = t.content;
});

// Escala segmentada: mostra o rotulo escolhido (o radio nativo guarda o valor).
Alpine.data('scalePicker', () => ({
  label: null,
  init() {
    const c = this.$el.querySelector('input:checked');
    this.label = c ? c.closest('label').querySelector('.scale-text').textContent.split('· ')[1] : 'nenhum';
  },
  pick(e) {
    this.label = e.target.closest('label').querySelector('.scale-text').textContent.split('· ')[1];
  },
}));

// Trava de periodo: data/periodo ficam fixos no formulario; so avisa se o relogio mudou.
Alpine.data('periodLock', () => ({
  changed: false,
  init() {
    const p = this.$el.dataset.period;
    const tick = () => {
      const h = new Date().getHours();
      const now = h >= 18 ? 'noite' : h >= 12 ? 'tarde' : 'manha';
      this.changed = now !== p;
    };
    tick();
    setInterval(tick, 60000);
  },
}));

window.Alpine = Alpine;
Alpine.start();

if ('serviceWorker' in navigator && import.meta.env.PROD) {
  navigator.serviceWorker.register('/sw.js', { scope: '/' });
}
