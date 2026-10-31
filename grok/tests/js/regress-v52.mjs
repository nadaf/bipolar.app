// Regressão V5.2 (412x915): Google Analytics 4 com privacidade no host principal (bipolar.app.br → servidor local).
// Nenhuma requisição real sai para o Google: tudo que vai a domínios do Google é interceptado e registrado
// (gtag.js é trocado por um stub vazio). Confere: nada antes do aceite, recusa lembrada, CSP sem violação, axe,
// e o conteúdo de todos os eventos (dataLayer) sem valores sentinela de saúde, texto, nome, e-mail ou ID.
import puppeteer from 'puppeteer-core';
import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';
const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const cfg = path.resolve(here, '../tmp/config.dev.php');
if (!fs.existsSync(cfg)) { console.error('Rode o PHPUnit antes (gera tests/tmp/config.dev.php).'); process.exit(2); }
if (!fs.existsSync(path.join(root, 'app/site-main.php'))) { console.log('app/site-main.php ausente (pacote público): GA desligado, teste pulado.'); process.exit(0); }
const ID = fs.readFileSync(path.join(root, 'app/site-main.php'), 'utf8').match(/G-[A-Z0-9]{4,16}/)[0];
const port = 8900 + Math.floor(Math.random() * 90);
const base = 'http://bipolar.app.br';
const srv = spawn(process.env.PHP_BIN || 'php', ['-S', `127.0.0.1:${port}`, '-t', path.join(root, 'public_html')], { env: { ...process.env, BIPOLAR_CONFIG: cfg }, stdio: 'ignore' });
await new Promise((r) => setTimeout(r, 800));
const axeSrc = fs.readFileSync(createRequire(import.meta.url).resolve('axe-core/axe.min.js'), 'utf8');
const browser = await puppeteer.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', headless: true,
  args: ['--no-sandbox', `--host-resolver-rules=MAP bipolar.app.br 127.0.0.1:${port}`] });
let fail = 0;
const ok = (c, msg) => { console.log(`${c ? '✓' : '✗'} ${msg}`); if (!c) fail++; };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const run = Date.now().toString(36);
const NAME = 'Sentinela' + run;
const SENT = ['SENTINELA-NOTA-52', NAME, 'v52' + run, '@', 'religiosity', 'religiosidade', 'pensamentos', 'sexualidade', 'substancias', 'pensamentos_morte', 'th_death', 'mood', 'note'];
const google = [];
async function axe(page, label) {
  await page.evaluate(axeSrc);
  const v = await page.evaluate(async () => {
    // eslint-disable-next-line no-undef
    const r = await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] } });
    return r.violations.map((x) => `${x.id}: ${x.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(' | ')}`);
  });
  ok(v.length === 0, `axe ${label}: ${v.length} violação(ões) ${v.join('; ')}`);
}
const api = (page, method, url, body) => page.evaluate(async (method, url, body) => {
  const csrf = JSON.parse(document.getElementById('boot').textContent).csrf;
  const r = await fetch(url, { method, headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json', Accept: 'application/json' }, credentials: 'same-origin', body: body ? JSON.stringify(body) : undefined });
  return { status: r.status, json: await r.json().catch(() => null) };
}, method, url, body);
const dl = (page) => page.evaluate(() => JSON.stringify((window.dataLayer || []).map((a) => Array.from(a)).filter((a) => a[0] !== 'js')));
try {
  const page = await browser.newPage();
  await page.setViewport({ width: 412, height: 915, deviceScaleFactor: 1, isMobile: true, hasTouch: true });
  page.on('pageerror', (e) => { console.log('pageerror', e.message); fail++; });
  page.on('console', (m) => { if (/Content Security Policy|Refused to/i.test(m.text())) { console.log('CSP:', m.text()); fail++; } });
  await page.setRequestInterception(true);
  page.on('request', (req) => {
    const u = new URL(req.url());
    if (/(^|\.)(google-analytics\.com|analytics\.google\.com|googletagmanager\.com|doubleclick\.net|google\.com)$/.test(u.hostname)) {
      google.push(req.url());
      if (u.pathname === '/gtag/js') return req.respond({ status: 200, contentType: 'application/javascript', body: 'window.__gtagStub = (window.__gtagStub || 0) + 1;' });
      return req.respond({ status: 204, body: '' });
    }
    req.continue();
  });

  // 1) primeira visita: aviso aparece, nada vai para o Google
  await page.goto(`${base}/auth/dev.php?u=v52${run}`, { waitUntil: 'networkidle0' });
  await api(page, 'POST', '/api/account.php?action=settings', { name: NAME }).catch(() => null);
  const mods = await page.evaluate(() => JSON.parse(document.getElementById('boot').textContent).prefs?.modules || null);
  const all = Object.fromEntries(Object.keys(mods || {}).map((k) => [k, true]));
  ok((await api(page, 'POST', '/api/account.php?action=prefs', { modules: { ...all, religiosidade: true, pensamentos: true, pensamentos_morte: true } })).status === 200, 'módulos privados ligados');
  await page.goto(`${base}/?aba=registrar`, { waitUntil: 'networkidle0' });
  ok(await page.$eval('meta[name=bp-ga4]', (m) => m.content) === ID, 'ID do site principal no host principal');
  ok(await page.$eval('#consent-banner', (b) => !b.hidden), 'aviso de consentimento visível na primeira visita');
  ok(await page.evaluate(() => document.activeElement === document.body || document.activeElement.id === 'conteudo' || !document.activeElement.closest('#consent-banner')), 'aviso não rouba o foco');
  await axe(page, 'tela Registrar com aviso');
  ok(google.length === 0, 'sem consentimento: 0 requisições ao Google (' + google.length + ')');
  ok(await page.evaluate(() => (window.dataLayer || []).some((a) => a[0] === 'consent' && a[1] === 'default' && a[2].analytics_storage === 'denied' && a[2].ad_storage === 'denied' && a[2].ad_user_data === 'denied' && a[2].ad_personalization === 'denied')), 'Consent Mode v2: tudo negado por padrão');

  // 2) recusar: lembrado, nada enviado mesmo usando o app
  await page.click('#consent-deny'); await sleep(200);
  ok(await page.$eval('#consent-banner', (b) => b.hidden), 'Recusar fecha o aviso');
  ok(await page.evaluate(() => localStorage.getItem('bp-analytics-consent')) === 'denied', 'recusa lembrada');
  await page.click('#tab-historico'); await sleep(300); await page.click('#tab-registrar'); await sleep(200);
  await page.reload({ waitUntil: 'networkidle0' });
  ok(await page.$eval('#consent-banner', (b) => b.hidden), 'depois de recusar, o aviso não volta');
  ok(google.length === 0 && !(await page.evaluate(() => window.__gtagStub)), 'recusado: 0 requisições ao Google e gtag.js não carregado');

  // 3) mudar de ideia em Conta › Estatísticas e aceitar
  await page.click('#tab-conta'); await sleep(300);
  ok(await page.$('#conta-estatisticas [data-consent-open]') !== null, 'Conta tem “Mudar minha escolha sobre estatísticas”');
  await axe(page, 'Conta');
  await page.click('#conta-estatisticas [data-consent-open]'); await sleep(200);
  ok(await page.evaluate(() => document.activeElement.id) === 'consent-accept', 'abrir pelas configurações leva o foco para Aceitar');
  const box = await page.$eval('#consent-accept', (b) => { const r = b.getBoundingClientRect(); return [r.width, r.height, r.right <= innerWidth]; });
  ok(box[0] >= 44 && box[1] >= 44 && box[2], 'botões do aviso ≥ 44px e dentro da tela: ' + box.join(','));
  await page.click('#consent-accept'); await sleep(500);
  ok(await page.evaluate(() => window.__gtagStub) === 1, 'aceito: gtag.js (stub) carregado pela CSP');
  ok(google.every((u) => u === 'https://www.googletagmanager.com/gtag/js?id=' + ID), 'só gtag.js foi pedido: ' + google.join(' '));
  ok(await page.evaluate(() => document.activeElement && document.activeElement.matches('#conta-estatisticas [data-consent-open]')), 'foco volta para o botão que abriu o aviso');

  // 4) usar o app com dados sentinela
  await page.click('#tab-historico'); await sleep(400);
  await page.click('#tab-registrar'); await sleep(300);
  await page.evaluate(() => { for (const m of ['sono', 'religiosidade', 'pensamentos']) { const d = document.querySelector(`[data-mod=${m}]`); if (d) d.open = true; } });
  await sleep(200);
  await page.evaluate(() => { document.querySelector('.mood-options input[value="2"]').click(); });
  await page.type('#note', 'SENTINELA-NOTA-52 ' + NAME);
  await page.click('#btn-save-entry'); await sleep(1200);
  await page.evaluate(() => { const r = document.getElementById('d-rel-3'); if (r) r.click(); });
  await page.evaluate(() => document.querySelector('[data-mod=religiosidade] .save-continue').click()); await sleep(1000);
  await page.evaluate(() => { const b = document.querySelector('#form-death button[type=submit]'); if (b) b.click(); const h = document.getElementById('death-hide'); if (h) h.click(); });
  await sleep(500);
  await page.evaluate(() => document.getElementById('btn-theme').click()); await sleep(100);
  await page.evaluate(() => document.getElementById('btn-theme').click()); await sleep(100);
  await page.evaluate(() => document.getElementById('btn-save-all').click()); await sleep(1200);
  const raw = await dl(page);
  const events = JSON.parse(raw).filter((a) => a[0] === 'event');
  console.log('  eventos:', events.map((e) => e[1] + (e[2] && e[2].bloco ? ':' + e[2].bloco : '')).join(' '));
  const hits = SENT.filter((s) => raw.includes(s));
  ok(hits.length === 0, 'nenhum valor sentinela/proibido no que vai ao GA: ' + hits.join(','));
  ok(!/\d{4}-\d{2}-\d{2}|\d{2}\/\d{2}/.test(JSON.stringify(events)), 'sem datas nos eventos');
  const pv = events.filter((e) => e[1] === 'page_view');
  ok(pv.length >= 3 && pv.every((e) => /^http:\/\/bipolar\.app\.br\/[a-z/]*$/.test(e[2].page_location) && !/[?#\d]/.test(e[2].page_location)), 'page_view virtual sem query/ID: ' + pv.map((e) => e[2].page_location).join(' '));
  ok(pv.some((e) => e[2].page_location.endsWith('/app/historico') && e[2].page_title === 'Histórico e alertas'), 'tela Histórico vira page_view com título genérico');
  ok(events.some((e) => e[1] === 'abrir_bloco' && e[2].bloco === 'sono'), 'abrir_bloco com bloco=sono');
  ok(events.some((e) => e[1] === 'abrir_bloco' && e[2].bloco === 'privado'), 'bloco privado aparece só como "privado"');
  ok(events.some((e) => e[1] === 'salvar_e_continuar' && e[2].bloco === 'privado'), 'salvar_e_continuar do bloco privado sem nome');
  ok(events.filter((e) => e[1] === 'first_record').length === 1, 'first_record uma vez');
  ok(events.some((e) => e[1] === 'salvar_registro') && events.some((e) => e[1] === 'salvar_tudo') && events.filter((e) => e[1] === 'tema_alternar').length === 2, 'salvar_registro, salvar_tudo, tema_alternar');
  ok(!events.some((e) => /death|morte/.test(JSON.stringify(e))) && events.every((e) => Object.keys(e[2] || {}).every((k) => ['bloco', 'formato', 'page_location', 'page_title'].includes(k))), 'módulo de morte sem evento; só parâmetros permitidos');
  ok(JSON.parse(raw).some((a) => a[0] === 'config' && a[2].allow_google_signals === false && a[2].allow_ad_personalization_signals === false && a[2].page_referrer === ''), 'config: sinais do Google e anúncios desligados');

  // 5) recusar depois: para de registrar
  await page.click('#tab-conta'); await sleep(200);
  await page.click('#conta-estatisticas [data-consent-open]'); await sleep(100);
  await page.click('#consent-deny'); await sleep(200);
  const before = JSON.parse(await dl(page)).length;
  await page.click('#tab-historico'); await sleep(300);
  ok(JSON.parse(await dl(page)).length === before + 0 || JSON.parse(await dl(page)).slice(before).every((a) => a[0] === 'consent'), 'depois de recusar, nenhum evento novo');
  ok(await page.evaluate((id) => window['ga-disable-' + id], ID) === true, 'ga-disable ligado após recusa');
  await page.screenshot({ path: path.resolve(here, '../tmp/v52-consent.png') }).catch(() => null);

  // 6) páginas com dados de paciente/token: sem GA
  for (const p of ['/relatorio.php?de=2026-10-01&ate=2026-10-10', '/?c=TOKEN']) {
    const n = google.length;
    await page.goto(base + p, { waitUntil: 'networkidle0' });
    ok(!(await page.$('meta[name=bp-ga4]')) && google.length === n, 'sem GA em ' + p);
  }
} catch (e) { console.error(e); fail++; } finally { await browser.close(); srv.kill(); }
if (fail) { console.error(`Regressão V5.2: ${fail} falha(s)`); process.exit(1); }
console.log('Regressão V5.2 OK');
