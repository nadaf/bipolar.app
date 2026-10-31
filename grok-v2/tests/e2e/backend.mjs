// Puppeteer + axe nas telas principais, 412px e 1280px, tema claro e escuro.
// Uso (servidor de teste em bipolar.test:8123 com tests/e2e/seed.php):
//   BASE=http://bipolar.test:8123 OUT=/caminho/screens node tests/e2e/a11y.mjs
// Falha (exit 1) se houver violacao "serious"/"critical", erro de JS ou violacao de CSP.
import puppeteer from 'puppeteer-core';
import { AxePuppeteer } from '@axe-core/puppeteer';
import fs from 'node:fs';

const BASE = process.env.BASE || 'http://bipolar.test:8123';
const CLINIC = BASE.replace('//', '//consultorio-demo.');
const OUT = process.env.OUT || './screens';
const PW = 'senha-demo-1234';
fs.mkdirSync(OUT, { recursive: true });

const browser = await puppeteer.launch({
  executablePath: process.env.CHROME || '/usr/bin/google-chrome',
  protocolTimeout: 60000,
  env: { ...process.env, LANGUAGE: 'pt_BR', LANG: 'pt_BR.UTF-8' }, // datas nativas em dd/mm/aaaa
  args: ['--no-sandbox', '--lang=pt-BR', '--host-resolver-rules=MAP *.bipolar.test 127.0.0.1:8123, MAP bipolar.test 127.0.0.1:8123'],
});
const problems = [];
const report = [];

async function session(base, email) {
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  page.on('console', (m) => { if (/Content Security Policy|Refused to/i.test(m.text())) problems.push(`CSP ${page.url()}: ${m.text()}`); });
  page.on('pageerror', (e) => problems.push(`JS ${page.url()}: ${e.message}`));
  page.on('dialog', (d) => { problems.push(`DIALOGO ${page.url()}: ${d.type()}`); d.accept(); });
  await page.setViewport({ width: 412, height: 915 });
  await page.goto(`${base}/entrar`, { waitUntil: 'networkidle0' });
  await page.type('#email', email);
  await page.type('#password', PW);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.evaluate(() => document.querySelector('form button.btn-primary').click())]);
  return { ctx, page };
}

async function check(page, url, name, { shots = [], prep } = {}) {
  console.error('>', name);
  for (const [w, h] of [[412, 915], [1280, 800]]) {
    for (const scheme of ['light', 'dark']) {
      await page.setViewport({ width: w, height: h });
      await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: scheme }]);
      await page.goto(url, { waitUntil: 'networkidle0' });
      if (prep) await prep(page);
      const r = await new AxePuppeteer(page).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice']).analyze();
      const bad = r.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
      report.push({ name, w, scheme, serious: bad.length, minor: r.violations.length - bad.length });
      bad.forEach((v) => problems.push(`AXE ${name} ${w}px ${scheme}: ${v.id} (${v.impact}) ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`));
      if (shots.includes(`${w}-${scheme}`)) {
        await page.screenshot({ path: `${OUT}/${name}-${w}${scheme === 'dark' ? '-escuro' : ''}.png`, fullPage: true });
      }
    }
  }
}

const all = ['412-light', '1280-light'];
const both = ['412-light', '412-dark', '1280-light', '1280-dark'];

async function guest(base) {
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  page.on('console', (m) => { if (/Content Security Policy|Refused to/i.test(m.text())) problems.push(`CSP ${page.url()}: ${m.text()}`); });
  page.on('pageerror', (e) => problems.push(`JS ${page.url()}: ${e.message}`));
  return { ctx, page };
}

// Paciente: 2FA (passos) e exportacao
{
  const { ctx, page } = await session(BASE, 'ana@demo.test');
  await check(page, `${BASE}/conta`, 'conta-com-2fa', { shots: ['412-light'] });
  await check(page, `${BASE}/conta/verificacao`, 'mfa-passo1', { shots: both });
  await page.goto(`${BASE}/conta/verificacao`, { waitUntil: 'networkidle0' });
  await page.type('#ini-senha', PW);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.$eval('#ini-senha', (i) => i.form.requestSubmit())]);
  await check(page, `${BASE}/conta/verificacao`, 'mfa-passo2-qr', { shots: both });
  await check(page, `${BASE}/exportar`, 'exportar', { shots: both });
  await ctx.close();
}
// Desafio no login
{
  const { ctx, page } = await guest(BASE);
  await page.setViewport({ width: 412, height: 915 });
  await page.goto(`${BASE}/entrar`, { waitUntil: 'networkidle0' });
  await page.type('#email', 'mfa@demo.test');
  await page.type('#password', PW);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.evaluate(() => document.querySelector('form button.btn-primary').click())]);
  if (!page.url().includes('/entrar/verificacao')) problems.push(`MFA: login nao levou ao desafio (${page.url()})`);
  await check(page, `${BASE}/entrar/verificacao`, 'entrar-verificacao', { shots: both });
  await ctx.close();
}
// Profissional: exportar paciente vinculado
{
  const { ctx, page } = await session(CLINIC, 'pro@demo.test');
  await page.goto(`${CLINIC}/cuidado`, { waitUntil: 'networkidle0' });
  const href = await page.$eval('a[href*="/cuidado/"]', (a) => a.getAttribute('href'));
  const id = href.match(/cuidado\/(\d+)/)[1];
  await check(page, `${CLINIC}/cuidado/${id}/exportar`, 'cuidado-exportar', { shots: all });
  await ctx.close();
}
// Admin: convites e seguranca
{
  const { ctx, page } = await session(CLINIC, 'admin@demo.test');
  await check(page, `${CLINIC}/clinica`, 'clinica-convites', { shots: both });
  await ctx.close();
}
// Convite (pessoa sem conta)
{
  const { ctx, page } = await guest(CLINIC);
  await check(page, `${CLINIC}/convite-clinica/${'demo'.repeat(12)}`, 'convite-clinica', { shots: both });
  await ctx.close();
}

await browser.close();
fs.writeFileSync(`${OUT}/a11y-report.json`, JSON.stringify({ report, problems }, null, 2));
console.log(JSON.stringify(report.filter((r) => r.serious > 0 || r.minor > 0), null, 0));
console.log(problems.length ? `PROBLEMAS (${problems.length}):\n${problems.join('\n')}` : 'OK: 0 serious/critical, sem erro de JS/CSP');
process.exit(problems.length ? 1 : 0);
