// Regressão V4.1: calendário pt-BR, data inexistente, sinal −, página 404, página de alertas, fusos em pt-BR, CSP.
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
  page.on('console', (m) => { if (/Content Security Policy|Refused to/i.test(m.text())) { console.log('CSP:', m.text()); fail++; } });
  await page.emulateTimezone('America/Cuiaba');
  // Páginas públicas
  let r = await page.goto(`${base}/404.php`, { waitUntil: 'load' });
  ok(r.status() === 404, `404.php responde 404 (${r.status()})`);
  ok(!!r.headers()['content-security-policy'] && r.headers()['x-content-type-options'] === 'nosniff', '404 tem cabeçalhos de segurança');
  ok(await page.$eval('h1', (h) => h.textContent.includes('não encontrada')), '404 com texto em pt-BR');
  r = await page.goto(`${base}/como-funcionam-os-alertas.php`, { waitUntil: 'load' });
  ok(r.status() === 200 && (await page.$eval('h1', (h) => h.textContent)).includes('alertas'), 'página pública “Como funcionam os alertas”');
  await page.goto(`${base}/escala.php`, { waitUntil: 'load' });
  const esc = await page.content();
  ok(!esc.includes('ALERTAS.md') && esc.includes('/como-funcionam-os-alertas.php'), 'escala.php sem ALERTAS.md e com link para a página de alertas');
  // App
  await page.goto(`${base}/auth/dev.php?u=v41${Date.now().toString(36)}`, { waitUntil: 'networkidle0' });
  await page.goto(`${base}/?aba=registrar`, { waitUntil: 'networkidle0' });
  // 31/02
  await page.evaluate(() => { const t = document.getElementById('entry-date-br'); t.value = '31/02/2026'; t.dispatchEvent(new Event('change')); });
  ok((await page.$eval('#entry-date-err', (e) => e.textContent)).includes('esse dia não existe'), '31/02 → “Data inválida: esse dia não existe.”');
  await page.evaluate(() => { const t = document.getElementById('entry-date-br'); t.value = '99/99'; t.dispatchEvent(new Event('change')); });
  ok((await page.$eval('#entry-date-err', (e) => e.textContent)).includes('dd/mm/aaaa'), 'formato incompleto → mensagem de formato');
  // Calendário pt-BR
  await page.evaluate(() => { const t = document.getElementById('entry-date-br'); t.value = '10/10/2026'; t.dispatchEvent(new Event('change')); });
  await page.click('.date-pick[data-for="entry-date"]');
  await sleep(200);
  const cal = await page.evaluate(() => { const d = document.querySelector('dialog.cal[open]'); return d ? { title: d.querySelector('.cal-title').textContent, focus: document.activeElement.dataset.iso || '', heads: [...d.querySelectorAll('th abbr')].map((a) => a.title).join(',') } : null; });
  ok(cal && cal.title === 'outubro de 2026', `calendário abre em pt-BR (${cal && cal.title})`);
  ok(cal && cal.focus === '2026-10-10', `foco no dia selecionado (${cal && cal.focus})`);
  ok(cal && cal.heads.startsWith('domingo,segunda'), 'dias da semana em pt-BR');
  await page.keyboard.press('ArrowLeft'); await page.keyboard.press('Enter'); await sleep(200);
  const after = await page.evaluate(() => ({ txt: document.getElementById('entry-date-br').value, iso: document.getElementById('entry-date').value, open: !!document.querySelector('dialog.cal[open]'), focus: document.activeElement.id }));
  ok(after.txt === '09/10/2026' && after.iso === '2026-10-09' && !after.open, `seta + Enter escolhe 09/10/2026 (${after.txt})`);
  ok(after.focus === 'entry-date-br', `foco volta ao campo de data (${after.focus})`);
  await page.click('.date-pick[data-for="entry-date"]'); await sleep(150);
  await page.keyboard.press('Escape'); await sleep(150);
  ok(await page.evaluate(() => !document.querySelector('dialog.cal[open]') && document.activeElement.classList.contains('date-pick')), 'Esc fecha e devolve o foco ao botão');
  // Fusos em pt-BR
  const tz = await page.evaluate(() => [...document.querySelectorAll('#st-tz option')].find((o) => o.value === 'Europe/Lisbon')?.textContent);
  ok(tz === 'Europa · Lisboa', `fuso em pt-BR (${tz})`);
  // Registro rápido usa o sinal de menos tipográfico
  await page.goto(`${base}/registro-rapido.php`, { waitUntil: 'load' });
  const q = await page.$$eval('.quick-btn strong', (s) => s.map((x) => x.textContent));
  ok(q.includes('−3') && !q.some((x) => /^-/.test(x)), `registro rápido com “−” (${q.join(' ')})`);
} finally { await browser.close(); srv.kill(); }
console.log(fail ? `\n${fail} falha(s)` : '\nV4.1: OK');
process.exit(fail ? 1 : 0);
