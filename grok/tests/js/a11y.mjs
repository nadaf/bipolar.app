// Verificação de acessibilidade com axe-core (WCAG 2.0/2.1/2.2 A e AA) em Chrome headless.
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
const port = 8800 + Math.floor(Math.random() * 100);
const base = `http://127.0.0.1:${port}`;
const php = process.env.PHP_BIN || 'php';
const srv = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', path.join(root, 'public_html')], { env: { ...process.env, BIPOLAR_CONFIG: cfg }, stdio: 'ignore' });
await new Promise((r) => setTimeout(r, 800));
const axeSrc = fs.readFileSync(createRequire(import.meta.url).resolve('axe-core/axe.min.js'), 'utf8');

const browser = await puppeteer.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox'] });
let total = 0;
const runId = Date.now().toString(36);
const report = [];
async function audit(page, label) {
  await page.addScriptTag({ content: axeSrc });
  const res = await page.evaluate(async () => {
    // eslint-disable-next-line no-undef
    const r = await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'] } });
    return r.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.slice(0, 3).map((n) => n.target.join(' ')) }));
  });
  total += res.length;
  report.push({ page: label, violations: res });
  console.log(`${res.length ? '✗' : '✓'} ${label}: ${res.length} violação(ões)`);
  for (const v of res) console.log(`   - [${v.impact}] ${v.id}: ${v.help} → ${v.nodes.join(' | ')}`);
}
for (const scheme of ['light', 'dark']) {
  const page = await browser.newPage();
  await page.setBypassCSP(true);
  await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: scheme }, { name: 'prefers-reduced-motion', value: 'reduce' }]);
  for (const vp of [{ width: 1280, height: 900 }, { width: 390, height: 844, isMobile: true, hasTouch: true }]) {
    await page.setViewport(vp);
    const tag = `${scheme}/${vp.width}px`;
    const client = await page.createCDPSession();
    await client.send('Network.clearBrowserCookies');
    for (const p of ['/', '/privacidade.php', '/escala.php', '/confirmar.php?t=invalido', '/offline.html', '/conta/cadastro.php', '/conta/esqueci.php', '/conta/reenviar.php', '/conta/verificar.php?t=x', '/conta/redefinir.php?t=x']) {
      await page.goto(base + p, { waitUntil: 'networkidle0' });
      await audit(page, `${tag} ${p}`);
    }
    // estados com mensagens: cadastro com erros, senha digitada (medidor), login com erro
    await page.goto(base + '/conta/cadastro.php', { waitUntil: 'networkidle0' });
    await page.type('#cad-senha', 'abc');
    await page.click('button[type=submit].btn-primary');
    await page.waitForSelector('#form-errors');
    await page.type('#cad-senha', 'cavalo correto');
    await new Promise((r) => setTimeout(r, 500));
    await audit(page, `${tag} cadastro (erros + medidor)`);
    await page.goto(base + '/', { waitUntil: 'networkidle0' });
    await page.type('#login-email', 'ninguem@example.com');
    await page.type('#login-senha', 'errada123');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('form.auth-form button[type=submit]')]);
    await audit(page, `${tag} login com erro`);
    // um usuário por passada: o limite de tentativas de PIN (5 em 15 min) é por pessoa
    await page.goto(`${base}/auth/dev.php?u=a11y${runId}${scheme[0]}${vp.width}`, { waitUntil: 'networkidle0' });
    // garante alguns dados para gráficos
    await page.evaluate(async () => {
      const csrf = JSON.parse(document.getElementById('boot').textContent).csrf;
      const d = new Date();
      for (let i = 0; i < 12; i++) {
        const day = new Date(d - i * 864e5).toISOString().slice(0, 10);
        await fetch('/api/entries.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ entry_date: day, period: ['manha', 'tarde', 'noite'][i % 3], mood: (i % 7) - 3, note: 'nota' }) });
      }
    });
    // V2: todos os módulos ligados (inclui Sexualidade), sinais pessoais, medicação e dias que disparam o alerta precoce
    await page.evaluate(async () => {
      const csrf = JSON.parse(document.getElementById('boot').textContent).csrf;
      const post = (u, b) => fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(b) });
      const mods = { sono: true, sol: true, habitos: true, mente: true, estresse: true, medicacao: true, sinais: true, ciclo: true, sexualidade: true, substancias: true };
      await post('/api/account.php?action=prefs', { modules: mods, sex_blur: true, signs: ['Arrumar a casa de madrugada', 'Muitos projetos novos'], action_plan: 'Ligar para minha psiquiatra', meds: [{ name: 'Lítio', dose: '300 mg', time: '21:00' }] });
      const d = new Date();
      for (let i = 0; i < 3; i++) {
        const day = new Date(d - i * 864e5).toISOString().slice(0, 10);
        await post('/api/day.php', { entry_date: day, bed_time: '03:00', wake_time: '06:00', racing_thoughts: 3, fast_speech: 2, sun_minutes: 20, sun_period: 'manha' });
        await post('/api/day.php?action=sex', { entry_date: day, libido: 2, activities: ['nova_parceria'], different_from_usual: 1 });
        await post('/api/day.php?action=subst_add', { entry_date: day, substance: 'cannabis', form: 'vaporizada', amount: 1, period: 'noite', reason: 'dormir', mood_effect: 1, above_usual: i === 0 ? 1 : 0 });
      }
      await post('/api/day.php?action=subst_fav', { favorites: [{ substance: 'cannabis', form: 'vaporizada', amount: 1 }, { substance: 'alcool', amount: 1 }] });
    });
    await page.goto(`${base}/?aba=registrar`, { waitUntil: 'networkidle0' });
    await page.evaluate(() => { document.querySelectorAll('details').forEach((d) => { d.open = true; }); document.getElementById('sex-reveal').click(); document.getElementById('subst-reveal').click(); document.getElementById('su-sub').value = 'outra'; document.getElementById('su-sub').dispatchEvent(new Event('change')); });
    await new Promise((r) => setTimeout(r, 300));
    await audit(page, `${tag} V2/V3 meu dia + sexualidade + substâncias visíveis + alerta precoce`);
    for (const tab of ['registrar', 'historico', 'relatorios', 'lembretes', 'conta']) {
      await page.goto(`${base}/?aba=${tab}`, { waitUntil: 'networkidle0' });
      await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
      await audit(page, `${tag} app:${tab}`);
    }
    await page.goto(base + '/relatorio.php?notas=1&sexualidade=1', { waitUntil: 'networkidle0' });
    await audit(page, `${tag} /relatorio.php`);
    // Sexualidade bloqueada por PIN
    await page.goto(`${base}/?aba=registrar`, { waitUntil: 'networkidle0' });
    await page.evaluate(async () => {
      const csrf = JSON.parse(document.getElementById('boot').textContent).csrf;
      await fetch('/api/account.php?action=sexpin', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ pin: '2468' }) });
    });
    await page.goto(`${base}/?aba=registrar`, { waitUntil: 'networkidle0' });
    await audit(page, `${tag} V2/V3 seções privadas bloqueadas (PIN)`);
    await page.evaluate(async () => {
      const csrf = JSON.parse(document.getElementById('boot').textContent).csrf;
      await fetch('/api/day.php?action=unlock', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ pin: '2468' }) });
      await fetch('/api/account.php?action=sexpin', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ atual: '2468', pin: '' }) });
    });
    // V4: alertas (banner + lista), IA com consentimento (chat, crise), convite de push, registro rápido e tela de bloqueio
    await page.evaluate(async () => {
      const csrf = JSON.parse(document.getElementById('boot').textContent).csrf;
      await fetch('/api/ai.php?action=consent', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ consent: true, categories: ['humor', 'sono'] }) });
    });
    await page.goto(`${base}/?aba=registrar`, { waitUntil: 'networkidle0' });
    await page.evaluate(() => { document.getElementById('push-onboard').classList.remove('hidden'); document.querySelector('[data-ai="checkin"]').click(); });
    await new Promise((r) => setTimeout(r, 800));
    await page.type('#ia-input', 'não aguento mais viver');
    await page.click('#ia-form button[type=submit]');
    await page.waitForSelector('.crisis-box');
    await audit(page, `${tag} V4 registrar: banner de alerta + convite push + IA (crise)`);
    await page.goto(`${base}/?aba=historico`, { waitUntil: 'networkidle0' });
    await page.waitForSelector('#alerts-list:not([aria-busy])');
    await audit(page, `${tag} V4 histórico: alertas com feedback`);
    await page.goto(`${base}/registro-rapido.php?p=tarde`, { waitUntil: 'networkidle0' });
    await audit(page, `${tag} V4 /registro-rapido.php`);
    await page.click('[data-mood="1"]');
    await new Promise((r) => setTimeout(r, 500));
    await audit(page, `${tag} V4 /registro-rapido.php salvo`);
    await page.goto(`${base}/?aba=conta`, { waitUntil: 'networkidle0' });
    await page.evaluate(async () => {
      const csrf = JSON.parse(document.getElementById('boot').textContent).csrf;
      const post = (u, b) => fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(b) });
      await post('/api/account.php?action=sexpin', { pin: '1357' });
      await post('/api/day.php?action=unlock', { pin: '1357' });
      await post('/api/webauthn.php?action=settings', { minutes: 5, app: true, on_hide: true });
      await post('/api/webauthn.php?action=lock', {});
    });
    await page.goto(`${base}/`, { waitUntil: 'networkidle0' });
    await page.waitForSelector('#lock-screen');
    await audit(page, `${tag} V4 tela de bloqueio`);
    await page.type('#unlock-pin', '0000');
    await page.click('#form-unlock-pin button[type=submit]');
    await page.waitForFunction(() => document.getElementById('lock-msg').textContent.length > 0);
    await audit(page, `${tag} V4 tela de bloqueio (PIN errado)`);
    await page.evaluate(async () => {
      const csrf = document.getElementById('lock-screen').dataset.csrf;
      const post = (u, b) => fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(b) });
      await post('/api/day.php?action=unlock', { pin: '1357' });
      await post('/api/webauthn.php?action=settings', { minutes: 15, app: false, on_hide: true });
      await post('/api/account.php?action=sexpin', { atual: '1357', pin: '' });
    });
    await page.goto(`${base}/?aba=conta`, { waitUntil: 'networkidle0' });
    await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
    await audit(page, `${tag} V4 conta: biometria, alertas e IA`);
  }
  await page.close();
}
// Tema escolhido no botão (data-theme) contra o tema do sistema + faixa de crise com contraste AAA
for (const [os, chosen] of [['light', 'dark'], ['dark', 'light'], ['dark', 'dark'], ['light', 'light']]) {
  const page = await browser.newPage();
  await page.setBypassCSP(true);
  await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: os }, { name: 'prefers-reduced-motion', value: 'reduce' }]);
  for (const vp of [{ width: 1280, height: 900 }, { width: 390, height: 844, isMobile: true, hasTouch: true }]) {
    await page.setViewport(vp);
    const tag = `sistema ${os} + tema ${chosen}/${vp.width}px`;
    await page.goto(`${base}/auth/dev.php?u=a11yt${runId}`, { waitUntil: 'networkidle0' });
    await page.evaluate((t) => localStorage.setItem('bp-theme', t), chosen);
    await page.goto(base + '/?aba=registrar', { waitUntil: 'networkidle0' });
    await page.addScriptTag({ content: axeSrc });
    const crisis = await page.evaluate(async () => {
      // eslint-disable-next-line no-undef
      const r = await axe.run({ include: [['.crisis']] }, { runOnly: { type: 'rule', values: ['color-contrast', 'color-contrast-enhanced'] } });
      return { v: r.violations.map((x) => x.id + ': ' + x.nodes.map((n) => n.target.join(' ')).join(' | ')), passes: r.passes.reduce((a, x) => a + x.nodes.length, 0) };
    });
    const okc = crisis.v.length === 0 && crisis.passes > 0;
    if (!okc) total += crisis.v.length || 1;
    console.log(`${okc ? '✓' : '✗'} ${tag} faixa de crise (CVV/SAMU) contraste AAA: ${crisis.passes} nós ok${crisis.v.length ? ' — ' + crisis.v.join('; ') : ''}`);
    await audit(page, `${tag} app:registrar`);
    // modo edição (Hoje → Editar) e erro de data
    await page.evaluate(() => { const b = document.querySelector('#today-list [data-edit], #today-list [data-new]'); if (b) b.click(); });
    await new Promise((r) => setTimeout(r, 600));
    await page.evaluate(() => { const t = document.getElementById('entry-date-br'); t.value = '31/02/2026'; t.dispatchEvent(new Event('change')); });
    await audit(page, `${tag} registrar (edição + data inválida)`);
    // V4.1: calendário pt-BR aberto e diálogo de confirmação
    await page.evaluate(() => { const t = document.getElementById('entry-date-br'); t.value = '10/10/2026'; t.dispatchEvent(new Event('change')); document.querySelector('.date-pick[data-for="entry-date"]').click(); });
    await new Promise((r) => setTimeout(r, 300));
    await audit(page, `${tag} calendário pt-BR`);
    await page.keyboard.press('Escape');
    await page.goto(base + '/como-funcionam-os-alertas.php', { waitUntil: 'networkidle0' });
    await audit(page, `${tag} /como-funcionam-os-alertas.php`);
    await page.goto(base + '/404.php', { waitUntil: 'networkidle0' });
    await audit(page, `${tag} /404.php`);
    await page.goto(base + '/', { waitUntil: 'networkidle0' });
    await page.goto(base + '/privacidade.php', { waitUntil: 'networkidle0' });
    await audit(page, `${tag} /privacidade.php`);
  }
  await page.close();
}
// Teclado: Tab a partir do topo chega ao link "Pular para o conteúdo" e às abas com foco visível
const page = await browser.newPage();
await page.goto(base + '/?aba=registrar', { waitUntil: 'networkidle0' });
await page.keyboard.press('Tab');
const first = await page.evaluate(() => document.activeElement.textContent.trim());
const ok1 = first === 'Pular para o conteúdo';
await page.focus('#tab-registrar');
await page.keyboard.press('ArrowRight');
const ok2 = await page.evaluate(() => document.activeElement.id === 'tab-historico' && !document.getElementById('painel-historico').hidden);
const outline = await page.evaluate(() => getComputedStyle(document.activeElement).outlineStyle);
console.log(`${ok1 ? '✓' : '✗'} teclado: primeiro Tab = "${first}"`);
console.log(`${ok2 ? '✓' : '✗'} teclado: setas navegam entre abas`);
console.log(`${outline !== 'none' ? '✓' : '✗'} foco visível (outline: ${outline})`);
if (!ok1 || !ok2 || outline === 'none') total++;
await browser.close();
srv.kill();
fs.writeFileSync(path.resolve(here, '../tmp/a11y-report.json'), JSON.stringify(report, null, 2));
console.log(`\nTotal de violações: ${total}`);
process.exit(total ? 1 : 0);
