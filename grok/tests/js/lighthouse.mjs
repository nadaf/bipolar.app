// Lighthouse (acessibilidade, boas práticas, SEO) + checagem de instalabilidade PWA via Chrome DevTools Protocol.
import puppeteer from 'puppeteer-core';
import lighthouse from 'lighthouse';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const cfg = path.resolve(here, '../tmp/config.dev.php');
const port = 8900 + Math.floor(Math.random() * 90);
const base = `http://127.0.0.1:${port}`;
const srv = spawn(process.env.PHP_BIN || 'php', ['-S', `127.0.0.1:${port}`, '-t', path.join(root, 'public_html')], { env: { ...process.env, BIPOLAR_CONFIG: cfg }, stdio: 'ignore' });
await new Promise((r) => setTimeout(r, 800));
const dbg = 9300 + Math.floor(Math.random() * 90);
const browser = await puppeteer.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox', `--remote-debugging-port=${dbg}`] });
let fail = 0;
const out = {};
const MIN = { accessibility: 1.0, 'best-practices': 0.9, seo: 0.8 };

const p0 = await browser.newPage();
await p0.goto(base + '/auth/dev.php?u=lighthouse', { waitUntil: 'networkidle0' });
await new Promise((r) => setTimeout(r, 1500));
const cdp = await p0.createCDPSession();
const inst = await cdp.send('Page.getInstallabilityErrors');
const errs = inst.installabilityErrors.map((e) => e.errorId);
console.log(`${errs.length ? '✗' : '✓'} PWA instalável${errs.length ? ': ' + errs.join(', ') : ''}`);
if (errs.length) fail++;
const man = await cdp.send('Page.getAppManifest');
console.log(`${man.errors.length ? '✗' : '✓'} manifest sem erros${man.errors.length ? ': ' + JSON.stringify(man.errors) : ''}`);
if (man.errors.length) fail++;
const sw = await p0.evaluate(async () => !!(await navigator.serviceWorker.getRegistration()));
console.log(`${sw ? '✓' : '✗'} service worker registrado`);
if (!sw) fail++;
await p0.reload({ waitUntil: 'networkidle0' });
await p0.setOfflineMode(true);
let offlineOk = false;
try { await p0.reload({ waitUntil: 'domcontentloaded' }); offlineOk = await p0.evaluate(() => !!document.querySelector('main')); } catch { offlineOk = false; }
await p0.setOfflineMode(false);
console.log(`${offlineOk ? '✓' : '✗'} funciona offline (shell em cache)`);
if (!offlineOk) fail++;

for (const url of ['/', '/?aba=historico', '/privacidade.php', '/escala.php', '/profissionais.php', '/comecar.php']) {
  for (const formFactor of ['mobile', 'desktop']) {
    const r = await lighthouse(base + url, {
      port: dbg, output: 'json', logLevel: 'error', onlyCategories: Object.keys(MIN), disableStorageReset: true, formFactor,
      screenEmulation: formFactor === 'desktop' ? { mobile: false, width: 1350, height: 940, deviceScaleFactor: 1, disabled: false } : undefined,
    });
    const sc = Object.fromEntries(Object.entries(r.lhr.categories).map(([k, v]) => [k, v.score]));
    out[`${url} (${formFactor})`] = sc;
    const bad = Object.entries(MIN).filter(([k, m]) => sc[k] < m);
    console.log(`${bad.length ? '✗' : '✓'} ${url} ${formFactor}: ` + Object.entries(sc).map(([k, v]) => `${k} ${Math.round(v * 100)}`).join(', '));
    if (bad.length) {
      fail++;
      for (const [k] of bad) for (const a of r.lhr.categories[k].auditRefs) {
        const au = r.lhr.audits[a.id];
        if (a.weight > 0 && au.score !== null && au.score < 1) console.log(`     - ${k}: ${au.id} — ${au.title}`);
      }
    }
  }
}
fs.writeFileSync(path.resolve(here, '../tmp/lighthouse-scores.json'), JSON.stringify(out, null, 2));
await browser.close();
srv.kill();
process.exit(fail ? 1 : 0);
