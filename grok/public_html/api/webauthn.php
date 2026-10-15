<?php
/**
 * Biometria do aparelho (WebAuthn / passkeys): cadastrar, listar, remover, desbloquear e entrar.
 * Ações sem sessão: login_options e login_verify (entrar com passkey na página inicial).
 */
require __DIR__ . '/../_boot.php';
require_once APP_ROOT . '/lib/webpush.php';
require_once APP_ROOT . '/lib/webauthn.php';
$action = (string)($_GET['action'] ?? '');
$pdo = db();
$rp = webauthn_rp_id();
$origin = webauthn_origin();

/** @param array<string,mixed> $in */
function wa_field(array $in, string $k): string
{
    $v = $in[$k] ?? '';
    if (!is_string($v) || strlen($v) > 20000) json_out(['erro' => 'Dados da biometria inválidos.'], 422);
    return b64url_decode($v);
}

if (in_array($action, ['login_options', 'login_verify'], true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['erro' => 'Método não permitido.'], 405);
    if (!csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) json_out(['erro' => 'Token CSRF inválido. Recarregue a página.'], 403);
    if (!rate_limit('walogin', 20, 900)) json_out(['erro' => 'Muitas tentativas. Aguarde 15 minutos.'], 429);
    if ($action === 'login_options') {
        json_out(['publicKey' => ['challenge' => b64url(webauthn_challenge('login')), 'rpId' => $rp, 'timeout' => 60000, 'userVerification' => 'required', 'allowCredentials' => []]]);
    }
    $in = json_input();
    try {
        $ch = webauthn_take_challenge('login');
        $id = wa_field($in, 'rawId');
        $st = $pdo->prepare('SELECT * FROM webauthn_credentials WHERE cred_hash = ?');
        $st->execute([hash('sha256', $id)]);
        $c = $st->fetch();
        if (!$c) throw new RuntimeException('Esta passkey não está cadastrada aqui. Entre com e-mail e senha e cadastre em Conta.');
        $n = webauthn_verify_assertion(wa_field($in, 'clientDataJSON'), wa_field($in, 'authenticatorData'), wa_field($in, 'signature'), $ch, $rp, $origin, (string)$c['public_key_pem'], (int)$c['alg'], (int)$c['sign_count']);
    } catch (RuntimeException $e) { json_out(['erro' => $e->getMessage()], 422); }
    $pdo->prepare('UPDATE webauthn_credentials SET sign_count = ?, last_used_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$n, $c['id']]);
    login_user((int)$c['user_id']);
    lock_mark_unlocked();
    json_out(['ok' => true]);
}

// V4.4: passkey como segunda etapa do login por senha (só para a conta com login pendente)
if (in_array($action, ['mfa_options', 'mfa_verify'], true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['erro' => 'Método não permitido.'], 405);
    if (!csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) json_out(['erro' => 'Token CSRF inválido. Recarregue a página.'], 403);
    $pend = mfa_pending();
    if (!$pend) json_out(['erro' => 'O tempo para confirmar acabou. Entre de novo com e-mail e senha.'], 401);
    if (!rate_limit('wamfa', 20, 900)) json_out(['erro' => 'Muitas tentativas. Aguarde 15 minutos.'], 429);
    $st = $pdo->prepare('SELECT credential_id FROM webauthn_credentials WHERE user_id = ?');
    $st->execute([$pend['uid']]);
    $allow = array_map(function (array $r): array { return ['type' => 'public-key', 'id' => $r['credential_id'], 'transports' => ['internal', 'hybrid']]; }, $st->fetchAll());
    if (!$allow) json_out(['erro' => 'Nenhuma biometria cadastrada nesta conta. Use o código do app autenticador.'], 404);
    if ($action === 'mfa_options') {
        json_out(['publicKey' => ['challenge' => b64url(webauthn_challenge('mfa')), 'rpId' => $rp, 'timeout' => 60000, 'userVerification' => 'required', 'allowCredentials' => $allow]]);
    }
    $in = json_input();
    try {
        $ch = webauthn_take_challenge('mfa');
        $st = $pdo->prepare('SELECT * FROM webauthn_credentials WHERE cred_hash = ? AND user_id = ?');
        $st->execute([hash('sha256', wa_field($in, 'rawId')), $pend['uid']]);
        $c = $st->fetch();
        if (!$c) throw new RuntimeException('Esta biometria não pertence a esta conta.');
        $n = webauthn_verify_assertion(wa_field($in, 'clientDataJSON'), wa_field($in, 'authenticatorData'), wa_field($in, 'signature'), $ch, $rp, $origin, (string)$c['public_key_pem'], (int)$c['alg'], (int)$c['sign_count']);
    } catch (RuntimeException $e) {
        $_SESSION['mfa_pending']['tries'] = $pend['tries'] + 1;
        json_out(['erro' => $e->getMessage()], 422);
    }
    $pdo->prepare('UPDATE webauthn_credentials SET sign_count = ?, last_used_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$n, $c['id']]);
    unset($_SESSION['mfa_pending']);
    login_user((int)$c['user_id']);
    lock_mark_unlocked();
    json_out(['ok' => true]);
}

define('ALLOW_LOCKED', true); // desbloquear precisa funcionar com o app bloqueado
$u = require_user_api();
$locked = app_locked($u);
if ($locked && !in_array($action, ['auth_options', 'auth_verify', 'lock'], true)) json_out(['erro' => 'App bloqueado.', 'bloqueado_app' => true], 423);

if ($action === 'list') {
    $st = $pdo->prepare('SELECT id, name, alg, created_at, last_used_at FROM webauthn_credentials WHERE user_id = ? ORDER BY created_at');
    $st->execute([$u['id']]);
    json_out(['credentials' => $st->fetchAll(), 'lock' => lock_settings($u), 'has_pin' => !empty($u['sex_pin_hash']), 'rp_id' => $rp]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['erro' => 'Método não permitido.'], 405);

if ($action === 'lock') { lock_now(); json_out(['ok' => true]); }

if ($action === 'reg_options') {
    // Cadastrar um aparelho novo exige o desbloqueio recente se já houver PIN/biometria (evita que alguém com o celular destravado cadastre o próprio dedo)
    // V4.1: confirmação com PIN ou senha da conta na própria tela (também para o primeiro aparelho).
    if (lock_has_factor($u) && !private_unlocked($u)) {
        $conf = (string)(json_input()['confirmacao'] ?? '');
        $first = webauthn_count($u) === 0;
        $ask = $first ? 'Para cadastrar a biometria, confirme que é você com seu PIN ou com a senha da conta.' : 'Para cadastrar mais um aparelho, confirme que é você com seu PIN ou com a senha da conta.';
        if ($conf === '') json_out(['erro' => $ask, 'reauth' => true], 423);
        if (!rate_limit('wa_reauth_' . $u['id'], 5, 900)) json_out(['erro' => 'Muitas tentativas. Aguarde 15 minutos.'], 429);
        $ok = (!empty($u['sex_pin_hash']) && password_verify($conf, (string)$u['sex_pin_hash']))
            || (!empty($u['password_hash']) && password_verify($conf, (string)$u['password_hash']));
        if (!$ok) json_out(['erro' => 'PIN ou senha incorretos.', 'reauth' => true], 422);
        lock_mark_unlocked();
    }
    $st = $pdo->prepare('SELECT credential_id FROM webauthn_credentials WHERE user_id = ?');
    $st->execute([$u['id']]);
    $exclude = array_map(function (array $r): array { return ['type' => 'public-key', 'id' => $r['credential_id']]; }, $st->fetchAll());
    json_out(['publicKey' => [
        'challenge' => b64url(webauthn_challenge('reg')),
        'rp' => ['id' => $rp, 'name' => 'Bipolar'],
        'user' => ['id' => b64url(hash('sha256', 'bp-user-' . $u['id'] . '-' . cfg('app_url'), true)), 'name' => (string)($u['email'] ?: 'conta-' . $u['id']), 'displayName' => (string)($u['name'] ?: 'Minha conta')],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
        'authenticatorSelection' => ['authenticatorAttachment' => 'platform', 'residentKey' => 'preferred', 'userVerification' => 'required'],
        'attestation' => 'none', 'timeout' => 60000, 'excludeCredentials' => $exclude,
    ]]);
}
if ($action === 'reg_verify') {
    if (!rate_limit('wareg_' . $u['id'], 10, 3600)) json_out(['erro' => 'Muitas tentativas.'], 429);
    $in = json_input();
    try {
        $r = webauthn_verify_registration(wa_field($in, 'clientDataJSON'), wa_field($in, 'attestationObject'), webauthn_take_challenge('reg'), $rp, $origin);
    } catch (RuntimeException $e) { json_out(['erro' => $e->getMessage()], 422); }
    $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 100)) ?: 'Aparelho ' . date('d/m/Y');
    $st = $pdo->prepare('SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = ?');
    $st->execute([$u['id']]);
    if ((int)$st->fetchColumn() >= 10) json_out(['erro' => 'Limite de 10 aparelhos. Remova um antes.'], 422);
    try {
        $pdo->prepare('INSERT INTO webauthn_credentials (user_id, cred_hash, credential_id, public_key_pem, alg, sign_count, name) VALUES (?,?,?,?,?,?,?)')
            ->execute([$u['id'], hash('sha256', $r['credentialId']), b64url($r['credentialId']), $r['pem'], $r['alg'], $r['signCount'], $name]);
    } catch (PDOException $e) { json_out(['erro' => 'Este aparelho já está cadastrado.'], 422); }
    lock_mark_unlocked();
    json_out(['ok' => true]);
}
if ($action === 'delete') {
    if (!private_unlocked($u)) json_out(['erro' => 'Para remover um aparelho, desbloqueie antes (PIN ou biometria).', 'bloqueado' => true], 423);
    $st = $pdo->prepare('DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?');
    $st->execute([(int)(json_input()['id'] ?? 0), $u['id']]);
    if ($st->rowCount() < 1) json_out(['erro' => 'Aparelho não encontrado.'], 404); // V4.4
    json_out(['ok' => true]);
}
if ($action === 'rename') {
    $in = json_input();
    $st = $pdo->prepare('SELECT id FROM webauthn_credentials WHERE id = ? AND user_id = ?'); // V4.4: dono primeiro
    $st->execute([(int)($in['id'] ?? 0), $u['id']]);
    if (!$st->fetchColumn()) json_out(['erro' => 'Aparelho não encontrado.'], 404);
    $pdo->prepare('UPDATE webauthn_credentials SET name = ? WHERE id = ? AND user_id = ?')->execute([trim(mb_substr((string)($in['name'] ?? ''), 0, 100)) ?: 'Aparelho', (int)($in['id'] ?? 0), $u['id']]);
    json_out(['ok' => true]);
}
if ($action === 'settings') {
    $in = json_input();
    $l = ['minutes' => max(1, min(240, (int)($in['minutes'] ?? 15))), 'app' => !empty($in['app']), 'on_hide' => !empty($in['on_hide'])];
    if ($l['app'] && !lock_has_factor($u)) json_out(['erro' => 'Cadastre a biometria ou um PIN antes de bloquear o app inteiro.'], 422);
    prefs_patch($pdo, $u, ['lock' => $l]);
    lock_mark_unlocked();
    json_out(['ok' => true, 'lock' => $l]);
}
if ($action === 'auth_options') {
    $st = $pdo->prepare('SELECT credential_id FROM webauthn_credentials WHERE user_id = ?');
    $st->execute([$u['id']]);
    $allow = array_map(function (array $r): array { return ['type' => 'public-key', 'id' => $r['credential_id'], 'transports' => ['internal', 'hybrid']]; }, $st->fetchAll());
    if (!$allow) json_out(['erro' => 'Nenhum aparelho com biometria cadastrado.'], 404);
    json_out(['publicKey' => ['challenge' => b64url(webauthn_challenge('auth')), 'rpId' => $rp, 'timeout' => 60000, 'userVerification' => 'required', 'allowCredentials' => $allow]]);
}
if ($action === 'auth_verify') {
    if (!rate_limit('waauth_' . $u['id'], 20, 900)) json_out(['erro' => 'Muitas tentativas. Aguarde 15 minutos.'], 429);
    $in = json_input();
    try {
        $ch = webauthn_take_challenge('auth');
        $id = wa_field($in, 'rawId');
        $st = $pdo->prepare('SELECT * FROM webauthn_credentials WHERE cred_hash = ? AND user_id = ?');
        $st->execute([hash('sha256', $id), $u['id']]);
        $c = $st->fetch();
        if (!$c) throw new RuntimeException('Aparelho não reconhecido.');
        $n = webauthn_verify_assertion(wa_field($in, 'clientDataJSON'), wa_field($in, 'authenticatorData'), wa_field($in, 'signature'), $ch, $rp, $origin, (string)$c['public_key_pem'], (int)$c['alg'], (int)$c['sign_count']);
    } catch (RuntimeException $e) { json_out(['erro' => $e->getMessage()], 422); }
    $pdo->prepare('UPDATE webauthn_credentials SET sign_count = ?, last_used_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$n, $c['id']]);
    lock_mark_unlocked();
    json_out(['ok' => true]);
}
json_out(['erro' => 'Ação inválida.'], 400);
