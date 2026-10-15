<?php
/**
 * V4.4: Conta → Verificação em duas etapas (TOTP + códigos de recuperação).
 * GET  status
 * POST start      {senha}            → segredo novo (QR + texto), guardado cifrado só na sessão até a confirmação
 * POST confirm    {codigo}           → ativa, devolve os 10 códigos de recuperação (única vez em claro)
 * POST disable    {senha, codigo}    → desativa (código do app ou de recuperação)
 * POST regen      {senha, codigo}    → novos códigos de recuperação
 */
require __DIR__ . '/../_boot.php';
require_once APP_ROOT . '/lib/qr.php';
$u = require_user_api();
$pdo = db();
$uid = (int)$u['id'];
$action = (string)($_GET['action'] ?? 'status');

$status = function () use ($pdo, $u, $uid): array {
    $on = mfa_enabled($u);
    return ['available' => mfa_available(), 'eligible' => mfa_eligible($u), 'enabled' => $on,
        'enabled_at' => $on ? (string)$u['mfa_enabled_at'] : null, 'recovery_left' => $on ? mfa_recovery_left($pdo, $uid) : 0];
};
if ($_SERVER['REQUEST_METHOD'] === 'GET') json_out($status());
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['erro' => 'Método não permitido.'], 405);
if (!mfa_eligible($u)) json_out(['erro' => 'A verificação em duas etapas vale para contas com e-mail e senha. Quem entra com Google, Microsoft ou X ativa a verificação em duas etapas na própria conta do provedor.'], 422);
$in = json_input();
$senha = is_string($in['senha'] ?? null) ? $in['senha'] : '';
$codigo = is_string($in['codigo'] ?? null) ? mb_substr($in['codigo'], 0, 40) : '';
$checkPassword = function () use ($u, $senha): void {
    if ($senha === '' || !password_verify($senha, (string)$u['password_hash'])) json_out(['erro' => 'Senha incorreta.', 'campo' => 'senha'], 422);
};

if ($action === 'start') {
    if (!mfa_available()) json_out(['erro' => MFA_NO_KEY_MSG], 503);
    if (mfa_enabled($u)) json_out(['erro' => 'A verificação em duas etapas já está ativa.'], 409);
    if (!rate_limit('mfa_setup|' . $uid, 10, 900, false)) json_out(['erro' => 'Muitas tentativas. Aguarde 15 minutos.'], 429);
    $checkPassword();
    $b32 = mfa_new_secret();
    $_SESSION['mfa_setup'] = ['enc' => mfa_encrypt_secret($uid, $b32), 't' => time()];
    $uri = mfa_otpauth_uri($b32, (string)($u['email'] ?: 'conta-' . $uid));
    json_out(['ok' => true, 'secret' => mfa_secret_display($b32), 'uri' => $uri,
        'qr' => 'data:image/svg+xml;base64,' . base64_encode(qr_svg($uri)), 'issuer' => MFA_ISSUER, 'account' => (string)$u['email']]);
}
if ($action === 'confirm') {
    if (!mfa_available()) json_out(['erro' => MFA_NO_KEY_MSG], 503);
    if (mfa_enabled($u)) json_out(['erro' => 'A verificação em duas etapas já está ativa.'], 409);
    $s = $_SESSION['mfa_setup'] ?? null;
    if (!is_array($s) || time() - (int)$s['t'] > 900) json_out(['erro' => 'O cadastro expirou. Comece de novo.'], 410);
    if (!mfa_rate_ok($uid)) json_out(['erro' => 'Muitas tentativas. Aguarde 15 minutos.'], 429);
    $b32 = mfa_decrypt_secret($uid, (string)$s['enc']);
    $key = $b32 === null ? null : base32_decode($b32);
    $step = $key === null ? null : totp_match($key, (string)preg_replace('/\s/', '', $codigo), time());
    if ($step === null || $b32 === null) json_out(['erro' => 'Código incorreto. Confira se o horário do celular está automático e digite o código que aparece agora.', 'campo' => 'codigo'], 422);
    $codes = mfa_enable($pdo, $uid, $b32, $step);
    unset($_SESSION['mfa_setup']);
    login_user($uid); // nova sessão (epoch novo): os outros aparelhos precisam entrar de novo, já com o código
    mfa_notify($u, 'on');
    json_out(['ok' => true, 'recovery_codes' => $codes, 'csrf' => csrf_token()]);
}
if ($action === 'disable' || $action === 'regen') {
    if (!mfa_enabled($u)) json_out(['erro' => 'A verificação em duas etapas não está ativa.'], 409);
    if (!mfa_rate_ok($uid)) json_out(['erro' => 'Muitas tentativas. Aguarde 15 minutos.'], 429);
    $checkPassword();
    if (mfa_verify_code($pdo, $u, $codigo) === null) json_out(['erro' => 'Código incorreto ou já usado.', 'campo' => 'codigo'], 422);
    if ($action === 'disable') {
        mfa_disable($pdo, $uid);
        mfa_notify($u, 'off');
        json_out(['ok' => true]);
    }
    $codes = mfa_generate_recovery_codes($pdo, $uid);
    mfa_notify($u, 'codes');
    json_out(['ok' => true, 'recovery_codes' => $codes]);
}
json_out(['erro' => 'Ação inválida.'], 400);
