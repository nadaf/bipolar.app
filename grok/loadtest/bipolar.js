// Teste de carga do Bipolar (k6) — https://k6.io
// NÃO rode contra o site real sem combinar a janela de teste. Veja README.md.
//
//   BASE_URL=https://bipolar.app.br CONFIRM_LIVE=sim BYPASS_TOKEN=... PASSWORD='...' ACCOUNTS=100 k6 run bipolar.js
//
import http from 'k6/http';
import { check, group, sleep, fail } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

const BASE = (__ENV.BASE_URL || '').replace(/\/$/, '');
if (!BASE) fail('Defina BASE_URL (ex.: http://127.0.0.1:8080)');
if (/bipolar\.app\.br/.test(BASE) && __ENV.CONFIRM_LIVE !== 'sim') fail('Alvo é o site real. Para confirmar, defina CONFIRM_LIVE=sim');
const PASSWORD = __ENV.PASSWORD || '';
if (PASSWORD.length < 12) fail('Defina PASSWORD (a mesma usada em tools/seed_loadtest.php, 12+ caracteres)');
const ACCOUNTS = parseInt(__ENV.ACCOUNTS || '100', 10);
const DOMAIN = __ENV.EMAIL_DOMAIN || 'bipolar.app.br';
const SIGNUP = __ENV.SIGNUP === '1'; // cria as contas pelo formulário de cadastro no setup() (precisa do BYPASS_TOKEN)
const LEVELS = (__ENV.STAGES || '5,10,25,50,100').split(',').map((n) => parseInt(n, 10));
const HOLD = __ENV.HOLD || '2m';
const RAMP = __ENV.RAMP || '30s';
const THINK_MIN = parseFloat(__ENV.THINK_MIN || '3');
const THINK_MAX = parseFloat(__ENV.THINK_MAX || '8');
const MAX_ERR = parseFloat(__ENV.MAX_ERROR_RATE || '0.05'); // 5%
const P95_MS = parseInt(__ENV.P95_MS || '5000', 10);       // 5 s

const stages = [];
for (const n of LEVELS) { stages.push({ duration: RAMP, target: n }); stages.push({ duration: HOLD, target: n }); }
stages.push({ duration: '20s', target: 0 });

export const options = {
  scenarios: { usuarios: { executor: 'ramping-vus', startVUs: 0, stages, gracefulRampDown: '20s', gracefulStop: '30s' } },
  thresholds: {
    // Para AUTOMATICAMENTE se taxa de erro > 5% ou p95 > 5 s (avaliado após 30 s de dados)
    http_req_failed: [{ threshold: `rate<${MAX_ERR}`, abortOnFail: true, delayAbortEval: '30s' }],
    http_req_duration: [{ threshold: `p(95)<${P95_MS}`, abortOnFail: true, delayAbortEval: '30s' }],
    checks: [{ threshold: `rate>${1 - MAX_ERR}`, abortOnFail: true, delayAbortEval: '30s' }],
  },
  noCookiesReset: true, // mantém a sessão de cada usuário virtual entre iterações
  insecureSkipTLSVerify: false,
  userAgent: 'bipolar-loadtest/1.0 (k6)',
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

const loginTime = new Trend('login_duracao', true);
const saveTime = new Trend('salvar_registro_duracao', true);
const appErrors = new Rate('erros_app');
const relogins = new Counter('relogins');

const BYPASS = __ENV.BYPASS_TOKEN || '';
function params(name, extra = {}) {
  const headers = Object.assign({}, extra.headers || {});
  if (BYPASS) headers['X-Loadtest-Bypass'] = BYPASS;
  return Object.assign({}, extra, { headers, tags: { name } });
}
const emailFor = (i) => `loadtest+${String(i).padStart(3, '0')}@${DOMAIN}`;
const formCsrf = (body) => { const m = /name="csrf" value="([a-f0-9]{64})"/.exec(body || ''); return m ? m[1] : null; };
const bootCsrf = (body) => { const m = /<script id="boot" type="application\/json">([\s\S]*?)<\/script>/.exec(body || ''); return m ? JSON.parse(m[1]).csrf : null; };
const think = () => sleep(THINK_MIN + Math.random() * (THINK_MAX - THINK_MIN));
function isoDate(offsetDays = 0) {
  // data no fuso de Cuiabá (UTC-4), como o app
  const d = new Date(Date.now() - 4 * 3600e3 + offsetDays * 864e5);
  return d.toISOString().slice(0, 10);
}

export function setup() {
  if (!SIGNUP) return {};
  if (!BYPASS) fail('SIGNUP=1 exige BYPASS_TOKEN (o cadastro tem limite de 5/h por IP)');
  let ok = 0;
  for (let i = 1; i <= ACCOUNTS; i++) {
    const jar = http.cookieJar(); jar.clear(BASE);
    const page = http.get(`${BASE}/conta/cadastro.php`, params('GET /conta/cadastro.php'));
    const csrf = formCsrf(page.body);
    const r = http.post(`${BASE}/conta/cadastro.php`, { csrf, nome: `Teste de carga ${i}`, email: emailFor(i), senha: PASSWORD, consentimento: '1' }, params('POST /conta/cadastro.php'));
    if (r.status === 200 || r.status === 302) ok++;
  }
  console.log(`setup: ${ok}/${ACCOUNTS} cadastros enviados (contas já existentes recebem resposta genérica)`);
  return {};
}

const state = { csrf: null };
function login() {
  const i = ((__VU - 1) % ACCOUNTS) + 1;
  http.cookieJar().clear(BASE);
  const t0 = Date.now();
  const home = http.get(`${BASE}/`, params('GET / (login)'));
  const csrf = formCsrf(home.body);
  if (!check(home, { 'página de login': (r) => r.status === 200 && !!csrf })) return false;
  const r = http.post(`${BASE}/conta/entrar.php`, { csrf, email: emailFor(i), senha: PASSWORD }, params('POST /conta/entrar.php'));
  state.csrf = bootCsrf(r.body);
  loginTime.add(Date.now() - t0);
  const ok = check(r, { 'login ok': () => !!state.csrf });
  appErrors.add(!ok);
  if (ok) {
    http.batch([
      ['GET', `${BASE}/assets/css/app.css?v=2`, null, params('GET asset')],
      ['GET', `${BASE}/assets/js/app.js?v=2`, null, params('GET asset')],
    ]);
  }
  return ok;
}
function apiGet(path, name) {
  const r = http.get(`${BASE}${path}`, params(name, { headers: { Accept: 'application/json' } }));
  if (r.status === 401) { state.csrf = null; relogins.add(1); }
  const ok = check(r, { [`${name} 200`]: (x) => x.status === 200 });
  appErrors.add(!ok);
  return r;
}

export default function () {
  if (!state.csrf && !login()) { sleep(5); return; }

  group('Hoje', () => {
    const page = http.get(`${BASE}/?aba=registrar`, params('GET /?aba=registrar'));
    const c = bootCsrf(page.body);
    if (c) state.csrf = c; else { state.csrf = null; return; }
    const t = isoDate();
    apiGet(`/api/entries.php?from=${t}&to=${t}`, 'GET /api/entries (hoje)');
  });
  if (!state.csrf) return;
  think();

  group('Salvar registros', () => {
    // manhã, tarde e noite (um a cada pausa), às vezes corrigindo o dia anterior
    for (const period of ['manha', 'tarde', 'noite']) {
      const date = Math.random() < 0.15 ? isoDate(-1) : isoDate();
      const body = JSON.stringify({
        entry_date: date, period, mood: Math.floor(Math.random() * 7) - 3,
        sleep_hours: period === 'manha' ? 5 + Math.floor(Math.random() * 8) / 2 : null,
        tags: Math.random() < 0.3 ? 'trabalho, teste de carga' : '', note: Math.random() < 0.2 ? 'Anotação de teste de carga' : '',
      });
      const r = http.post(`${BASE}/api/entries.php`, body, params('POST /api/entries', { headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': state.csrf } }));
      saveTime.add(r.timings.duration);
      if (r.status === 401 || r.status === 403) { state.csrf = null; relogins.add(1); return; }
      const ok = check(r, { 'registro salvo': (x) => x.status === 200 && x.json('ok') === true });
      appErrors.add(!ok);
      sleep(1 + Math.random() * 2);
    }
  });
  if (!state.csrf) return;
  think();

  group('Histórico', () => {
    http.get(`${BASE}/?aba=historico`, params('GET /?aba=historico'));
    const to = isoDate();
    apiGet(`/api/entries.php?from=${isoDate(-29)}&to=${to}`, 'GET /api/entries (30 dias)');
    apiGet(`/api/entries.php?from=${isoDate(-125)}&to=${to}`, 'GET /api/entries (calendário)');
    apiGet('/api/media.php', 'GET /api/media');
  });
  think();

  const dice = Math.random();
  if (dice < 0.10) {
    group('Exportar', () => {
      const r = http.get(`${BASE}/api/account.php?action=export&format=csv`, params('GET export CSV'));
      appErrors.add(!check(r, { 'CSV exportado': (x) => x.status === 200 && String(x.body).indexOf('data;período') >= 0 }));
    });
  } else if (dice < 0.18) {
    group('Exportar', () => { apiGet('/api/account.php?action=export&format=json', 'GET export JSON'); });
  } else if (dice < 0.30) {
    group('Relatório', () => {
      const r = http.get(`${BASE}/relatorio.php?de=${isoDate(-29)}&ate=${isoDate()}`, params('GET /relatorio.php'));
      appErrors.add(!check(r, { 'relatório 200': (x) => x.status === 200 }));
    });
  } else if (dice < 0.40) {
    group('Lembretes e destinatários', () => {
      apiGet('/api/reminders.php', 'GET /api/reminders');
      apiGet('/api/recipients.php', 'GET /api/recipients');
    });
  }
  think();
}

export function handleSummary(data) {
  const stamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
  const m = data.metrics;
  const pick = (k, s) => (m[k] && m[k].values[s] !== undefined ? m[k].values[s] : null);
  const lines = [
    `Teste de carga Bipolar — ${new Date().toISOString()} — alvo ${BASE}`,
    `Estágios: ${LEVELS.join(' → ')} usuários (${HOLD} cada, rampa ${RAMP})`,
    `Requisições: ${pick('http_reqs', 'count')} · taxa ${pick('http_reqs', 'rate')?.toFixed(1)}/s`,
    `Erros HTTP: ${((pick('http_req_failed', 'rate') || 0) * 100).toFixed(2)}% · checks ok: ${((pick('checks', 'rate') || 0) * 100).toFixed(2)}%`,
    `Duração p95: ${pick('http_req_duration', 'p(95)')?.toFixed(0)} ms · p99: ${pick('http_req_duration', 'p(99)')?.toFixed(0)} ms · máx: ${pick('http_req_duration', 'max')?.toFixed(0)} ms`,
    `Login p95: ${pick('login_duracao', 'p(95)')?.toFixed(0)} ms · Salvar registro p95: ${pick('salvar_registro_duracao', 'p(95)')?.toFixed(0)} ms`,
    `Pico de usuários: ${pick('vus_max', 'max') ?? pick('vus', 'max')} · relogins: ${pick('relogins', 'count') || 0}`,
  ];
  const thr = Object.entries(m).filter(([, v]) => v.thresholds).map(([k, v]) => `${k}: ${Object.entries(v.thresholds).map(([t, r]) => `${t} ${r.ok ? 'OK' : 'FALHOU'}`).join(', ')}`);
  const txt = lines.concat(['Limites:', ...thr.map((t) => '  ' + t)]).join('\n') + '\n';
  return { stdout: txt, [`results/resumo-${stamp}.txt`]: txt, [`results/resumo-${stamp}.json`]: JSON.stringify(data, null, 2) };
}
