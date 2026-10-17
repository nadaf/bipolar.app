// Regressão V4.8 (412x915, America/Cuiaba): pensamentos por período, nicotina, Pensamentos de morte
// (PIN, revelar/ocultar, painel de apoio acessível e offline) e compartilhamento com profissional
// (link de convite, lista, pendente → aceitar, visão só leitura). Inclui axe nas telas novas.
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
const shots = process.env.SHOTS || path.resolve(here, '../tmp/v48');
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
try {
  const page = await newPage();
  await page.goto(`${base}/auth/dev.php?u=v48p${run}`, { waitUntil: 'networkidle0' });
  await page.goto(`${base}/?aba=registrar`, { waitUntil: 'networkidle0' });
  const mods = await page.evaluate(() => JSON.parse(document.getElementById('boot').textContent).prefs?.modules || null);
  const all = Object.fromEntries(Object.keys(mods || {}).map((k) => [k, true]));
  let r = await api(page, 'POST', '/api/account.php?action=prefs', { modules: { ...all, pensamentos: true, pensamentos_morte: true, habitos: true }, trusted_contact: { name: 'Mãe', phone: '(65) 99999-1234' } });
  ok(r.status === 200, 'ativar módulos (incl. Pensamentos de morte) e contato de confiança');
  r = await api(page, 'POST', '/api/account.php?action=sexpin', { pin: '2468' });
  ok(r.status === 200, 'definir PIN');
  await page.goto(`${base}/?aba=registrar`, { waitUntil: 'networkidle0' });
  await page.evaluate(() => { const r = document.getElementById('p-manha'); r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); });
  await sleep(700);
  await page.evaluate(() => { const f = document.getElementById('form-day'); f.classList.remove('hidden'); f.querySelectorAll('details').forEach((d) => { d.hidden = false; d.open = true; }); });
  const ui = await page.evaluate(() => ({
    th: !!document.querySelector('[data-mod=pensamentos] input[name=th_grandiose]'),
    racingDay: !!document.querySelector('#form-day input[name=racing_thoughts]'),
    cig: document.getElementById('d-cig')?.getAttribute('step'),
    nic: [...document.querySelectorAll('#d-nic option')].map((o) => o.value).join(','),
    deathInDay: !!document.querySelector('#form-day input[name=th_death]'),
    deathLocked: !document.getElementById('death-locked').classList.contains('hidden'),
  }));
  ok(ui.th && !ui.racingDay, 'bloco Pensamentos por período (acelerados substitui o campo do dia)');
  ok(ui.cig === '1' && ui.nic.includes('vape_pod') && ui.nic.includes('adesivo_chiclete'), 'Nicotina: cigarros inteiros + outra forma ' + ui.nic);
  ok(!ui.deathInDay && ui.deathLocked, 'Pensamentos de morte fora do formulário do dia e bloqueado pelo PIN');
  await page.screenshot({ path: path.join(shots, '01-dia-pensamentos.png'), fullPage: true });
  // salvar pensamentos + nicotina
  const posts = [];
  page.on('request', (rq) => { if (rq.method() === 'POST' && /\/api\/day\.php/.test(rq.url())) posts.push(JSON.parse(rq.postData() || '{}')); });
  await page.evaluate(() => { const f = document.getElementById('form-day'); f.querySelector('#form-day input[name=th_racing][value="2"]').checked = true; f.querySelector('input[name=th_grandiose][value="2"]').checked = true; f.elements.cigarettes.value = '7'; f.elements.nicotine_other.value = 'vape_pod'; f.requestSubmit(); });
  await sleep(1200);
  const pd = posts.find((p) => 'cigarettes' in p);
  ok(pd && pd.cigarettes === 7 && pd.nicotine_other === 'vape_pod', 'salvar envia cigarros/outra forma ' + JSON.stringify(pd || {}));
  ok(posts.some((p) => p.th_racing === 2 && p.th_grandiose === 2), 'salvar envia pensamentos do período');
  // desbloquear e marcar
  await page.type('#death-pin', '2468'); await page.click('#death-unlock'); await sleep(900);
  const unl = await page.evaluate(() => document.getElementById('death-locked').classList.contains('hidden'));
  ok(unl, 'PIN desbloqueia Pensamentos de morte');
  const blurBefore = await page.evaluate(() => document.getElementById('form-death').classList.contains('blurred'));
  await page.click('#death-hide'); await sleep(200);
  const blurAfter = await page.evaluate(() => document.getElementById('form-death').classList.contains('blurred'));
  ok(blurBefore !== blurAfter, 'Revelar/Ocultar alterna');
  await sleep(800);
  ok(await page.evaluate(() => !document.getElementById('death-locked').classList.contains('hidden')), 'Ocultar bloqueia de novo (com PIN)');
  await page.type('#death-pin', '2468'); await page.click('#death-unlock'); await sleep(900);
  await page.evaluate(() => { const b = document.getElementById('death-reveal'); if (b && !b.classList.contains('hidden')) b.click(); });
  await sleep(200);
  ok(await page.evaluate(() => !document.getElementById('form-death').classList.contains('blurred') && document.getElementById('death-locked').classList.contains('hidden')), 'Revelar mostra a seção desbloqueada');
  // offline: o painel abre mesmo sem rede
  await page.setOfflineMode(true);
  await page.evaluate(() => document.querySelector('label[for="d-thd-1"]').click());
  await page.focus('#form-death button[type=submit]');
  await page.keyboard.press('Enter'); await sleep(500);
  const sp = await page.evaluate(() => { const d = document.getElementById('support-panel'); return { open: d.open, role: d.getAttribute('role'), modal: d.getAttribute('aria-modal'), focus: document.activeElement?.id, txt: d.textContent, tel: [...d.querySelectorAll('a[href^="tel:"]')].map((a) => a.getAttribute('href')) }; });
  ok(sp.open && sp.role === 'alertdialog' && sp.modal === 'true', 'painel de apoio abre offline (alertdialog, aria-modal)');
  ok(sp.focus === 'support-title', 'foco vai para o título do painel');
  ok(sp.tel.includes('tel:188') && sp.tel.includes('tel:192') && sp.tel.some((t) => /99999/.test(t)) && /ligue 192 ou vá ao pronto-socorro/.test(sp.txt) && /cvv\.org\.br/.test(await page.evaluate(() => document.getElementById('support-panel').innerHTML)), 'CVV 188, SAMU 192, contato de confiança e aviso de perigo ' + sp.tel.join(','));
  await page.screenshot({ path: path.join(shots, '02-painel-apoio.png') });
  await axe(page, 'painel de apoio', ['#support-panel']);
  await page.keyboard.press('Escape'); await sleep(300);
  const after = await page.evaluate(() => ({ open: document.getElementById('support-panel').open, focus: document.activeElement?.closest('#form-death') !== null }));
  ok(!after.open && after.focus, 'Esc fecha e o foco volta ao formulário');
  ok(await page.evaluate(() => /Sem conexão/.test(document.body.textContent) && !/Failed to fetch/.test(document.body.textContent)), 'sem rede: aviso em português (não salva, painel já foi mostrado)');
  await page.setOfflineMode(false); await sleep(1500);
  // nível 0 não abre
  await page.evaluate(() => { document.querySelector('label[for="d-thd-0"]').click(); document.getElementById('form-death').requestSubmit(); }); await sleep(500);
  ok(!(await page.evaluate(() => document.getElementById('support-panel').open)), 'nível "Não" não abre o painel');
  await axe(page, 'Meu dia (pensamentos, nicotina, morte)', ['#form-day', '#form-death']);

  // compartilhamento: link de convite para e-mail sem conta
  await page.goto(`${base}/?aba=conta`, { waitUntil: 'networkidle0' });
  await page.evaluate(() => document.getElementById('care-card').scrollIntoView());
  await page.type('#care-email', `v48pro${run}@example.com`);
  await page.evaluate(() => document.getElementById('form-care-invite').requestSubmit()); await sleep(1200);
  const lb = await page.evaluate(() => ({ shown: !document.getElementById('care-link-box').classList.contains('hidden'), link: document.getElementById('care-link').value, wa: document.getElementById('care-wa').getAttribute('href'), list: document.getElementById('care-shares').textContent }));
  ok(lb.shown && /convite-profissional\.php\?t=/.test(lb.link) && /^https:\/\/wa\.me\/\?text=/.test(lb.wa), 'link de convite + WhatsApp ' + lb.link.slice(0, 60));
  ok(/aguardando aceite/i.test(lb.list) && /Cancelar convite/.test(lb.list), 'convite pendente aparece na lista');
  await page.screenshot({ path: path.join(shots, '03-convite-link.png'), fullPage: false });
  await axe(page, 'Compartilhar com profissional', ['#care-card']);
  // profissional (outra sessão) por código
  const ctx = await browser.createBrowserContext();
  const pro = await ctx.newPage();
  await pro.setViewport({ width: 412, height: 915, isMobile: true, hasTouch: true });
  pro.on('pageerror', (e) => { console.log('pageerror(pro)', e.message); fail++; });
  await pro.goto(`${base}/auth/dev.php?u=v48d${run}`, { waitUntil: 'networkidle0' });
  await pro.goto(`${base}/?aba=conta`, { waitUntil: 'networkidle0' });
  r = await api(pro, 'POST', '/api/care.php?action=profile', { is_professional: true, kind: 'psiquiatra', reg: 'CRM-MT 1234' });
  ok(r.status === 200, 'profissional se declara');
  await pro.reload({ waitUntil: 'networkidle0' });
  await pro.evaluate(() => document.getElementById('care-code-btn').click()); await sleep(800);
  const code = await pro.evaluate(() => document.getElementById('care-code-out').textContent.trim().slice(0, 9));
  ok(/^[A-Z0-9]{4}-[A-Z0-9]{4}$/.test(code), 'código gerado ' + code);
  r = await api(page, 'POST', '/api/care.php?action=invite', { code, scope: ['humor', 'sono'] });
  ok(r.status === 200, 'paciente convida pelo código');
  await pro.reload({ waitUntil: 'networkidle0' }); await sleep(600);
  const pend = await pro.evaluate(() => document.getElementById('care-pending').textContent);
  ok(/Aceitar/.test(pend), 'convite pendente visível ao profissional');
  await axe(pro, 'Área do profissional', ['#care-pro-card']);
  await pro.evaluate(() => [...document.querySelectorAll('#care-pending button')].find((b) => /Aceitar/.test(b.textContent)).click()); await sleep(1000);
  const pats = await pro.evaluate(() => document.getElementById('care-patients').innerHTML);
  ok(/paciente\.php\?c=\d+/.test(pats), 'Meus pacientes lista o paciente');
  const href = pats.match(/paciente\.php\?c=\d+/)[0];
  await pro.goto(`${base}/${href}`, { waitUntil: 'networkidle0' });
  const pv = await pro.evaluate(() => ({ t: document.body.textContent, inputs: document.querySelectorAll('main form input:not([type=date]):not([type=hidden])').length }));
  ok(/só leitura/.test(pv.t) && !/Pensamentos de morte —/.test(pv.t), 'visão do paciente só leitura, sem categoria sensível');
  await pro.screenshot({ path: path.join(shots, '04-paciente.png'), fullPage: true });
  await axe(pro, 'Visão do paciente (profissional)');
  // revogar → 404 na hora
  await page.reload({ waitUntil: 'networkidle0' });
  const sh = (await api(page, 'GET', '/api/care.php')).json.shares.find((s) => s.status === 'ativo');
  r = await api(page, 'POST', '/api/care.php?action=revoke', { id: sh.id });
  const g = await pro.goto(`${base}/${href}`, { waitUntil: 'networkidle0' });
  ok(r.status === 200 && g.status() === 404, 'revogar corta o acesso na hora');
  // limpeza do PIN
  await api(page, 'POST', '/api/account.php?action=sexpin', { atual: '2468', pin: '' });
  await ctx.close();
} catch (e) { console.log('erro', e); fail++; } finally {
  await browser.close(); srv.kill();
}
console.log(fail ? `FALHOU: ${fail}` : 'OK: regressão V4.8');
process.exit(fail ? 1 : 0);
