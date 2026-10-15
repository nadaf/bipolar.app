// Regressão (V4.1): "Editar" após salvar deve carregar o registro do período clicado (por id),
// e salvar nunca pode sobrescrever outro período. Roda em Chrome headless contra o servidor PHP local.
import puppeteer from 'puppeteer-core';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const cfg = path.resolve(here, '../tmp/config.dev.php');
if (!fs.existsSync(cfg)) { console.error('Rode o PHPUnit antes (gera tests/tmp/config.dev.php).'); process.exit(2); }
const port = 8700 + Math.floor(Math.random() * 90);
const base = `http://127.0.0.1:${port}`;
const srv = spawn(process.env.PHP_BIN || 'php', ['-S', `127.0.0.1:${port}`, '-t', path.join(root, 'public_html')], { env: { ...process.env, BIPOLAR_CONFIG: cfg }, stdio: 'ignore' });
await new Promise((r) => setTimeout(r, 800));
const browser = await puppeteer.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox'] });
let fail = 0;
const ok = (c, msg) => { console.log(`${c ? '✓' : '✗'} ${msg}`); if (!c) fail++; };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
try {
  const page = await browser.newPage();
  page.on('pageerror', (e) => { console.log('pageerror', e.message); fail++; });
  page.on('console', (m) => { if (/Content Security Policy|Refused to apply/i.test(m.text())) { console.log('CSP:', m.text()); fail++; } });
  await page.emulateTimezone('America/Cuiaba');
  await page.goto(`${base}/auth/dev.php?u=edit${Date.now().toString(36)}`, { waitUntil: 'networkidle0' });
  await page.goto(`${base}/?aba=registrar`, { waitUntil: 'networkidle0' });
  const state = () => page.evaluate(() => ({
    period: (document.querySelector('input[name=period]:checked') || {}).value,
    mood: (document.querySelector('input[name=mood]:checked') || {}).value,
    sleep: document.getElementById('sleep').value,
    note: document.getElementById('edit-note').classList.contains('hidden') ? '' : document.getElementById('edit-note').textContent,
    body: !document.getElementById('entry-body').classList.contains('hidden'),
    view: document.getElementById('slot-view').classList.contains('hidden') ? '' : document.getElementById('slot-view').textContent,
  }));
  const pick = (period, mood, sleepH) => page.evaluate((p, m, s) => {
    const r = document.getElementById('p-' + p); if (!r.checked) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }
    document.querySelector(`input[name=mood][value="${m}"]`).checked = true;
    document.querySelector('details.extras').open = true; document.getElementById('sleep').value = s;
  }, period, mood, sleepH);
  const save = async () => { await page.click('#btn-save-entry'); await page.waitForFunction(() => /salvo|atualizado/i.test(document.getElementById('toast')?.textContent || ''), { timeout: 5000 }); await sleep(600); };
  const today = await page.evaluate(() => JSON.parse(document.getElementById('boot').textContent).today || new Intl.DateTimeFormat('en-CA', { timeZone: JSON.parse(document.getElementById('boot').textContent).tz }).format(new Date()));

  // 1) novo registro de Noite
  await page.click('[data-new="noite"]'); await sleep(400);
  await pick('noite', 1, '7.5'); await save();
  // 2) logo depois: "Registrar" Tarde
  await page.click('[data-new="tarde"]'); await sleep(400);
  let s = await state();
  ok(s.period === 'tarde' && s.mood === undefined && !/Noite/.test(s.note), `Registrar Tarde abre formulário vazio de Tarde (período=${s.period}, humor=${s.mood}, aviso="${s.note}")`);
  await pick('tarde', 0, '6'); await save();
  // 3) Editar Noite logo após salvar Tarde
  await page.click('[data-edit="noite"]'); await sleep(500);
  s = await state();
  ok(s.period === 'noite' && s.mood === '1' && s.sleep === '7.5' && /Noite/.test(s.note), `Editar Noite carrega Noite (período=${s.period}, humor=${s.mood}, sono=${s.sleep})`);
  // 4) sem salvar, Editar Tarde
  await page.click('[data-edit="tarde"]'); await sleep(500);
  s = await state();
  ok(s.period === 'tarde' && s.mood === '0' && s.sleep === '6' && /Tarde/.test(s.note) && !/Noite/.test(s.note), `Editar Tarde carrega Tarde (período=${s.period}, humor=${s.mood}, sono=${s.sleep}, aviso="${s.note}")`);
  // 5) altera e salva Tarde
  await page.evaluate(() => { document.querySelector('input[name=mood][value="-1"]').checked = true; });
  await save();
  // 6) troca de período no meio da edição: Editar Noite, depois clica Manhã → formulário novo, sem aviso de edição
  await page.click('[data-edit="noite"]'); await sleep(500);
  await page.evaluate(() => { const r = document.getElementById('p-manha'); r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); });
  await sleep(500);
  s = await state();
  ok(s.period === 'manha' && !s.note && s.mood === undefined, `Trocar para Manhã durante edição limpa o formulário (aviso="${s.note}", humor=${s.mood})`);
  // 7) servidor: cada período com seu valor
  const rows = await page.evaluate(async (d) => (await (await fetch('/api/entries.php?from=' + d + '&to=' + d)).json()).entries, today);
  const by = Object.fromEntries(rows.map((r) => [r.period, r]));
  ok(rows.length === 2 && +by.noite?.mood === 1 && +by.noite?.sleep_hours === 7.5 && +by.tarde?.mood === -1 && +by.tarde?.sleep_hours === 6, `servidor: Noite=+1/7,5h e Tarde=−1/6h (${JSON.stringify(rows.map((r) => [r.period, r.mood, r.sleep_hours]))})`);
  // 8) ao recarregar, não entra em modo edição automaticamente
  await page.goto(`${base}/?aba=registrar`, { waitUntil: 'networkidle0' });
  s = await state();
  ok(!s.note, `ao abrir a página não entra em modo edição (aviso="${s.note}")`);
  await page.evaluate(() => { const r = document.getElementById('p-tarde'); r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); });
  await sleep(500);
  s = await state();
  ok(!s.body && /Tarde/.test(s.view) && /−1|-1/.test(s.view), `período já registrado aparece só para leitura, com Editar (resumo="${s.view.trim()}")`);
  await page.click('#slot-edit'); await sleep(300);
  s = await state();
  ok(s.body && s.mood === '-1' && /Tarde/.test(s.note), `"Editar este registro" abre a edição da Tarde (humor=${s.mood})`);
  // 8b) período trocado sem evento (ex.: preenchido por script) durante a edição → não salva por cima
  await page.click('[data-edit="noite"]'); await sleep(500);
  await page.evaluate(() => { document.getElementById('p-tarde').checked = true; document.querySelector('input[name=mood][value="3"]').checked = true; });
  await page.click('#btn-save-entry'); await sleep(800);
  const after = await page.evaluate(async (d) => (await (await fetch('/api/entries.php?from=' + d + '&to=' + d)).json()).entries, today);
  ok(after.every((r) => +r.mood !== 3), `edição inconsistente não sobrescreve outro período (${JSON.stringify(after.map((r) => [r.period, r.mood]))})`);
  // 9) servidor: id de outro período não pode sobrescrever (409)
  const conflict = await page.evaluate(async (d, noiteId) => {
    const csrf = JSON.parse(document.getElementById('boot').textContent).csrf;
    const r = await fetch('/api/entries.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ id: noiteId, entry_date: d, period: 'tarde', mood: 3 }) });
    return r.status;
  }, today, by.noite?.id);
  ok(conflict === 409, `POST com id da Noite e período Tarde é recusado (HTTP ${conflict})`);
} finally { await browser.close(); srv.kill(); }
console.log(fail ? `\n${fail} falha(s)` : '\nEdição por período: OK');
process.exit(fail ? 1 : 0);
