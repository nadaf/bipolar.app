// Regressão V5.1 (412x915, America/Cuiaba): período travado na sessão de registro + diálogo ao virar o relógio,
// Salvar e continuar / Salvar tudo, Religiosidade por período, tema claro/escuro persistente e grade semanal de lembretes.
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
const shots = process.env.SHOTS || path.resolve(here, '../tmp/v51');
fs.mkdirSync(shots, { recursive: true });
const port = 8800 + Math.floor(Math.random() * 90);
const base = `http://127.0.0.1:${port}`;
const srv = spawn(process.env.PHP_BIN || 'php', ['-S', `127.0.0.1:${port}`, '-t', path.join(root, 'public_html')], { env: { ...process.env, BIPOLAR_CONFIG: cfg }, stdio: 'ignore' });
await new Promise((r) => setTimeout(r, 800));
const axeSrc = fs.readFileSync(createRequire(import.meta.url).resolve('axe-core/axe.min.js'), 'utf8');
const browser = await puppeteer.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox'] });
let fail = 0;
const ok = (c, msg) => { console.log(`${c ? '✓' : '✗'} ${msg}`); if (!c) fail++; };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const run = Date.now().toString(36);
async function axe(page, label, include) {
  await page.evaluate(axeSrc); // via DevTools, sem afrouxar a CSP da página
  const v = await page.evaluate(async (inc) => {
    // eslint-disable-next-line no-undef
    const r = await axe.run(inc ? { include: inc } : document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] } });
    return r.violations.map((x) => `${x.id}: ${x.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(' | ')}`);
  }, include || null);
  ok(v.length === 0, `axe ${label}: ${v.length} violação(ões) ${v.join('; ')}`);
}
async function newPage() {
  const page = await browser.newPage();
  await page.setViewport({ width: 412, height: 915, deviceScaleFactor: 1, isMobile: true, hasTouch: true });
  page.on('pageerror', (e) => { console.log('pageerror', e.message); fail++; });
  page.on('console', (m) => { if (/Content Security Policy|Refused to/i.test(m.text())) { console.log('CSP:', m.text()); fail++; } });
  await page.emulateTimezone('America/Cuiaba');
  return page;
}
const api = (page, method, url, body) => page.evaluate(async (method, url, body) => {
  const csrf = JSON.parse(document.getElementById('boot').textContent).csrf;
  const r = await fetch(url, { method, headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json', Accept: 'application/json' }, credentials: 'same-origin', body: body ? JSON.stringify(body) : undefined });
  return { status: r.status, json: await r.json().catch(() => null) };
}, method, url, body);

const shift = (page, ms) => page.evaluate((ms) => { window.__bpOff = ms; }, ms);
try {
  const page = await newPage();
  // relógio controlável: Date passa a somar window.__bpOff (só neste teste)
  await page.evaluateOnNewDocument(() => {
    const R = Date; window.__bpOff = 0;
    // eslint-disable-next-line no-global-assign
    Date = class extends R { constructor(...a) { if (a.length) super(...a); else super(R.now() + window.__bpOff); } static now() { return R.now() + window.__bpOff; } };
  });
  await page.goto(`${base}/auth/dev.php?u=v51p${run}`, { waitUntil: 'networkidle0' });
  // posiciona o relógio às 11:58 de Cuiabá (UTC-4) de hoje
  const off = await page.evaluate(() => { const n = new Date(); const t = new Date(n); t.setUTCHours(15, 58, 0, 0); return t.getTime() - n.getTime(); });
  await page.evaluateOnNewDocument((o) => { window.__bpOff0 = o; }, off);
  await page.evaluateOnNewDocument(() => { window.__bpOff = window.__bpOff0 || 0; });
  await page.goto(`${base}/?aba=registrar`, { waitUntil: 'networkidle0' });
  const mods = await page.evaluate(() => JSON.parse(document.getElementById('boot').textContent).prefs?.modules || null);
  const all = Object.fromEntries(Object.keys(mods || {}).map((k) => [k, true]));
  let r = await api(page, 'POST', '/api/account.php?action=prefs', { modules: { ...all, religiosidade: true, pensamentos_morte: false, sexualidade: false, substancias: false } });
  ok(r.status === 200, 'ligar módulos (com Religiosidade)');
  await page.goto(`${base}/?aba=registrar`, { waitUntil: 'networkidle0' });
  await sleep(500);
  let ind = await page.$eval('#reg-indicator', (x) => x.textContent);
  ok(/Registrando: Manhã de hoje \(\d\d\/\d\d\)/.test(ind), 'indicador fixo: ' + ind.trim());
  // vira o relógio para a tarde: precisa PERGUNTAR, nunca trocar sozinho
  await shift(page, off + 5 * 60e3);
  await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
  await sleep(400);
  const dlg = await page.evaluate(() => { const d = document.getElementById('confirm-dlg'); return d && d.open ? d.textContent : null; });
  ok(!!dlg && /Continuar em Manhã/.test(dlg) && /Passar para Tarde/.test(dlg), 'diálogo acessível ao mudar de período');
  ok(await page.evaluate(() => document.querySelector('input[name=period]:checked').value === 'manha'), 'período não mudou sozinho enquanto o diálogo está aberto');
  await page.click('#confirm-dlg-cancel'); await sleep(300);
  ok(await page.evaluate(() => document.querySelector('input[name=period]:checked').value === 'manha'), '“Continuar” mantém Manhã');
  // Religiosidade + Salvar e continuar
  ok(await page.$('[data-mod=religiosidade] .save-continue') !== null, 'bloco Religiosidade tem “Salvar e continuar”');
  const nBlocks = await page.$$eval('#form-day [data-mod]:not([hidden])', (b) => b.length);
  const nBtns = await page.$$eval('#form-day [data-mod]:not([hidden]) .save-continue', (b) => b.length);
  ok(nBlocks > 0 && nBlocks === nBtns, `todo bloco visível tem “Salvar e continuar” (${nBtns}/${nBlocks})`);
  await page.evaluate(() => { const d = document.querySelector('[data-mod=religiosidade]'); d.open = true; document.getElementById('d-rel-2').click(); });
  await page.click('[data-mod=religiosidade] .save-continue'); await sleep(900);
  const today = await page.evaluate(() => { const n = new Date(); return new Date(n.getTime() - 4 * 3600e3).toISOString().slice(0, 10); });
  r = await api(page, 'GET', `/api/day.php?from=${today}&to=${today}`);
  const pm = (r.json?.period_markers || r.json?.periodMarkers || []).find((x) => x.period === 'manha');
  ok(pm && +pm.religiosity === 2, 'religiosidade salva na Manhã: ' + JSON.stringify(pm && pm.religiosity));
  ok(await page.evaluate(() => document.activeElement && document.activeElement.closest('[data-mod]') !== document.querySelector('[data-mod=religiosidade]')), 'foco foi para o próximo bloco');
  ok(await page.evaluate(() => document.querySelector('input[name=period]:checked').value === 'manha'), 'depois de salvar continua na Manhã (mesmo período)');
  r = await api(page, 'POST', '/api/day.php', { entry_date: today, period: 'manha', religiosity: 9 });
  ok(r.status === 422, 'valor inválido de religiosidade → 422');
  // Salvar tudo
  await page.evaluate(() => { document.querySelector('.mood-options input[value="1"]').click(); });
  await page.click('#btn-save-all'); await sleep(1200);
  const sum = await page.$eval('#save-all-summary', (x) => (x.classList.contains('hidden') ? '' : x.textContent));
  ok(/Salvo:/.test(sum) && /Humor/.test(sum), 'Salvar tudo mostra resumo: ' + sum.slice(0, 120));
  await axe(page, 'Registrar (indicador, botões, religiosidade)', '#painel-registrar');
  // Tema
  const th0 = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
  await page.evaluate(() => window.scrollTo(0, 0)); await page.click('#btn-theme'); await sleep(200);
  const th1 = await page.evaluate(() => [document.documentElement.getAttribute('data-theme'), localStorage.getItem('bp-theme'), document.getElementById('btn-theme').getAttribute('aria-pressed'), getComputedStyle(document.body).backgroundColor]);
  ok(th1[0] && th1[0] === th1[1] && th1[0] !== th0, 'tema alterna e salva: ' + th1.join(' '));
  ok(th1[2] === (th1[0] === 'dark' ? 'true' : 'false'), 'aria-pressed acompanha o tema');
  await page.reload({ waitUntil: 'networkidle0' });
  ok(await page.evaluate(() => document.documentElement.getAttribute('data-theme')) === th1[0], 'tema continua depois de recarregar');
  await page.evaluate(() => window.scrollTo(0, 0)); await page.click('#btn-theme'); await sleep(200);
  const th2 = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
  ok(th2 !== th1[3], 'cor de fundo muda de verdade (' + th1[3] + ' → ' + th2 + ')');
  // sem escolha salva: segue o sistema
  await page.evaluate(() => localStorage.removeItem('bp-theme'));
  await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'dark' }]);
  await page.reload({ waitUntil: 'networkidle0' });
  ok(await page.evaluate(() => !document.documentElement.hasAttribute('data-theme') && document.getElementById('btn-theme').getAttribute('aria-pressed') === 'true' && getComputedStyle(document.body).backgroundColor === 'rgb(15, 26, 32)'), 'sem escolha salva segue prefers-color-scheme: dark');
  await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }]); await sleep(200);
  ok(await page.evaluate(() => document.getElementById('btn-theme').getAttribute('aria-pressed') === 'false'), 'acompanha a mudança do sistema ao vivo');
  // Lembretes: grade semanal
  r = await api(page, 'POST', '/api/reminders.php', { time_local: '08:00', days: [1, 2, 3, 4, 5], channel: 'email' });
  ok(r.status === 200, 'cria horário 08:00 seg–sex');
  await page.goto(`${base}/?aba=lembretes`, { waitUntil: 'networkidle0' }); await sleep(600);
  const cells = await page.$$eval('#reminders .rm-cell', (c) => c.map((x) => [x.getAttribute('aria-pressed'), x.getBoundingClientRect().height, x.getBoundingClientRect().width]));
  ok(cells.length === 7, 'linha com 7 dias (Dom–Sáb)');
  ok(cells[0] && cells[0][0] === 'false' && cells[1][0] === 'true', 'aria-pressed reflete os dias');
  ok(cells.every((c) => c[1] >= 44), 'células com 44px de altura');
  ok(cells.every((c) => c[2] >= 24), 'células com largura mínima de alvo (≥ 24 px, WCAG 2.5.8)');
  ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'grade cabe na largura do celular (412 px) sem rolagem lateral');
  await page.focus('#reminders .rm-cell'); await page.keyboard.press('Enter'); await sleep(700);
  ok(await page.$eval('#reminders .rm-cell', (x) => x.getAttribute('aria-pressed')) === 'true', 'teclado: Enter liga o domingo');
  r = await api(page, 'GET', '/api/reminders.php');
  ok(r.json.reminders.some((x) => x.days === '0,1,2,3,4,5'), 'servidor gravou os dias (PATCH)');
  await page.keyboard.press('ArrowRight');
  ok(await page.evaluate(() => document.activeElement.dataset.day === '1'), 'seta → move para segunda');
  await axe(page, 'Lembretes (grade)', '#painel-lembretes');
  await page.screenshot({ path: path.join(shots, 'lembretes.png'), fullPage: true });
} catch (e) { console.log('erro', e); fail++; }
await browser.close(); srv.kill();
console.log(fail ? `FALHOU: ${fail}` : 'OK v5.1');
process.exit(fail ? 1 : 0);
