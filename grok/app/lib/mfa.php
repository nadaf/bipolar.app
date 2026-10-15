<?php
/**
 * V4.4: verificação em duas etapas (MFA) para contas de e-mail e senha.
 * - TOTP (RFC 6238: HMAC-SHA1, 6 dígitos, passo de 30 s), compatível com Google Authenticator, Microsoft Authenticator, Authy e Aegis.
 * - Janela de ±1 passo, com proteção contra reuso (o último passo aceito fica gravado e só passos maiores valem).
 * - Segredo cifrado em repouso (libsodium secretbox) com uma subchave derivada de 'ai.secret_key'. Sem essa chave, não dá para ativar.
 * - 10 códigos de recuperação de uso único; só o SHA-256 (com o id da pessoa) fica no banco.
 * - Logins por Google/Microsoft/X não passam por aqui: a verificação em duas etapas fica a cargo do provedor.
 */
require_once __DIR__ . '/ai_providers.php';

const MFA_PERIOD = 30;
const MFA_DIGITS = 6;
const MFA_WINDOW = 1;
const MFA_RECOVERY_COUNT = 10;
const MFA_PENDING_TTL = 300;      // segundos para digitar o código depois da senha
const MFA_MAX_TRIES = 5;          // tentativas por login pendente (depois, senha de novo)
const MFA_USER_LIMIT = 10;        // tentativas de código por conta a cada 15 min (login, ativação, desativação)
const MFA_ISSUER = 'Bipolar';
const MFA_NO_KEY_MSG = 'A verificação em duas etapas ainda não está disponível neste servidor: o responsável precisa preencher ai.secret_key no config.php.';

/* ------------------------------------------------------------------ base32 (RFC 4648, sem "=") */
function base32_encode(string $bin): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $bin === '' ? '' : $out;
}
function base32_decode(string $s): ?string
{
    $s = strtoupper((string)preg_replace('/[\s=-]/', '', $s));
    if ($s === '' || !preg_match('/^[A-Z2-7]+$/', $s)) return null;
    $bits = '';
    foreach (str_split($s) as $c) $bits .= str_pad(decbin((int)strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr((int)bindec($byte));
    return $out;
}

/* ------------------------------------------------------------------ HOTP/TOTP (RFC 4226 / 6238) */
function hotp(string $key, int $counter, int $digits = MFA_DIGITS, string $algo = 'sha1'): string
{
    $h = hash_hmac($algo, pack('J', $counter), $key, true);
    $o = ord($h[strlen($h) - 1]) & 0x0f;
    $bin = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}
function totp_step(int $time, int $period = MFA_PERIOD): int { return intdiv($time, $period); }
function totp_code(string $key, int $time, int $digits = MFA_DIGITS, string $algo = 'sha1', int $period = MFA_PERIOD): string
{
    return hotp($key, totp_step($time, $period), $digits, $algo);
}
/**
 * Confere o código na janela ±$window. Devolve o passo aceito ou null.
 * Só aceita passos maiores que $lastStep (proteção contra reuso).
 */
function totp_match(string $key, string $code, int $time, ?int $lastStep = null, int $window = MFA_WINDOW): ?int
{
    if (!preg_match('/^\d{' . MFA_DIGITS . '}$/', $code)) return null;
    $now = totp_step($time);
    $found = null;
    for ($d = -$window; $d <= $window; $d++) {
        $s = $now + $d;
        // percorre a janela inteira (tempo constante) e guarda o passo que bate
        if (hash_equals(hotp($key, $s), $code) && ($lastStep === null || $s > $lastStep)) $found = $found ?? $s;
    }
    return $found;
}

/* ------------------------------------------------------------------ cifra do segredo */
/** Subchave só para MFA, derivada de ai.secret_key (BLAKE2b com contexto). null = MFA indisponível. */
function mfa_key(): ?string
{
    $k = ai_server_key();
    if ($k === null || !function_exists('sodium_crypto_generichash')) return null;
    return sodium_crypto_generichash('bipolar-mfa-totp-v1', $k, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
}
function mfa_available(): bool { return mfa_key() !== null; }

/** "m1:" + base64(nonce || secretbox("<uid>:<segredo base32>")). O id amarra o segredo à conta. */
function mfa_encrypt_secret(int $uid, string $b32): string
{
    $k = mfa_key();
    if ($k === null) throw new RuntimeException(MFA_NO_KEY_MSG);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'm1:' . base64_encode($nonce . sodium_crypto_secretbox($uid . ':' . $b32, $nonce, $k));
}
function mfa_decrypt_secret(int $uid, string $enc): ?string
{
    $k = mfa_key();
    if ($k === null || strpos($enc, 'm1:') !== 0) return null;
    $bin = base64_decode(substr($enc, 3), true);
    if (!is_string($bin) || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $p = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $k);
    if (!is_string($p) || strpos($p, $uid . ':') !== 0) return null;
    return substr($p, strlen($uid . ':'));
}
function mfa_new_secret(): string { return base32_encode(random_bytes(20)); }

/* ------------------------------------------------------------------ estado da conta */
/** @param array<string,mixed> $u */
function mfa_eligible(array $u): bool { return !empty($u['password_hash']); }
/** @param array<string,mixed> $u */
function mfa_enabled(array $u): bool { return !empty($u['mfa_enabled_at']) && !empty($u['mfa_totp_secret_enc']); }

function mfa_otpauth_uri(string $b32, string $account): string
{
    $label = rawurlencode(MFA_ISSUER) . ':' . rawurlencode(mb_substr($account, 0, 120));
    return 'otpauth://totp/' . $label . '?' . http_build_query(['secret' => $b32, 'issuer' => MFA_ISSUER, 'algorithm' => 'SHA1', 'digits' => MFA_DIGITS, 'period' => MFA_PERIOD], '', '&', PHP_QUERY_RFC3986);
}
/** Segredo em grupos de 4 para digitar à mão. */
function mfa_secret_display(string $b32): string { return trim(chunk_split($b32, 4, ' ')); }

/* ------------------------------------------------------------------ códigos de recuperação */
function mfa_normalize_code(string $c): string { return strtolower((string)preg_replace('/[\s-]/', '', $c)); }
function mfa_recovery_hash(int $uid, string $code): string { return hash('sha256', 'bp-mfa-rc|' . $uid . '|' . mfa_normalize_code($code)); }

/**
 * Gera 10 códigos novos (apaga os anteriores). Devolve em claro, só desta vez.
 * @return list<string>
 */
function mfa_generate_recovery_codes(PDO $pdo, int $uid): array
{
    $alpha = '23456789abcdefghjkmnpqrstuvwxyz'; // sem 0/o, 1/l/i
    $codes = [];
    while (count($codes) < MFA_RECOVERY_COUNT) {
        $c = '';
        for ($i = 0; $i < 10; $i++) $c .= $alpha[random_int(0, strlen($alpha) - 1)];
        $codes[substr($c, 0, 5) . '-' . substr($c, 5)] = true;
    }
    $codes = array_keys($codes);
    $pdo->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = ?')->execute([$uid]);
    $ins = $pdo->prepare('INSERT INTO mfa_recovery_codes (user_id, code_hash) VALUES (?, ?)');
    foreach ($codes as $c) $ins->execute([$uid, mfa_recovery_hash($uid, $c)]);
    return $codes;
}
function mfa_recovery_left(PDO $pdo, int $uid): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

/* ------------------------------------------------------------------ verificação */
/**
 * Confere um código (TOTP de 6 dígitos ou código de recuperação). Consome de forma atômica.
 * @param array<string,mixed> $u
 * @return 'totp'|'recovery'|null
 */
function mfa_verify_code(PDO $pdo, array $u, string $code, ?int $now = null): ?string
{
    if (!mfa_enabled($u)) return null;
    $uid = (int)$u['id'];
    $code = trim($code);
    $digits = (string)preg_replace('/\s/', '', $code);
    if (preg_match('/^\d{' . MFA_DIGITS . '}$/', $digits)) {
        $b32 = mfa_decrypt_secret($uid, (string)$u['mfa_totp_secret_enc']);
        $key = $b32 === null ? null : base32_decode($b32);
        if ($key === null) return null;
        $st = $pdo->prepare('SELECT mfa_totp_last_step FROM users WHERE id = ?');
        $st->execute([$uid]);
        $last = $st->fetchColumn();
        $step = totp_match($key, $digits, $now ?? time(), $last === null || $last === false ? null : (int)$last);
        if ($step === null) return null;
        // reuso: só um pedido consegue gravar este passo (atômico)
        $up = $pdo->prepare('UPDATE users SET mfa_totp_last_step = ? WHERE id = ? AND (mfa_totp_last_step IS NULL OR mfa_totp_last_step < ?)');
        $up->execute([$step, $uid, $step]);
        return $up->rowCount() === 1 ? 'totp' : null;
    }
    $norm = mfa_normalize_code($code);
    if (!preg_match('/^[a-z0-9]{10}$/', $norm)) return null;
    $up = $pdo->prepare('UPDATE mfa_recovery_codes SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND code_hash = ? AND used_at IS NULL');
    $up->execute([$uid, mfa_recovery_hash($uid, $norm)]);
    return $up->rowCount() === 1 ? 'recovery' : null;
}

/** Limite de tentativas de código: por conta (10 a cada 15 min) e por IP (30 a cada 15 min). */
function mfa_rate_ok(int $uid): bool
{
    return rate_limit('mfa_ip', 30, 900) && rate_limit('mfa_user|' . $uid, MFA_USER_LIMIT, 900, false);
}

/**
 * Ativa: grava o segredo cifrado, o passo usado na confirmação e gera os códigos.
 * @return list<string>
 */
function mfa_enable(PDO $pdo, int $uid, string $b32, int $step): array
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET mfa_totp_secret_enc = ?, mfa_enabled_at = UTC_TIMESTAMP(), mfa_totp_last_step = ?, session_epoch = session_epoch + 1 WHERE id = ?')
            ->execute([mfa_encrypt_secret($uid, $b32), $step, $uid]);
        $codes = mfa_generate_recovery_codes($pdo, $uid);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return $codes;
}
function mfa_disable(PDO $pdo, int $uid): void
{
    $pdo->prepare('UPDATE users SET mfa_totp_secret_enc = NULL, mfa_enabled_at = NULL, mfa_totp_last_step = NULL WHERE id = ?')->execute([$uid]);
    $pdo->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = ?')->execute([$uid]);
}

/**
 * Aviso por e-mail (sem segredo nem código).
 * @param array<string,mixed> $u
 */
function mfa_notify(array $u, string $what): void
{
    if (empty($u['email'])) return;
    require_once APP_ROOT . '/lib/smtp.php';
    $quando = (new DateTime('now', new DateTimeZone(valid_timezone((string)($u['timezone'] ?? '')) ? (string)$u['timezone'] : 'America/Cuiaba')))->format('d/m/Y \à\s H:i');
    $map = [
        'on' => ['Verificação em duas etapas ativada', 'A verificação em duas etapas foi <strong>ativada</strong> na sua conta em ' . $quando . '. A partir de agora, depois da senha, vamos pedir o código do seu app autenticador.'],
        'off' => ['Verificação em duas etapas desativada', 'A verificação em duas etapas foi <strong>desativada</strong> na sua conta em ' . $quando . '. Agora basta a senha para entrar.'],
        'codes' => ['Novos códigos de recuperação', 'Novos códigos de recuperação foram gerados para a sua conta em ' . $quando . '. Os códigos antigos deixaram de valer.'],
    ];
    [$subj, $txt] = $map[$what] ?? $map['on'];
    send_mail((string)$u['email'], $subj, email_layout($subj, '<p>' . $txt . '</p>'
        . '<p style="font-size:13px;color:#50606c">Se não foi você, troque sua senha agora em <a href="' . e(app_link('/conta/esqueci.php')) . '">“Esqueci minha senha”</a> e escreva para contato@bipolar.app.br.</p>'), '', 'mfa');
}

/* ------------------------------------------------------------------ login em duas etapas */
/**
 * Depois da senha certa: entra direto ou, com MFA ativa, guarda o login pendente e leva à tela do código.
 * @return never
 */
function login_or_mfa(int $uid): void
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$uid]);
    $u = $st->fetch();
    if (is_array($u) && mfa_enabled($u)) {
        session_regenerate_id(true);
        unset($_SESSION['uid'], $_SESSION['epoch']);
        $_SESSION['mfa_pending'] = ['uid' => $uid, 't' => time(), 'tries' => 0, 'epoch' => (int)$u['session_epoch']];
        redirect('/conta/mfa.php');
    }
    login_user($uid);
    redirect('/');
}
/**
 * Login pendente válido (não expirou).
 * @return array{uid:int,t:int,tries:int,epoch:int}|null
 */
function mfa_pending(): ?array
{
    $p = $_SESSION['mfa_pending'] ?? null;
    if (!is_array($p) || empty($p['uid']) || time() - (int)($p['t'] ?? 0) > MFA_PENDING_TTL || (int)($p['tries'] ?? 0) >= MFA_MAX_TRIES) return null;
    return ['uid' => (int)$p['uid'], 't' => (int)$p['t'], 'tries' => (int)$p['tries'], 'epoch' => (int)($p['epoch'] ?? 0)];
}
