// Sem scripts inline: tudo aqui (CSP script-src 'self'). Alpine no build CSP (sem eval).
import htmx from 'htmx.org';
import Alpine from '@alpinejs/csp';

window.htmx = htmx;

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
const owner = () => document.querySelector('meta[name="owner"]')?.content || '';

document.addEventListener('htmx:configRequest', (e) => {
  e.detail.headers['X-CSRF-TOKEN'] = csrf();
});

const TEMAS = ['sistema', 'claro', 'escuro'];
const TEMA_LABEL = { sistema: 'Tema do aparelho', claro: 'Tema claro', escuro: 'Tema escuro' };

function aplicarTema(t) {
  const d = document.documentElement;
  const mq = window.matchMedia('(prefers-color-scheme: dark)');
  d.setAttribute('data-tema-ativo', t);
  d.classList.toggle('dark', t === 'escuro' || (t === 'sistema' && mq.matches));
}

Alpine.data('themeToggle', () => ({
  label: '',
  init() {
    this.label = TEMA_LABEL[document.documentElement.getAttribute('data-tema-ativo') || 'sistema'];
  },
  next() {
    const atual = document.documentElement.getAttribute('data-tema-ativo') || 'sistema';
    const t = TEMAS[(TEMAS.indexOf(atual) + 1) % TEMAS.length];
    aplicarTema(t);
    document.documentElement.setAttribute('data-tema', t);
    try { localStorage.setItem('tema', t); } catch (_) { /* modo privado */ }
    this.label = TEMA_LABEL[t];
    document.querySelectorAll('[x-data="themeToggle"]').forEach((el) => { if (el !== this.$el && el._x_dataStack) el._x_dataStack[0].label = this.label; });
    const url = this.$el.dataset.url;
    if (url) {
      fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf(), 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
        body: new URLSearchParams({ theme: t }), credentials: 'same-origin' }).catch(() => {});
    }
  },
}));

// Escala segmentada: mostra o rotulo escolhido (o radio nativo guarda o valor).
Alpine.data('scalePicker', () => ({
  label: null,
  init() {
    const c = this.$el.querySelector('input:checked');
    this.label = c ? c.closest('label').dataset.text : 'nenhum';
  },
  pick(e) {
    this.label = e.target.closest('label').dataset.text;
  },
}));

// Trava de periodo: data/periodo ficam fixos nos formularios. Se o relogio cruzar para outro periodo,
// pergunta (dialogo) se continua no periodo travado ou muda. Nunca muda sozinho.
const PERIODOS = { manha: 'Manhã', tarde: 'Tarde', noite: 'Noite' };
function periodoAgora(tz) {
  let h;
  try { h = parseInt(new Intl.DateTimeFormat('en-GB', { timeZone: tz, hour: '2-digit', hourCycle: 'h23' }).format(new Date()), 10); } catch (_) { h = new Date().getHours(); }
  return h >= 18 ? 'noite' : h >= 12 ? 'tarde' : 'manha';
}
function hojeEm(tz) {
  try { return new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()); } catch (_) { return ''; }
}

Alpine.data('periodLock', () => ({
  agora: '',
  travado: '',
  link: '#',
  init() {
    const { period, date, tz } = this.$el.dataset;
    this.travado = PERIODOS[period];
    let ultimo = periodoAgora(tz);
    this.timer = setInterval(() => {
      const p = periodoAgora(tz);
      if (p !== ultimo) {
        ultimo = p;
        if (p !== period) {
          this.agora = PERIODOS[p];
          this.link = `?data=${hojeEm(tz) || date}&periodo=${p}`;
          this.$refs.dlg.showModal();
        }
      }
    }, 30000);
  },
  ficar() {
    this.$refs.dlg.close();
  },
  destroy() { clearInterval(this.timer); },
}));

// Registrar: "Salvar e continuar", marcador de alterado, "Salvar tudo" com resumo e fila offline
// (so blocos NAO intimos; amarrada ao dono; enviada quando a internet volta).
const RESULTADO = { ok: 'salvo', conflito: 'alterado em outro aparelho', erro: 'revise os campos', bloqueado: 'precisa desbloquear', fila: 'guardado no aparelho', naoenviado: 'não enviado' };
const filaKey = () => `fila:${owner()}`;
const lerFila = () => { try { return JSON.parse(localStorage.getItem(filaKey()) || '[]'); } catch (_) { return []; } };
const gravarFila = (f) => { try { localStorage.setItem(filaKey(), JSON.stringify(f)); } catch (_) { /* cheio/privado */ } };

Alpine.data('registrar', () => ({
  resumo: '',
  filaMsg: '',
  ocupado: false,
  init() {
    const root = this.$el;
    const marcar = (e) => {
      const f = e.target.closest('form[data-bloco]');
      if (!f) return;
      f.dataset.dirty = '1';
      const s = document.getElementById(`s-${f.dataset.bloco}`);
      if (s) s.textContent = 'Alterado, ainda não salvo';
    };
    root.addEventListener('change', marcar);
    root.addEventListener('input', marcar);
    root.addEventListener('click', (e) => {
      const b = e.target.closest('button[data-continuar]');
      if (b && b.form) b.form.dataset.continuar = '1';
    });
    root.addEventListener('htmx:afterRequest', (e) => {
      const f = e.detail.elt.closest && e.detail.elt.closest('form[data-bloco]');
      if (!f) return;
      if (e.detail.xhr && e.detail.xhr.status >= 200 && e.detail.xhr.status < 300) {
        delete f.dataset.dirty;
        if (f.dataset.continuar === '1') this.proximo(f);
      }
      delete f.dataset.continuar;
    });
    root.addEventListener('htmx:sendError', (e) => {
      const f = e.detail.elt.closest && e.detail.elt.closest('form[data-bloco]');
      if (f) this.semConexao(f);
    });
    window.addEventListener('online', () => this.enviarFila());
    window.addEventListener('beforeunload', (e) => {
      if (root.querySelector('form[data-dirty]')) { e.preventDefault(); e.returnValue = ''; }
    });
    this.enviarFila();
  },
  proximo(f) {
    const atual = f.closest('details');
    let n = atual && atual.nextElementSibling;
    while (n && n.tagName !== 'DETAILS') n = n.nextElementSibling;
    if (atual) atual.open = false;
    if (n) {
      n.open = true;
      const s = n.querySelector('summary');
      s.focus();
      s.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    } else {
      this.$refs.salvarTudo?.focus();
    }
  },
  status(f, tipo, msg) {
    const alvo = f.querySelector('[data-status]');
    if (!alvo) return;
    alvo.replaceChildren();
    const p = document.createElement('p');
    p.className = tipo === 'fila' ? 'warn' : 'alert';
    p.dataset.result = tipo;
    p.textContent = msg;
    alvo.appendChild(p);
  },
  semConexao(f) {
    if (f.dataset.intimo === '1') {
      this.status(f, 'naoenviado', 'Não enviado: sem conexão. Este bloco é privado e não fica guardado no aparelho. Tente de novo quando a internet voltar.');
      return;
    }
    const corpo = new URLSearchParams(new FormData(f));
    corpo.delete('_token');
    const fila = lerFila().filter((i) => i.url !== f.getAttribute('hx-post') || i.chave !== f.dataset.chave);
    fila.push({ url: f.getAttribute('hx-post'), chave: f.dataset.chave, corpo: corpo.toString(), titulo: f.dataset.titulo, em: Date.now() });
    gravarFila(fila);
    delete f.dataset.dirty;
    this.status(f, 'fila', 'Sem conexão: guardado só neste aparelho. Enviamos sozinhos quando a internet voltar.');
    this.filaMsg = `${fila.length} bloco(s) aguardando internet.`;
    window.dispatchEvent(new CustomEvent('fila:mudou'));
  },
  async enviarFila() {
    if (!owner() || !navigator.onLine) return;
    const fila = lerFila().filter((i) => Date.now() - i.em < 7 * 864e5);
    if (!fila.length) { gravarFila([]); return; }
    const resto = [];
    const enviados = [];
    for (const i of fila) {
      try {
        const r = await fetch(i.url, { method: 'POST', credentials: 'same-origin', body: i.corpo,
          headers: { 'X-CSRF-TOKEN': csrf(), 'HX-Request': 'true', 'Content-Type': 'application/x-www-form-urlencoded' } });
        if (r.status === 419 || r.status === 401) { resto.push(i); continue; }
        enviados.push(`${i.titulo}: ${r.ok ? 'enviado' : r.status === 409 ? 'conflito (já havia outra versão)' : 'recusado'}`);
      } catch (_) { resto.push(i); }
    }
    gravarFila(resto);
    window.dispatchEvent(new CustomEvent('fila:mudou', { detail: { enviados: enviados.length } }));
    if (enviados.length) this.filaMsg = `Guardados no aparelho e agora enviados: ${enviados.join(' · ')}. Recarregue para ver os valores atuais.`;
  },
  async salvarTudo() {
    const forms = [...this.$root.querySelectorAll('form[data-bloco][data-dirty]')];
    if (!forms.length) { this.resumo = 'Nada novo para salvar. Tudo o que você marcou já está salvo.'; return; }
    this.ocupado = true;
    this.resumo = 'Salvando…';
    const res = [];
    for (const f of forms) {
      const alvo = f.querySelector('[data-status]');
      try {
        await htmx.ajax('POST', f.getAttribute('hx-post'), { source: f, target: alvo, swap: 'innerHTML' });
      } catch (_) { /* tratado abaixo */ }
      const r = alvo && alvo.querySelector('[data-result]');
      const tipo = r ? r.dataset.result : (navigator.onLine ? 'erro' : 'naoenviado');
      res.push(`${f.dataset.titulo}: ${RESULTADO[tipo] || tipo}`);
    }
    this.ocupado = false;
    this.resumo = `Resumo — ${res.join(' · ')}.`;
  },
}));

Alpine.data('imprimir', () => ({
  go() { window.print(); },
}));

Alpine.data('copiar', () => ({
  msg: '',
  async go() {
    try { await navigator.clipboard.writeText(this.$el.dataset.texto); this.msg = 'Copiado.'; } catch (_) { this.msg = 'Não deu para copiar; selecione o texto e copie.'; }
  },
}));

// Botao que so libera quando a pessoa digita a palavra de confirmacao (exclusao de conta).
Alpine.data('confirmaPalavra', () => ({
  ok: false,
  check(e) { this.ok = e.target.value.trim().toUpperCase() === this.$el.dataset.palavra; },
  get bloqueado() { return !this.ok; },
}));

// Formulario com confirmacao: sem JS envia direto; com JS abre <dialog> modal (Esc fecha, foco volta ao gatilho).
Alpine.data('confirmarEnvio', () => ({
  ok: false,
  pedir(e) {
    if (this.ok) return;
    e.preventDefault();
    this.$refs.dlg.showModal();
  },
  confirmar() { this.ok = true; this.$refs.dlg.close(); this.$el.closest('form').requestSubmit(); },
  cancelar() { this.$refs.dlg.close(); },
  voltar() { if (!this.ok) this.$refs.gatilho.focus(); },
}));

// Notificacoes deste aparelho (Web Push). Texto do aviso e fixo e neutro; nada de saude sai daqui.
const b64ParaBytes = (b64) => {
  const pad = '='.repeat((4 - (b64.length % 4)) % 4);
  const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(raw, (c) => c.charCodeAt(0));
};
Alpine.data('pushAparelho', () => ({
  msg: '',
  ativo: false,
  podeAtivar: false,
  ocupado: false,
  async init() {
    const { vapid } = this.$el.dataset;
    if (!vapid) { this.msg = 'O envio de notificações não está configurado neste servidor.'; return; }
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
      this.msg = 'Este navegador não recebe notificações. No iPhone/iPad, instale o app na Tela de Início e abra por ele.';
      return;
    }
    if (Notification.permission === 'denied') {
      this.msg = 'As notificações estão bloqueadas para este site. Para receber, libere nas configurações do navegador (ícone de cadeado ao lado do endereço) e volte aqui.';
      return;
    }
    const reg = await navigator.serviceWorker.getRegistration();
    const sub = reg ? await reg.pushManager.getSubscription() : null;
    this.marcar(!!sub);
  },
  marcar(on) {
    this.ativo = on;
    this.podeAtivar = !on;
    this.msg = on ? 'Notificações ligadas neste aparelho.' : 'Notificações desligadas neste aparelho.';
  },
  async ativar() {
    this.ocupado = true;
    try {
      const perm = await Notification.requestPermission();
      if (perm !== 'granted') {
        this.msg = perm === 'denied' ? 'Você não permitiu as notificações. Tudo bem: os lembretes ficam desligados neste aparelho.' : 'Nada mudou. Toque de novo quando quiser ativar.';
        return;
      }
      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ParaBytes(this.$el.dataset.vapid) });
      const r = await fetch(this.$el.dataset.url, { method: 'POST', credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': csrf(), 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(sub.toJSON()) });
      if (!r.ok) throw new Error(String(r.status));
      this.marcar(true);
    } catch (_) {
      this.msg = 'Não deu para ativar agora. Verifique a internet e tente de novo.';
    } finally { this.ocupado = false; }
  },
  async desativar() {
    this.ocupado = true;
    try {
      const reg = await navigator.serviceWorker.getRegistration();
      const sub = reg ? await reg.pushManager.getSubscription() : null;
      if (sub) {
        await fetch(this.$el.dataset.urlRemover, { method: 'POST', credentials: 'same-origin',
          headers: { 'X-CSRF-TOKEN': csrf(), 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ endpoint: sub.endpoint }) });
        await sub.unsubscribe();
      }
      this.marcar(false);
    } catch (_) {
      this.msg = 'Não deu para desativar agora. Tente de novo.';
    } finally { this.ocupado = false; }
  },
}));

window.Alpine = Alpine;
Alpine.start();

// PWA: aviso de versao nova (SW em espera) com botao "Atualizar" (Workbox ouve SKIP_WAITING).
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  const aviso = document.getElementById('sw-nova-versao');
  const mostrar = (sw) => {
    if (!aviso || !sw) return;
    aviso.hidden = false;
    aviso.querySelector('[data-sw-atualizar]').addEventListener('click', () => sw.postMessage({ type: 'SKIP_WAITING' }), { once: true });
  };
  navigator.serviceWorker.register('/sw.js', { scope: '/' }).then((reg) => {
    if (reg.waiting && navigator.serviceWorker.controller) mostrar(reg.waiting);
    reg.addEventListener('updatefound', () => {
      const novo = reg.installing;
      novo?.addEventListener('statechange', () => { if (novo.state === 'installed' && navigator.serviceWorker.controller) mostrar(novo); });
    });
  }).catch(() => {});
  let recarregou = false;
  navigator.serviceWorker.addEventListener('controllerchange', () => { if (!recarregou) { recarregou = true; window.location.reload(); } });
}

// Indicador de rede: offline mostra quantos registros estao guardados no aparelho; ao voltar, "Sincronizado".
{
  const el = document.getElementById('rede');
  let timer = null;
  const pendentes = () => (owner() ? lerFila().length : 0);
  const mostrar = (cls, txt) => { el.className = `${cls} rede`; el.textContent = txt; el.hidden = false; };
  const atualizar = (e) => {
    if (!el) return;
    clearTimeout(timer);
    const n = pendentes();
    if (!navigator.onLine) {
      mostrar('warn', n ? `Sem internet. ${n} registro(s) guardado(s) neste aparelho; enviamos quando a conexão voltar.` : 'Sem internet. O que você registrar fica guardado neste aparelho.');
      return;
    }
    const enviados = e?.detail?.enviados || 0;
    if (enviados) {
      mostrar('ok', `Sincronizado: ${enviados} registro(s) enviado(s).`);
      timer = setTimeout(() => { el.hidden = true; }, 6000);
    } else if (n) {
      mostrar('warn', `${n} registro(s) aguardando envio.`);
    } else if (e?.type === 'online') {
      mostrar('ok', 'Conexão de volta.');
      timer = setTimeout(() => { el.hidden = true; }, 4000);
    } else {
      el.hidden = true;
    }
  };
  if (el) {
    window.addEventListener('online', atualizar);
    window.addEventListener('offline', atualizar);
    window.addEventListener('fila:mudou', atualizar);
    atualizar();
  }
}

// PWA: instalar (beforeinstallprompt no Android/desktop; instrucoes manuais no iOS).
{
  const btn = document.querySelector('[data-pwa-instalar]');
  const feito = document.querySelector('[data-pwa-instalado]');
  const manual = document.querySelector('[data-pwa-manual]');
  const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (standalone && feito) { feito.hidden = false; if (manual) manual.hidden = true; }
  let adiado = null;
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    adiado = e;
    if (btn) btn.hidden = false;
  });
  btn?.addEventListener('click', async () => {
    if (!adiado) return;
    adiado.prompt();
    await adiado.userChoice.catch(() => null);
    adiado = null;
    btn.hidden = true;
  });
  window.addEventListener('appinstalled', () => { if (btn) btn.hidden = true; if (feito) feito.hidden = false; });
}
