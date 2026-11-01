// Puppeteer + axe nas telas principais, celular (MOBILE, padrao 412px) e 1280px, tema claro e escuro.
// Uso (servidor de teste em bipolar.test:8123 com tests/e2e/seed.php):
//   BASE=http://bipolar.test:8123 OUT=/caminho/screens [MOBILE=375] [RESET_URL=link de /redefinir-senha] node tests/e2e/a11y.mjs
// Falha (exit 1) se houver violacao "serious"/"critical", erro de JS ou violacao de CSP.
import puppeteer from 'puppeteer-core';
import { AxePuppeteer } from '@axe-core/puppeteer';
import fs from 'node:fs';

const BASE = process.env.BASE || 'http://bipolar.test:8123';
const CLINIC = BASE.replace('//', '//consultorio-demo.');
const OUT = process.env.OUT || './screens';
const PW = 'senha-demo-1234';
const M = Number(process.env.MOBILE || 412);
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
  await page.setViewport({ width: M, height: 915 });
  await page.goto(`${base}/entrar`, { waitUntil: 'networkidle0' });
  await page.type('#email', email);
  await page.type('#password', PW);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.evaluate(() => document.querySelector('form button.btn-primary').click())]);
  return { ctx, page };
}

async function check(page, url, name, { shots = [], prep } = {}) {
  console.error('>', name);
  for (const [w, h] of [[M, 915], [1280, 800]]) {
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

const all = [`${M}-light`, '1280-light'];
const both = [`${M}-light`, `${M}-dark`, '1280-light', '1280-dark'];
const mob = [`${M}-light`];

// Visitante
{
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  page.on('pageerror', (e) => problems.push(`JS ${page.url()}: ${e.message}`));
  await check(page, `${BASE}/entrar`, 'entrar', { shots: all });
  await check(page, `${BASE}/criar-conta`, 'criar-conta', { shots: all });
  await check(page, `${BASE}/esqueci-senha`, 'esqueci-senha', { shots: all });
  await check(page, `${BASE}/redefinir-senha/${'x'.repeat(64)}`, 'redefinir-senha-invalido', { shots: [mob[0]] });
  if (process.env.RESET_URL) await check(page, process.env.RESET_URL, 'redefinir-senha', { shots: all });
  await ctx.close();
}

// Paciente
{
  const { ctx, page } = await session(BASE, 'ana@demo.test');
  await check(page, `${BASE}/registrar`, 'registrar', { shots: both });
  await check(page, `${BASE}/registrar?periodo=manha`, 'registrar-cartao-aberto', {
    shots: [mob[0]],
    prep: async (p) => { await p.evaluate(() => { document.querySelector('#bloco-sono').open = true; }); },
  });
  // dialogo de troca de periodo (simula o relogio cruzando o periodo)
  await check(page, `${BASE}/registrar?periodo=manha`, 'registrar-dialogo-periodo', {
    shots: [mob[0]],
    prep: async (p) => {
      await p.evaluate(() => {
        const el = document.querySelector('[x-data="periodLock"]');
        const d = el._x_dataStack[0];
        d.agora = 'Tarde'; d.link = '?periodo=tarde';
        el.querySelector('dialog').showModal();
      });
      await new Promise((r) => setTimeout(r, 150));
    },
  });
  // fluxo: escolhe humor, Salvar e continuar -> resultado acessivel e proximo cartao aberto
  await page.setViewport({ width: M, height: 915 });
  await page.goto(`${BASE}/registrar?periodo=manha`, { waitUntil: 'networkidle0' });
  await page.evaluate(() => { document.querySelector('#bloco-humor').open = true; });
  // cliques via DOM (o clique sintetico do CDP trava no Chrome headless com <details> + radio sr-only)
  const tap = (sel) => page.evaluate((s) => document.querySelector(s).click(), sel);
  await tap('#bloco-humor label[data-level="1"] input');
  await tap('#bloco-humor button[data-continuar]');
  await page.waitForSelector('#bloco-humor [data-result]', { timeout: 5000 });
  const res = await page.$eval('#bloco-humor [data-result]', (e) => e.dataset.result + ' ' + e.textContent.trim());
  const nextOpen = await page.$eval('#bloco-energia', (e) => e.open);
  if (!res.startsWith('ok') || !nextOpen) problems.push(`FLUXO Registrar: ${res} / proximo aberto=${nextOpen}`);
  await tap('#bloco-energia label[data-level="2"] input');
  await tap('button[x-ref="salvarTudo"]');
  await page.waitForFunction(() => !/Salva de uma vez|Salvando/.test(document.querySelector('.save-bar p').textContent), { timeout: 5000 });
  await page.screenshot({ path: `${OUT}/registrar-salvar-tudo-${M}.png`, fullPage: false });
  report.push({ name: 'fluxo-registrar', resultado: res, salvarTudo: await page.$eval('.save-bar p', (e) => e.textContent.trim()) });

  await check(page, `${BASE}/historico`, 'historico', { shots: all });
  await check(page, `${BASE}/relatorios`, 'relatorios', { shots: [...all, '1280-dark'] });
  await check(page, `${BASE}/alertas`, 'alertas', { shots: all });
  await check(page, `${BASE}/conta`, 'conta', { shots: all });
  await check(page, `${BASE}/compartilhar`, 'compartilhar', { shots: all });
  const gid = await page.$eval('a[href*="/compartilhar/"]', (a) => a.getAttribute('href'));
  await check(page, new URL(gid, BASE).href, 'compartilhar-detalhe', { shots: [mob[0]] });
  await check(page, `${BASE}/exportar`, 'exportar', { shots: all });
  await check(page, `${BASE}/conta/verificacao`, 'conta-verificacao', { shots: all });
  await check(page, `${BASE}/historico/${new Date(Date.now() - 2 * 864e5).toISOString().slice(0, 10)}`, 'historico-dia', { shots: all });
  await ctx.close();
}

// Profissional e clinica (subdominio)
{
  const { ctx, page } = await session(CLINIC, 'pro@demo.test');
  await check(page, `${CLINIC}/cuidado`, 'cuidado', { shots: [mob[0]] });
  const href = await page.$eval('a[href*="/cuidado/"]', (a) => a.getAttribute('href'));
  await check(page, new URL(href, CLINIC).href, 'cuidado-relatorio', { shots: [mob[0], '1280-light'] });
  await check(page, new URL(href, CLINIC).href + '/exportar', 'cuidado-exportar', { shots: [mob[0]] });
  await ctx.close();
}
{
  const { ctx, page } = await session(CLINIC, 'admin@demo.test');
  await check(page, `${CLINIC}/clinica`, 'clinica', { shots: [mob[0], '1280-light'] });
  await ctx.close();
}

await browser.close();
fs.writeFileSync(`${OUT}/a11y-report.json`, JSON.stringify({ report, problems }, null, 2));
console.log(JSON.stringify(report.filter((r) => r.serious === undefined || r.serious > 0 || r.minor > 0), null, 0));
console.log(problems.length ? `PROBLEMAS (${problems.length}):\n${problems.join('\n')}` : 'OK: 0 serious/critical, sem erro de JS/CSP');
process.exit(problems.length ? 1 : 0);
