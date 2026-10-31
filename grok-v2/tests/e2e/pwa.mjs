// PWA: push (permissao/ativar), indicador de rede offline->sincronizado, pagina offline, atalhos do manifesto.
// Uso: BASE=http://bipolar.test:8123 OUT=./screens node tests/e2e/pwa.mjs (servidor com VAPID_* de teste no env)
import puppeteer from 'puppeteer-core';
import { AxePuppeteer } from '@axe-core/puppeteer';
import fs from 'node:fs';
const BASE = process.env.BASE, OUT = process.env.OUT; fs.mkdirSync(OUT, { recursive: true });
const browser = await puppeteer.launch({ executablePath: '/usr/bin/google-chrome', env: { ...process.env, LANGUAGE: 'pt_BR' },
  args: ['--no-sandbox', '--unsafely-treat-insecure-origin-as-secure=http://bipolar.test:8123', '--user-data-dir=/tmp/pwa-prof', '--lang=pt-BR', '--host-resolver-rules=MAP *.bipolar.test 127.0.0.1:8123, MAP bipolar.test 127.0.0.1:8123'] });
const out = [], problems = [];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function axe(page, n) { const r = await new AxePuppeteer(page).analyze(); const bad = r.violations.filter((v) => ['serious', 'critical'].includes(v.impact)); if (bad.length) problems.push(n + ': ' + bad.map((v) => v.id).join(',')); }
for (const w of [412, 375]) {
  const ctx = await browser.createBrowserContext();
  await ctx.overridePermissions(BASE, ['notifications']);
  const page = await ctx.newPage();
  page.on('pageerror', (e) => problems.push('JS ' + e.message));
  page.on('console', (m) => { if (/Content Security Policy|Refused to/i.test(m.text())) problems.push('CSP ' + m.text()); });
  await page.setViewport({ width: w, height: 915 });
  await page.goto(`${BASE}/entrar`, { waitUntil: 'networkidle0' });
  await page.type('#email', 'ana@demo.test'); await page.type('#password', 'senha-demo-1234');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.evaluate(() => document.querySelector('form button.btn-primary').click())]);
  await page.goto(`${BASE}/alertas`, { waitUntil: 'networkidle0' }); await sleep(1500);
  const card = async () => page.evaluate(() => document.querySelector('[x-data="pushAparelho"] [role="status"]')?.textContent.trim() ?? document.title);
  out.push([w, page.url()]); out.push([w, 'alertas antes', await card()]); await axe(page, 'alertas ' + w);
  await page.screenshot({ path: `${OUT}/pwa-alertas-${w}.png`, fullPage: true });
  console.log(JSON.stringify(out)); await page.evaluate(() => document.querySelector('[x-data="pushAparelho"] .btn-primary').click());
  await page.waitForFunction(() => !/Verificando|desligadas/.test(document.querySelector('[x-data="pushAparelho"] [role="status"]').textContent), { timeout: 20000 }).catch(() => {});
  out.push([w, 'alertas apos Ativar', await card()]);
  await page.screenshot({ path: `${OUT}/pwa-alertas-ativado-${w}.png`, fullPage: false });
  // offline: guarda um bloco, indicador mostra fila; volta online -> sincronizado
  await page.goto(`${BASE}/registrar?periodo=noite`, { waitUntil: 'networkidle0' });
  await page.setOfflineMode(true);
  await page.evaluate(() => window.dispatchEvent(new Event('offline')));
  await page.evaluate(() => { document.querySelector('#bloco-humor').open = true; document.querySelector('#bloco-humor label[data-level="-1"] input').click(); document.querySelector('#bloco-humor button[data-continuar]').click(); });
  await sleep(2500);
  out.push([w, 'rede offline', await page.$eval('#rede', (e) => `${e.hidden ? '[oculto]' : ''}${e.textContent.trim()}`)]);
  await axe(page, 'registrar offline ' + w);
  await page.screenshot({ path: `${OUT}/pwa-offline-fila-${w}.png`, fullPage: false });
  await page.setOfflineMode(false);
  await page.evaluate(() => window.dispatchEvent(new Event('online')));
  await page.waitForFunction(() => /Sincronizado/.test(document.querySelector('#rede').textContent), { timeout: 15000 }).catch(() => problems.push('sem Sincronizado ' + w));
  out.push([w, 'rede online', await page.$eval('#rede', (e) => e.textContent.trim())]);
  await page.screenshot({ path: `${OUT}/pwa-sincronizado-${w}.png`, fullPage: false });
  await page.goto(`${BASE}/offline.html`, { waitUntil: 'networkidle0' }); await axe(page, 'offline.html ' + w);
  await page.screenshot({ path: `${OUT}/pwa-pagina-offline-${w}.png`, fullPage: true });
  const man = await page.evaluate(async () => (await (await fetch('/manifest.webmanifest')).json()).shortcuts.map((s) => s.name + ' ' + s.url + ' ' + s.icons[0].src));
  if (w === 412) { out.push(['manifest', man]); for (const s of man) { const src = s.split(' ').pop(); const st = await page.evaluate(async (u) => (await fetch(u)).status, src); if (st !== 200) problems.push('icone ' + src); } }
  await ctx.close();
}
console.log(JSON.stringify(out, null, 1)); console.log(problems.length ? 'PROBLEMAS ' + problems.join(' | ') : 'OK');
await browser.close();
