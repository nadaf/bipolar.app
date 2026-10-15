// Regressão V4.4: verificação em duas etapas no navegador real (CSP ativa), com axe nos estados novos.
import puppeteer from 'puppeteer-core';
import { spawn, spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { createHmac } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const cfg = path.resolve(here, '../tmp/config.dev.php');
if (!fs.existsSync(cfg)) { console.error('Rode o PHPUnit antes (gera tests/tmp/config.dev.php).'); process.exit(2); }
const php = process.env.PHP_BIN || 'php';
const port = 8600 + Math.floor(Math.random() * 90);
const base = `http://127.0.0.1:${port}`;
const env = { ...process.env, BIPOLAR_CONFIG: cfg };
const srv = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', path.join(root, 'public_html')], { env, stdio: 'ignore' });
await new Promise((r) => setTimeout(r, 800));
const axeSrc = fs.readFileSync(createRequire(import.meta.url).resolve('axe-core/axe.min.js'), 'utf8');

// conta de e-mail e senha criada direto no banco de teste
const email = `mfa${Date.now().toString(36)}@example.com`;
const PW = 'cavalo correto bateria';
const mk = spawnSync(php, ['-r', `require '${root}/app/bootstrap.php'; $p = db(); $p->prepare('INSERT INTO users (name, email, timezone, password_hash, email_verified_at) VALUES (?,?,?,?,UTC_TIMESTAMP())')->execute(['Bia Teste', '${email}', 'America/Cuiaba', pw_hash('${PW}')]); $p->prepare("INSERT INTO oauth_identities (user_id, provider, subject) VALUES (?, 'email', ?)")->execute([(int)$p->lastInsertId(), '${email}']); $p->exec('DELETE FROM rate_limits');`], { env });
if (mk.status !== 0) { console.error(String(mk.stderr) + String(mk.stdout)); process.exit(2); }

const b32 = (s) => { const a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = ''; for (const c of s.replace(/\s/g, '')) bits += a.indexOf(c).toString(2).padStart(5, '0'); const out = []; for (let i = 0; i + 8 <= bits.length; i += 8) out.push(parseInt(bits.slice(i, i + 8), 2)); return Buffer.from(out); };
const totp = (key, t) => { const c = Buffer.alloc(8); c.writeBigUInt64BE(BigInt(Math.floor(t / 30))); const h = createHmac('sha1', key).update(c).digest(); const o = h[h.length - 1] & 15; return String((h.readUInt32BE(o) & 0x7fffffff) % 1e6).padStart(6, '0'); };

const browser = await puppeteer.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox'] });
let fail = 0;
const ok = (c, msg) => { console.log(`${c ? '✓' : '✗'} ${msg}`); if (!c) fail++; };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function axe(page, label) {
  await page.evaluate(axeSrc); // via CDP: funciona com a CSP ativa (sem script inline na página)
  const v = await page.evaluate(async () => {
    // eslint-disable-next-line no-undef
    const r = await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'] } });
    return r.violations.map((x) => `${x.id}: ${x.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
  });
  ok(v.length === 0, `axe ${label}${v.length ? ' — ' + v.join('; ') : ''}`);
}
function watch(page) {
  page.on('pageerror', (e) => { console.log('pageerror', e.message); fail++; });
  page.on('console', (m) => { if (/Content Security Policy|Refused to/i.test(m.text())) { console.log('CSP:', m.text()); fail++; } });
}
async function passwordLogin(page) {
  await page.goto(base + '/', { waitUntil: 'networkidle0' });
  await page.type('#login-email', email);
  await page.type('#login-senha', PW);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('form.auth-form button[type=submit]')]);
}
try {
  // 1) ativar em Conta
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  watch(page);
  await passwordLogin(page);
  ok(new URL(page.url()).pathname === '/', 'sem MFA: entra direto');
  await page.goto(base + '/?aba=conta', { waitUntil: 'networkidle0' });
  ok(await page.$('#form-mfa-start') !== null, 'cartão “Verificação em duas etapas” com o botão de ativar');
  await axe(page, 'Conta com MFA desativada');
  await page.type('#mfa-senha', PW);
  await page.click('#form-mfa-start button[type=submit]');
  await page.waitForSelector('#mfa-setup:not(.hidden)');
  await page.waitForFunction(() => document.getElementById('mfa-qr').complete && document.getElementById('mfa-qr').naturalWidth > 0);
  ok(true, 'QR code (SVG local, data:) carregou com a CSP ativa');
  const secret = await page.$eval('#mfa-secret', (e) => e.textContent);
  ok(/^[A-Z2-7]{4}( [A-Z2-7]{4}){7}$/.test(secret), `chave para digitar à mão: ${secret.slice(0, 9)}…`);
  const key = b32(secret);
  await axe(page, 'Conta: QR code e chave para digitar');
  await page.type('#mfa-code', '000000');
  await page.click('#form-mfa-confirm button[type=submit]');
  await page.waitForFunction(() => /incorreto/.test((document.getElementById('toast') || {}).textContent || ''));
  ok(true, 'código errado: mensagem em pt-BR');
  await page.$eval('#mfa-code', (e) => { e.value = ''; });
  await page.type('#mfa-code', totp(key, Date.now() / 1000));
  await page.click('#form-mfa-confirm button[type=submit]');
  await page.waitForSelector('#mfa-codes:not(.hidden)');
  const codes = await page.$$eval('#mfa-codes-list code', (els) => els.map((e) => e.textContent));
  ok(codes.length === 10 && codes.every((c) => /^[a-z2-9]{5}-[a-z2-9]{5}$/.test(c)), '10 códigos de recuperação exibidos');
  // a sessão foi renovada: o app continua salvando (token CSRF novo repassado ao app.js)
  await page.click('#form-settings button[type=submit]');
  await page.waitForFunction(() => /Preferências salvas/.test((document.getElementById('toast') || {}).textContent || ''), { timeout: 5000 }).then(() => ok(true, 'app.js usa o token novo depois da ativação'), () => ok(false, 'app.js usa o token novo depois da ativação'));
  await page.reload({ waitUntil: 'networkidle0' });
  await page.evaluate(() => { document.getElementById('mfa-codes').classList.remove('hidden'); });
  await axe(page, 'Conta com MFA ativa (gerenciar + lista de códigos)');
  await ctx.close();

  // 2) login em outro navegador: senha → código
  for (const [vp, scheme] of [[{ width: 1280, height: 900 }, 'light'], [{ width: 390, height: 844, isMobile: true, hasTouch: true }, 'dark']]) {
    const c2 = await browser.createBrowserContext();
    const p2 = await c2.newPage();
    watch(p2);
    await p2.setViewport(vp);
    await p2.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: scheme }]);
    await passwordLogin(p2);
    ok(new URL(p2.url()).pathname === '/conta/mfa.php', `${vp.width}px: depois da senha, pede o código`);
    ok(await p2.$eval('#mfa-codigo', (e) => e.getAttribute('autocomplete')) === 'one-time-code', 'campo com autocomplete="one-time-code"');
    await p2.type('#mfa-codigo', '111111');
    await Promise.all([p2.waitForNavigation({ waitUntil: 'networkidle0' }), p2.click('form.auth-form button[type=submit]')]);
    ok(/Código incorreto/.test(await p2.$eval('.flash', (e) => e.textContent)), 'código errado: aviso em pt-BR');
    const scroll = await p2.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
    ok(scroll, `${vp.width}px: sem rolagem horizontal`);
    await axe(p2, `/conta/mfa.php ${scheme}/${vp.width}px (com aviso de erro)`);
    const code = vp.width === 1280 ? totp(key, Date.now() / 1000 + 30) : codes[0];
    await p2.type('#mfa-codigo', code);
    await Promise.all([p2.waitForNavigation({ waitUntil: 'networkidle0' }), p2.click('form.auth-form button[type=submit]')]);
    ok(new URL(p2.url()).pathname === '/' && await p2.$('#boot') !== null, `${vp.width}px: entrou com ${vp.width === 1280 ? 'o código do app' : 'um código de recuperação'}`);
    if (vp.width !== 1280) ok(/Restam 9 códigos/.test(await p2.$eval('.flash', (e) => e.textContent)), 'aviso de códigos restantes no app');
    await c2.close();
  }
} catch (e) { console.log('erro:', e.message); fail++; }
await browser.close();
srv.kill();
console.log(fail ? `\n${fail} falha(s)` : '\nRegressão V4.4 OK');
process.exit(fail ? 1 : 0);
