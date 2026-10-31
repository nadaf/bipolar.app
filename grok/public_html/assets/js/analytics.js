/* V5.2 — Google Analytics 4 com privacidade (LGPD).
 * - Nada é carregado nem enviado ao Google antes de a pessoa clicar em "Aceitar" (Consent Mode v2: tudo negado).
 * - Só nomes de eventos fixos e o parâmetro "bloco" (lista fechada). Nunca valores de humor, textos, nomes, e-mails,
 *   IDs, datas, pensamentos, sexualidade, substâncias, religiosidade. O módulo de pensamentos de morte não gera evento.
 * - page_location sem query string, sem números (IDs/datas) e sem fragmento. Sem sinais do Google nem de anúncios. */
(function () {
  'use strict';
  const meta = document.querySelector('meta[name="bp-ga4"]');
  if (!meta) return;
  const ID = meta.content;
  if (!/^G-[A-Z0-9]{4,16}$/.test(ID)) return;
  const KEY = 'bp-analytics-consent';
  const FIRST = 'bp-ga-first-record';
  /* V5.2.1: localStorage pode não existir ou lançar erro (cookies bloqueados, modo privado, WebView).
   * A escolha de consentimento também vai para um cookie primário "bp_consent" (só "granted"/"denied", 1 ano),
   * para que o aviso não volte a cada página quando o armazenamento local falhar. */
  const CK = 'bp_consent';
  const cookieGet = (n) => { try { const m = document.cookie.match(new RegExp('(?:^|;\\s*)' + n + '=([^;]*)')); return m ? decodeURIComponent(m[1]) : null; } catch { return null; } };
  const cookieSet = (n, v) => { try { document.cookie = n + '=' + encodeURIComponent(v) + '; Max-Age=31536000; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : ''); } catch { /* sem cookies */ } };
  const store = {
    get(k) { try { return window.localStorage.getItem(k); } catch { return null; } },
    set(k, v) { try { window.localStorage.setItem(k, v); return true; } catch { return false; } },
  };
  const VALID = (v) => (v === 'granted' || v === 'denied' ? v : null);
  const getConsent = () => VALID(store.get(KEY)) || VALID(cookieGet(CK));
  const setConsent = (v) => { store.set(KEY, v); cookieSet(CK, v); };

  /* Listas fechadas: qualquer coisa fora delas é descartada antes de sair do aparelho. */
  const EVENTS = new Set(['salvar_registro', 'salvar_e_continuar', 'salvar_tudo', 'salvar_dia', 'trocar_periodo', 'abrir_bloco',
    'ativar_alerta', 'desativar_alerta', 'adicionar_horario', 'remover_horario', 'exportar', 'tema_alternar',
    'convidar_profissional', 'revogar_compartilhamento', 'novo_registro', 'ocultar_revelar', 'salvar_modulo_privado',
    'abrir_relatorio', 'convidar_destinatario', 'salvar_preferencias', 'salvar_modulos', 'sign_up', 'login', 'first_record', 'page_view']);
  const BLOCKS = new Set(['humor', 'sono', 'sol', 'habitos', 'mente', 'estresse', 'alimentacao', 'medicacao', 'sinais', 'ciclo', 'privado']);
  /* Módulos sensíveis: nunca o nome, só "privado". Pensamentos de morte: nada. */
  const PRIVATE = new Set(['pensamentos', 'religiosidade', 'sexualidade', 'substancias']);
  const BLOCKED = new Set(['pensamentos_morte', 'morte', 'death']);
  const FORMATS = new Set(['csv', 'json', 'zip', 'pdf']);
  const SCREENS = { registrar: 'Registrar', historico: 'Histórico e alertas', relatorios: 'Relatórios e compartilhar', lembretes: 'Lembretes', conta: 'Configurações' };

  window.dataLayer = window.dataLayer || [];
  function gtag() { window.dataLayer.push(arguments); }
  gtag('consent', 'default', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied', functionality_storage: 'denied', personalization_storage: 'denied', security_storage: 'granted' });
  gtag('set', 'ads_data_redaction', true);
  gtag('set', 'url_passthrough', false);

  let started = false;
  const cleanPath = (p) => ('/' + String(p || '/').split(/[?#]/)[0].split('/').filter(Boolean).map((s) => (/\d/.test(s) && !/\.php$/.test(s) ? ':id' : s)).join('/')).slice(0, 80);
  const loc = (path) => location.origin + cleanPath(path);

  function bloco(v) {
    const b = String(v || '').toLowerCase();
    if (BLOCKED.has(b)) return null;
    if (PRIVATE.has(b)) return 'privado';
    return BLOCKS.has(b) ? b : undefined;
  }
  function send(name, params) {
    if (!started || !EVENTS.has(name)) return false;
    const out = {};
    if (params && params.bloco !== undefined) { const b = bloco(params.bloco); if (b === null) return false; if (b) out.bloco = b; }
    if (params && params.formato && FORMATS.has(params.formato)) out.formato = params.formato;
    if (params && params.page_location) { out.page_location = params.page_location; out.page_title = params.page_title; }
    gtag('event', name, out);
    return true;
  }
  function pageView(screen) {
    let path = location.pathname; let title = meta.dataset.page || 'Bipolar';
    if (screen && SCREENS[screen]) { path = '/app/' + screen; title = SCREENS[screen]; }
    send('page_view', { page_location: loc(path), page_title: title });
  }
  let screen = null;
  function start() {
    if (started) return;
    started = true;
    window['ga-disable-' + ID] = false;
    gtag('consent', 'update', { analytics_storage: 'granted' });
    gtag('js', new Date());
    gtag('config', ID, { send_page_view: false, allow_google_signals: false, allow_ad_personalization_signals: false, page_location: loc(location.pathname), page_referrer: '', page_title: meta.dataset.page || 'Bipolar', cookie_flags: 'SameSite=Lax;Secure' });
    const s = document.createElement('script');
    s.async = true; s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(ID);
    document.head.appendChild(s);
    const sel = document.querySelector('[role=tab][aria-selected=true]');
    pageView(sel ? sel.id.replace('tab-', '') : screen);
    if (meta.dataset.flash) send(meta.dataset.flash);
  }
  function stop() {
    window['ga-disable-' + ID] = true;
    if (started) gtag('consent', 'update', { analytics_storage: 'denied' });
    started = false;
    const host = location.hostname.split('.');
    document.cookie.split(';').map((c) => c.split('=')[0].trim()).filter((n) => /^_ga/.test(n)).forEach((n) => {
      for (let i = 0; i < host.length; i++) {
        const d = host.slice(i).join('.');
        document.cookie = n + '=; Max-Age=0; path=/; domain=.' + d;
      }
      document.cookie = n + '=; Max-Age=0; path=/';
    });
  }

  /* ---------- Aviso de consentimento ---------- */
  /* V5.2.1: esconder SEMPRE pelos dois caminhos (atributo hidden + classe is-hidden com display:none !important no CSS)
   * e antes de qualquer outra coisa — mesmo que o Google falhe/seja bloqueado, o aviso some. */
  const banner = document.getElementById('consent-banner');
  const root = document.documentElement;
  let opener = null;
  function hideBanner() {
    if (!banner) return;
    banner.hidden = true;
    banner.classList.add('is-hidden');
    banner.setAttribute('aria-hidden', 'true');
    root.classList.remove('ga-consent-open');
    root.style.removeProperty('--consent-h');
  }
  function showBanner() {
    if (!banner) return;
    if (!banner.classList.contains('ga-consent')) banner.classList.add('ga-consent'); // HTML antigo (class="consent")
    banner.hidden = false;
    banner.classList.remove('is-hidden');
    banner.removeAttribute('aria-hidden');
    root.classList.add('ga-consent-open');
    try { root.style.setProperty('--consent-h', Math.ceil(banner.getBoundingClientRect().height) + 'px'); } catch { /* ok */ }
  }
  function say(msg) { const l = document.getElementById('sr-live'); if (l) { l.textContent = ''; setTimeout(() => { l.textContent = msg; }, 30); } }
  function choose(v) {
    hideBanner();
    try {
      setConsent(v);
      if (v === 'granted') { start(); say('Estatísticas de uso ativadas. Obrigado!'); } else { stop(); say('Estatísticas de uso recusadas. Nada será enviado.'); }
      document.dispatchEvent(new CustomEvent('bp:consent', { detail: v }));
    } finally {
      hideBanner();
      if (opener && document.contains(opener)) opener.focus();
      opener = null;
    }
  }
  function openBanner(from) {
    if (!banner) return;
    opener = from || null;
    showBanner();
    if (from) { const a = document.getElementById('consent-accept'); if (a) a.focus(); }
  }
  if (banner) {
    banner.addEventListener('click', (e) => {
      const b = e.target.closest('#consent-accept, #consent-deny');
      if (b) { e.preventDefault(); choose(b.id === 'consent-accept' ? 'granted' : 'denied'); }
    });
    banner.addEventListener('keydown', (e) => { if (e.key === 'Escape' && getConsent()) { hideBanner(); if (opener) opener.focus(); } });
  }
  document.addEventListener('click', (e) => { const b = e.target.closest('[data-consent-open]'); if (b) openBanner(b); });
  window.bpConsentState = () => getConsent() || 'pendente';

  /* ---------- Eventos ---------- */
  document.addEventListener('bp:screen', (e) => { screen = e.detail; pageView(e.detail); });
  document.addEventListener('bp:entry-saved', () => { if (started && !store.get(FIRST)) { store.set(FIRST, '1'); send('first_record'); } });
  document.addEventListener('toggle', (e) => {
    const d = e.target;
    if (!(d instanceof HTMLDetailsElement) || !d.open || !d.dataset.mod) return;
    send('abrir_bloco', { bloco: d.dataset.mod });
  }, true);
  document.addEventListener('click', (e) => {
    if (!started) return;
    const t = e.target.closest('button, a, [data-ga]');
    if (!t || t.closest('#form-death, [data-ga-off]')) return;
    if (t.dataset.ga) { send(t.dataset.ga, { bloco: t.dataset.gaBloco, formato: t.dataset.export }); return; }
    if (t.dataset.export) send('exportar', { formato: t.dataset.export });
    else if (t.dataset.caToggle) send(t.dataset.on ? 'desativar_alerta' : 'ativar_alerta');
    else if (t.dataset.careRevoke) send('revogar_compartilhamento');
  }, true);
  /* Formulários: só o nome do formulário vira evento (nada do conteúdo). */
  const FORMS = { 'form-entry': 'salvar_registro', 'form-day': 'salvar_dia', 'form-care-invite': 'convidar_profissional', 'form-reminder': 'adicionar_horario', 'form-settings': 'salvar_preferencias', 'form-modules': 'salvar_modulos', 'form-sex': 'salvar_modulo_privado', 'form-subst': 'salvar_modulo_privado' };
  document.addEventListener('submit', (e) => { const n = FORMS[e.target.id]; if (n) send(n, n === 'salvar_modulo_privado' ? { bloco: 'privado' } : undefined); }, true);

  /* Debug/testes: últimos eventos aceitos (sem dados). */
  window.bpTrack = (name, params) => send(name, params);

  const c = getConsent();
  if (c) { setConsent(c); hideBanner(); } // sincroniza armazenamento e cookie
  if (c === 'granted') { try { start(); } catch { /* aviso continua escondido */ } } else if (!c) showBanner();
})();
