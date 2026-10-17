// Regressão V4.7.2: "Meu dia" sempre mostra a data escolhida no topo (nunca os dados de outra data).
// Cobre: calendário, Hoje, texto, "Editar" da lista de hoje, "Novo registro", resposta atrasada de outra data
// (corrida depois de salvar) e troca de data sem conexão. 412x915, fuso America/Cuiaba.
import puppeteer from 'puppeteer-core';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';
const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const cfg = path.resolve(here, '../tmp/config.dev.php');
if (!fs.existsSync(cfg)) { console.error('Rode o PHPUnit antes (gera tests/tmp/config.dev.php).'); process.exit(2); }
const shots = process.env.SHOTS || path.resolve(here, '../tmp/v472');
fs.mkdirSync(shots, { recursive: true });
const port = 8800 + Math.floor(Math.random() * 90);
const base = `http://127.0.0.1:${port}`;
const srv = spawn(process.env.PHP_BIN || 'php', ['-S', `127.0.0.1:${port}`, '-t', path.join(root, 'public_html')], { env: { ...process.env, BIPOLAR_CONFIG: cfg }, stdio: 'ignore' });
await new Promise((r) => setTimeout(r, 800));
const browser = await puppeteer.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox'] });
let fail = 0;
const ok = (c, msg) => { console.log(`${c ? '✓' : '✗'} ${msg}`); if (!c) fail++; };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
try {
  const page = await browser.newPage();
  await page.setViewport({ width: 412, height: 915, deviceScaleFactor: 1, isMobile: true, hasTouch: true });
  page.on('pageerror', (e) => { console.log('pageerror', e.message); fail++; });
  page.on('console', (m) => { if (/Content Security Policy|Refused to apply/i.test(m.text())) { console.log('CSP:', m.text()); fail++; } });
  await page.emulateTimezone('America/Cuiaba');
  await page.goto(`${base}/auth/dev.php?u=v472${Date.now().toString(36)}`, { waitUntil: 'networkidle0' });
  // V4.8: com o módulo Pensamentos ligado, "acelerados" vira por período. Esta regressão cobre o campo do dia (módulo desligado).
  await page.goto(`${base}/?aba=registrar`, { waitUntil: 'networkidle0' });
  await page.evaluate(async () => { const b = JSON.parse(document.getElementById('boot').textContent); const mods = { ...(b.prefs && b.prefs.modules), pensamentos: false }; await fetch('/api/account.php?action=prefs', { method: 'POST', headers: { 'X-CSRF-Token': b.csrf, 'Content-Type': 'application/json' }, body: JSON.stringify({ modules: mods }) }); });
  await page.goto(`${base}/?aba=registrar`, { waitUntil: 'networkidle0' });
  const T = await page.evaluate(() => document.getElementById('entry-date').value);
  const Y = new Date(new Date(T + 'T12:00:00Z').getTime() - 864e5).toISOString().slice(0, 10);
  const br = (iso) => iso.split('-').reverse().join('/');
  await page.evaluate(() => { const f = document.getElementById('form-day'); f.classList.remove('hidden'); f.querySelectorAll('details').forEach((d) => { d.hidden = false; d.open = true; }); });
  const snap = () => page.evaluate(() => {
    const f = document.getElementById('form-day'); const g = (n) => (f.querySelector(`input[name=${n}]:checked`) || {}).value || '';
    const n = document.getElementById('day-load-note');
    return { date: document.getElementById('entry-date').value, label: document.getElementById('day-date-label').textContent, bed: f.elements.bed_time.value, caf: f.elements.caffeine_cups.value, racing: g('racing_thoughts'), st: g('stress_level'), note: f.elements.stress_note.value, notice: n && !n.classList.contains('hidden') ? n.textContent : '' };
  });
  const setP = (p) => page.evaluate((p) => { const r = document.getElementById('p-' + p); r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }, p);
  const fill = (o) => page.evaluate((o) => { const f = document.getElementById('form-day'); f.elements.bed_time.value = o.bed; f.elements.caffeine_cups.value = o.caf; f.querySelector(`input[name=racing_thoughts][value="${o.racing}"]`).checked = true; f.querySelector(`input[name=stress_level][value="${o.st}"]`).checked = true; f.elements.stress_note.value = o.note; }, o);
  let posts = [];
  page.on('request', (rq) => { if (rq.method() === 'POST' && /\/api\/day\.php$/.test(rq.url())) posts.push(JSON.parse(rq.postData() || '{}')); });
  const saveDay = async () => { await page.evaluate(() => document.getElementById('form-day').requestSubmit()); await sleep(900); };
  const pick = async (iso) => { await page.click('.date-pick[data-for=entry-date]'); await sleep(250); await page.evaluate((iso) => document.querySelector(`.cal-day[data-iso="${iso}"]`).click(), iso); await sleep(900); };
  const isToday = (s) => s.bed === '23:00' && s.caf === '2' && s.racing === '2';
  const isYest = (s) => s.bed === '01:30' && s.caf === '6' && s.racing === '0';
  const empty = (s) => s.bed === '' && s.caf === '' && s.racing === '' && s.st === '' && s.note === '';

  // 1) hoje gravado; ontem (sem dados) pelo calendário aparece vazio
  await setP('manha'); await fill({ bed: '23:00', caf: '2', racing: '2', st: '3', note: 'hoje manhã' }); await saveDay();
  await pick(Y); let s = await snap();
  ok(s.date === Y && s.label.includes(br(Y)) && empty(s), 'calendário → ontem sem dados: bloco vazio ' + JSON.stringify(s));
  await page.screenshot({ path: path.join(shots, '01-ontem-vazio.png') });
  await setP('tarde'); await fill({ bed: '01:30', caf: '6', racing: '0', st: '1', note: 'ontem tarde' }); await saveDay();
  ok(posts.at(-1).entry_date === Y && posts.at(-1).caffeine_cups === 6, 'salvar ontem grava em ontem');

  // 2) Hoje (calendário) e ontem digitando
  await page.click('.date-pick[data-for=entry-date]'); await sleep(250); await page.evaluate(() => document.querySelector('[data-today]').click()); await sleep(900);
  s = await snap(); ok(s.date === T && isToday(s) && s.label.includes(br(T)), 'botão Hoje → dados de hoje');
  await page.click('#entry-date-br', { clickCount: 3 }); await page.keyboard.press('Backspace'); await page.type('#entry-date-br', br(Y).replace(/\//g, '')); await page.keyboard.press('Tab'); await sleep(900);
  s = await snap(); ok(s.date === Y && isYest(s) && s.st === '1' && s.note === 'ontem tarde', 'digitar ontem → dados de ontem (Tarde)');

  // 3) corrida: resposta de HOJE atrasada chega depois de escolher ontem — não pode entrar no bloco
  await pick(T);
  await page.setRequestInterception(true);
  const delayT = (rq) => { if (rq.method() === 'GET' && rq.url().includes('/api/day.php?from=' + T)) setTimeout(() => rq.continue().catch(() => {}), 2500); else rq.continue().catch(() => {}); };
  page.on('request', delayT);
  await page.evaluate(() => document.getElementById('form-day').requestSubmit());
  await sleep(400); await pick(Y); await sleep(3000);
  s = await snap();
  ok(s.date === Y && isYest(s) && s.label.includes(br(Y)), 'resposta atrasada de hoje é descartada: bloco continua com ontem ' + JSON.stringify(s));
  await page.screenshot({ path: path.join(shots, '02-corrida-descartada.png') });
  page.off('request', delayT); await page.setRequestInterception(false);
  posts = []; await saveDay();
  ok(posts.length === 1 && posts[0].entry_date === Y && posts[0].caffeine_cups === 6 && posts[0].bed_time === '01:30', 'salvar ontem depois da corrida não leva dados de hoje');

  // 4) "Editar" da lista de hoje estando em ontem (troca de data feita pelo app)
  await pick(T); await setP('manha'); await sleep(600);
  await page.evaluate(() => { document.querySelector('#form-entry input[name=mood][value="1"]').click(); document.getElementById('form-entry').requestSubmit(); }); await sleep(1500);
  await pick(Y); s = await snap(); ok(isYest(s), 'de volta a ontem');
  const had = await page.evaluate(() => { const b = document.querySelector('#today-list [data-edit]'); if (b) b.click(); return !!b; }); await sleep(1200);
  s = await snap(); ok(had && s.date === T && isToday(s) && s.label.includes(br(T)), '"Editar" de hoje estando em ontem → Meu dia mostra hoje ' + JSON.stringify(s));
  await page.screenshot({ path: path.join(shots, '03-editar-hoje.png') });

  // 5) "Novo registro" estando editando ontem → volta para hoje com os dados de hoje
  await pick(Y); await page.evaluate(() => { document.querySelector('#form-entry input[name=mood][value="-1"]').click(); }); await setP('noite'); await sleep(600);
  await page.evaluate(() => { document.querySelector('#form-entry input[name=mood][value="-1"]').click(); document.getElementById('form-entry').requestSubmit(); }); await sleep(1500);
  await page.evaluate(() => document.getElementById('slot-edit') && document.getElementById('slot-edit').click()); await sleep(400);
  await page.evaluate(() => document.getElementById('btn-new-entry').click()); await sleep(1200);
  s = await snap(); ok(s.date === T && isToday(s) && s.label.includes(br(T)), '"Novo registro" → hoje com dados de hoje ' + JSON.stringify(s));

  // 6) sem conexão: trocar de data limpa o bloco, avisa e não deixa salvar por cima
  await page.setOfflineMode(true); await page.evaluate(() => dispatchEvent(new Event('offline')));
  await pick(Y); s = await snap();
  ok(s.date === Y && empty(s) && s.label.includes(br(Y)) && /Sem conexão/.test(s.notice), 'offline → ontem: bloco vazio com aviso ' + JSON.stringify(s));
  await page.screenshot({ path: path.join(shots, '04-offline.png') });
  posts = []; await saveDay(); ok(posts.length === 0, 'offline: salvar um dia não carregado é bloqueado');
  await page.setOfflineMode(false); await page.evaluate(() => dispatchEvent(new Event('online'))); await sleep(1500);
  s = await snap(); ok(s.date === Y && isYest(s) && !s.notice, 'volta a conexão → dados de ontem carregam e o aviso some');
} catch (e) { console.log('erro', e); fail++; }
await browser.close(); srv.kill();
console.log(fail ? `\nRegressão V4.7.2: ${fail} falha(s)` : '\nRegressão V4.7.2 OK');
process.exit(fail ? 1 : 0);
