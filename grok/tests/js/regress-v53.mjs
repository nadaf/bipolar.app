// Regressão V5.2.1 (0.5.3-beta): o aviso de consentimento SOME depois de "Aceitar" em qualquer cenário.
// Causa do bug em produção: a classe ".consent" do aviso já existia no checkbox LGPD do cadastro, que virava uma
// caixa flutuante fixa no rodapé e "não sumia". Também cobre: armazenamento local bloqueado (cookie bp_consent),
// HTML antigo (class="consent") com JS novo, aviso acima da navegação inferior e sem cobrir o fim da página.
// Nenhuma requisição real vai ao Google (interceptadas, gtag.js vira um stub).
import puppeteer from 'puppeteer-core';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';
const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const cfg = path.resolve(here, '../tmp/config.dev.php');
if (!fs.existsSync(cfg)) { console.error('Rode o PHPUnit antes (gera tests/tmp/config.dev.php).'); process.exit(2); }
if (!fs.existsSync(path.join(root, 'app/site-main.php'))) { console.log('app/site-main.php ausente (pacote público): GA desligado, teste pulado.'); process.exit(0); }
const port = 8800 + Math.floor(Math.random() * 90);
const base = 'http://bipolar.app.br';
const srv = spawn(process.env.PHP_BIN || 'php', ['-S', `127.0.0.1:${port}`, '-t', path.join(root, 'public_html')], { env: { ...process.env, BIPOLAR_CONFIG: cfg }, stdio: 'ignore' });
await new Promise((r) => setTimeout(r, 800));
const browser = await puppeteer.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', headless: true,
  args: ['--no-sandbox', `--host-resolver-rules=MAP bipolar.app.br 127.0.0.1:${port}`] });
let fail = 0;
const ok = (c, msg) => { console.log(`${c ? '✓' : '✗'} ${msg}`); if (!c) fail++; };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const run = Date.now().toString(36);
const MOBILE = { width: 412, height: 915, deviceScaleFactor: 1, isMobile: true, hasTouch: true };
const DESKTOP = { width: 1280, height: 800, deviceScaleFactor: 1 };

async function newPage({ viewport = MOBILE, noStorage = false, oldMarkup = false } = {}) {
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  await page.setViewport(viewport);
  page.on('pageerror', (e) => { console.log('pageerror', e.message); fail++; });
  page.on('console', (m) => { if (/Content Security Policy|Refused to/i.test(m.text())) { console.log('CSP:', m.text()); fail++; } });
  if (noStorage) {
    await page.evaluateOnNewDocument(() => {
      Object.defineProperty(window, 'localStorage', { configurable: true, get() { throw new DOMException('bloqueado', 'SecurityError'); } });
    });
  }
  if (oldMarkup) {
    // HTML da 0.5.2 (class="consent", sem is-hidden) com os assets novos: reescreve o aviso assim que é lido do HTML,
    // antes de o analytics.js (defer) rodar.
    await page.evaluateOnNewDocument(() => {
      new MutationObserver((ms, mo) => {
        const b = document.getElementById('consent-banner');
        if (!b) return;
        b.className = 'consent';
        const a = b.querySelector('.ga-consent-actions'); if (a) a.className = 'consent-actions';
        if (a) mo.disconnect();
      }).observe(document, { childList: true, subtree: true });
    });
  }
  await page.setRequestInterception(true);
  page.on('request', (req) => {
    const u = new URL(req.url());
    if (/(^|\.)(google-analytics\.com|analytics\.google\.com|googletagmanager\.com|doubleclick\.net|google\.com)$/.test(u.hostname)) {
      if (u.pathname === '/gtag/js') return req.respond({ status: 200, contentType: 'application/javascript', body: 'window.__gtagStub = 1;' });
      return req.respond({ status: 204, body: '' });
    }
    req.continue();
  });
  return { page, ctx };
}
const state = (page) => page.evaluate(() => {
  const b = document.getElementById('consent-banner');
  const fixed = [...document.querySelectorAll('body *')].filter((e) => {
    const s = getComputedStyle(e); const r = e.getBoundingClientRect();
    return s.position === 'fixed' && s.display !== 'none' && s.visibility !== 'hidden' && r.width > 0 && r.height > 0 && Number(s.zIndex) >= 60;
  }).map((e) => e.id || e.className || e.tagName);
  return { hidden: b.hidden, display: getComputedStyle(b).display, cls: b.className, fixed, cookie: document.cookie };
});
const topmost = (page, sel) => page.$eval(sel, (el) => { const r = el.getBoundingClientRect(); const t = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2); return !!t && (t === el || el.contains(t)); });

try {
  // 1) cadastro: o checkbox LGPD (".consent") não pode virar caixa flutuante; depois de Aceitar, nada fixo sobra.
  {
    const { page, ctx } = await newPage();
    await page.goto(`${base}/conta/cadastro.php`, { waitUntil: 'networkidle0' });
    ok(await page.$eval('label.consent', (l) => getComputedStyle(l).position) === 'static', 'cadastro: checkbox LGPD fica no formulário (não é fixo)');
    let s = await state(page);
    ok(!s.hidden && s.display !== 'none', 'cadastro: aviso visível antes');
    ok(await topmost(page, '#consent-accept'), 'cadastro: "Aceitar" é clicável (nada por cima)');
    await page.tap('#consent-accept'); await sleep(250);
    s = await state(page);
    ok(s.hidden && s.display === 'none' && /is-hidden/.test(s.cls), 'cadastro: Aceitar esconde o aviso (hidden + is-hidden)');
    ok(s.fixed.length === 0, 'cadastro: nenhuma caixa fixa sobra na tela depois de Aceitar (' + s.fixed.join(',') + ')');
    ok(/bp_consent=granted/.test(s.cookie) && await page.evaluate(() => localStorage.getItem('bp-analytics-consent')) === 'granted', 'cadastro: escolha em localStorage e no cookie bp_consent');
    await page.reload({ waitUntil: 'networkidle0' });
    s = await state(page);
    ok(s.hidden && s.display === 'none' && s.fixed.length === 0, 'cadastro: continua escondido depois de recarregar');
    await ctx.close();
  }
  // 2) armazenamento local bloqueado: a escolha fica no cookie e o aviso não volta.
  for (const choice of ['accept', 'deny']) {
    const { page, ctx } = await newPage({ noStorage: true });
    await page.goto(`${base}/`, { waitUntil: 'networkidle0' });
    let s = await state(page);
    ok(!s.hidden, `sem localStorage (${choice}): aviso visível`);
    await page.tap(`#consent-${choice}`); await sleep(250);
    s = await state(page);
    ok(s.hidden && s.display === 'none', `sem localStorage (${choice}): aviso some`);
    ok(new RegExp('bp_consent=' + (choice === 'accept' ? 'granted' : 'denied')).test(s.cookie), `sem localStorage (${choice}): cookie bp_consent gravado`);
    await page.reload({ waitUntil: 'networkidle0' });
    s = await state(page);
    ok(s.hidden && s.display === 'none', `sem localStorage (${choice}): aviso não volta depois de recarregar`);
    await ctx.close();
  }
  // 3) HTML antigo (class="consent") com CSS/JS novos: aviso estilizado pelo ID e some ao aceitar.
  {
    const { page, ctx } = await newPage({ viewport: DESKTOP, oldMarkup: true });
    await page.goto(`${base}/`, { waitUntil: 'networkidle0' });
    ok(await page.$eval('#consent-banner', (b) => b.className.includes('consent') && getComputedStyle(b).position === 'fixed'), 'HTML antigo: aviso estilizado (fixo) pelo ID');
    await page.click('#consent-accept'); await sleep(250);
    const s = await state(page);
    ok(s.hidden && s.display === 'none' && s.fixed.length === 0, 'HTML antigo: Aceitar esconde o aviso');
    await ctx.close();
  }
  // 4) desktop: com o aviso aberto, o fim da página (rodapé) não fica escondido atrás dele.
  {
    const { page, ctx } = await newPage({ viewport: DESKTOP });
    await page.goto(`${base}/`, { waitUntil: 'networkidle0' });
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight)); await sleep(150);
    ok(await topmost(page, 'footer [data-consent-open]'), 'desktop: rodapé alcançável com o aviso aberto');
    await page.evaluate(() => window.scrollTo(0, 0));
    ok(await topmost(page, 'form button[type=submit]'), 'desktop: botão Entrar não fica coberto pelo aviso');
    await ctx.close();
  }
  // 5) app logado no celular: aviso acima da navegação inferior, abas clicáveis, some ao aceitar.
  {
    const { page, ctx } = await newPage();
    await page.goto(`${base}/auth/dev.php?u=v53${run}`, { waitUntil: 'networkidle0' });
    await page.goto(`${base}/?aba=registrar`, { waitUntil: 'networkidle0' });
    const geo = await page.evaluate(() => ({ b: document.getElementById('consent-banner').getBoundingClientRect().bottom, t: document.querySelector('.tabs').getBoundingClientRect().top }));
    ok(geo.b <= geo.t + 1, `app celular: aviso acima da navegação inferior (${Math.round(geo.b)} ≤ ${Math.round(geo.t)})`);
    ok(await topmost(page, '#tab-historico'), 'app celular: abas continuam clicáveis com o aviso aberto');
    ok(await topmost(page, '#consent-accept'), 'app celular: "Aceitar" clicável');
    await page.tap('#consent-accept'); await sleep(250);
    let s = await state(page);
    ok(s.hidden && s.display === 'none' && s.fixed.length === 0, 'app celular: Aceitar esconde o aviso');
    ok(await page.evaluate(() => !document.documentElement.classList.contains('ga-consent-open')), 'app celular: espaço extra removido');
    await page.reload({ waitUntil: 'networkidle0' });
    s = await state(page);
    ok(s.hidden && s.display === 'none', 'app celular: continua escondido depois de recarregar');
    // reabrir pelo rodapé/Conta e fechar de novo
    await page.evaluate(() => document.querySelector('[data-consent-open]').click()); await sleep(150);
    ok(!(await state(page)).hidden, 'reabrir pelo botão "Cookies e estatísticas de uso"');
    await page.evaluate(() => document.getElementById('consent-deny').click()); await sleep(150);
    s = await state(page);
    ok(s.hidden && s.display === 'none' && /bp_consent=denied/.test(s.cookie), 'Recusar depois: some e grava denied');
    await ctx.close();
  }
} catch (e) {
  console.log('ERRO', e); fail++;
} finally {
  await browser.close();
  srv.kill();
}
console.log(fail ? `\n${fail} falha(s)` : '\nV5.2.1: tudo certo');
process.exit(fail ? 1 : 0);
