/* Registro rápido (1 toque) a partir da notificação. Offline: guarda na mesma fila do app. */
(function () {
  'use strict';
  const box = document.getElementById('quick'); if (!box) return;
  const msg = document.getElementById('quick-msg');
  const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2));
  box.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-mood]'); if (!b) return;
    const p = { entry_date: box.dataset.date, period: box.dataset.period, mood: +b.dataset.mood, client_uuid: uuid() };
    box.querySelectorAll('[data-mood]').forEach((x) => { x.disabled = true; x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
    try {
      const r = await fetch('/api/entries.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': box.dataset.csrf, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(p) });
      const d = await r.json().catch(() => ({}));
      if (!r.ok) throw Object.assign(new Error(d.erro || d.mensagem || 'Erro ' + r.status), { status: r.status });
      msg.textContent = 'Salvo! Obrigado por registrar. ' + (d.alerta || '');
      document.getElementById('quick-more').textContent = 'Adicionar detalhes';
    } catch (err) {
      if (!err.status) {
        let kept = false;
        try {
          // V5.1: mesma fila do app, com dono (conta + sessão) e estado; itens de outro dono são descartados
          const k = 'bp-queue', owner = box.dataset.owner || '';
          let q = JSON.parse(localStorage.getItem(k) || '[]'); if (!Array.isArray(q)) q = [];
          q = q.filter((x) => x && x.queue_owner === owner && x.state === 'pending');
          q.push(Object.assign({}, p, { queue_owner: owner, state: 'pending' })); localStorage.setItem(k, JSON.stringify(q)); kept = !!owner;
        } catch { /* sem armazenamento */ }
        msg.textContent = kept ? 'Sem conexão: ainda não enviado. Guardado neste aparelho; será enviado quando você abrir o app on-line nesta conta.' : 'Sem conexão: não foi possível enviar nem guardar. Tente de novo quando a conexão voltar.';
        if (!kept) box.querySelectorAll('[data-mood]').forEach((x) => { x.disabled = false; });
      } else { msg.textContent = err.message; box.querySelectorAll('[data-mood]').forEach((x) => { x.disabled = false; }); }
    }
  });
})();
