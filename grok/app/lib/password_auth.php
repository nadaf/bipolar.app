<?php
/**
 * Login por e-mail e senha.
 * - A conta por e-mail é uma identidade em oauth_identities com provider = 'email' e subject = e-mail normalizado
 *   (garante unicidade). Contas Google/Microsoft/X com o mesmo e-mail NÃO são unificadas (anti-sequestro de conta).
 * - Tokens (verificação/redefinição) são aleatórios de 256 bits; só o SHA-256 é guardado no banco.
 */
const VERIFY_TTL_HOURS = 24;
const RESET_TTL_MINUTES = 60;
const PASSWORD_MIN = 8;
const PASSWORD_MAX = 200;

/** @return string */
function pw_algo() { return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT; }
function pw_hash(string $pw): string { return password_hash($pw, pw_algo()); }
function normalize_email(string $e): string { return mb_strtolower(trim($e)); }
function valid_email(string $e): bool { return strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\r\n]/', $e); }

/** @return string|null mensagem de erro (pt-BR) ou null se a senha é aceitável */
function password_problem(string $pw, string $email = ''): ?string {
    $len = mb_strlen($pw);
    if ($len < PASSWORD_MIN) return 'A senha precisa ter pelo menos ' . PASSWORD_MIN . ' caracteres.';
    if ($len > PASSWORD_MAX) return 'A senha pode ter no máximo ' . PASSWORD_MAX . ' caracteres.';
    $low = mb_strtolower($pw);
    $common = ['12345678', '123456789', '1234567890', 'password', 'senha123', 'senha1234', 'qwertyui', 'qwerty123', '11111111', '00000000',
        'abcdefgh', 'abc12345', 'iloveyou', 'brasil123', 'mudar123', 'admin123', 'bipolar1', 'bipolar123', 'password1', 'senhasenha'];
    if (in_array($low, $common, true)) return 'Essa senha é muito comum. Escolha outra.';
    if (count(array_unique(preg_split('//u', $pw, -1, PREG_SPLIT_NO_EMPTY) ?: [])) < 4) return 'Use uma senha com mais variedade de caracteres.';
    if ($email !== '' && $low === normalize_email($email)) return 'A senha não pode ser igual ao e-mail.';
    return null;
}

/** @return array<string,mixed>|null usuário com conta por e-mail */
function find_email_user(string $email): ?array {
    $st = db()->prepare("SELECT u.* FROM oauth_identities i JOIN users u ON u.id = i.user_id WHERE i.provider = 'email' AND i.subject = ?");
    $st->execute([normalize_email($email)]);
    $u = $st->fetch();
    return $u ?: null;
}

function create_auth_token(int $uid, string $purpose, int $ttlSeconds): string {
    $raw = b64url(random_bytes(32));
    db()->prepare('INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at) VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? SECOND)')
        ->execute([$uid, $purpose, hash('sha256', $raw), $ttlSeconds]);
    return $raw;
}
/**
 * Valida token sem consumir.
 * @return array<string,mixed>|null linha de auth_tokens
 */
function find_auth_token(string $raw, string $purpose): ?array {
    if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $raw)) return null;
    $st = db()->prepare('SELECT * FROM auth_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()');
    $st->execute([hash('sha256', $raw), $purpose]);
    $t = $st->fetch();
    return $t ?: null;
}
/** Consome token de forma atômica (uso único). @return int|null user_id */
function consume_auth_token(string $raw, string $purpose): ?int {
    $t = find_auth_token($raw, $purpose);
    if (!$t) return null;
    $st = db()->prepare('UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ? AND used_at IS NULL');
    $st->execute([$t['id']]);
    if ($st->rowCount() !== 1) return null; // corrida: outro pedido já usou
    // invalida os demais tokens do mesmo tipo
    db()->prepare('UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND purpose = ? AND used_at IS NULL')->execute([$t['user_id'], $purpose]);
    return (int)$t['user_id'];
}

function app_link(string $path): string { return rtrim((string)cfg('app_url'), '/') . $path; }

function send_verification_email(int $uid, string $email, string $name): bool {
    require_once APP_ROOT . '/lib/smtp.php';
    $tok = create_auth_token($uid, 'verify', VERIFY_TTL_HOURS * 3600);
    $link = app_link('/conta/verificar.php?t=' . $tok);
    $html = email_layout('Confirme seu e-mail', '<p>Olá' . ($name !== '' ? ', ' . e($name) : '') . '!</p>'
        . '<p>Para ativar sua conta no Bipolar — Diário de Humor, confirme seu e-mail:</p>'
        . '<p><a href="' . e($link) . '" style="display:inline-block;background:#2f6f73;color:#fff;padding:12px 20px;border-radius:10px;text-decoration:none">Confirmar e-mail</a></p>'
        . '<p style="font-size:13px;color:#50606c">O link vale por ' . VERIFY_TTL_HOURS . ' horas. Se você não criou esta conta, ignore este e-mail.</p>');
    return send_mail($email, 'Confirme seu e-mail', $html, '', 'verificacao');
}

/**
 * Cadastro. Resposta é sempre genérica para não revelar se o e-mail já existe.
 * @return array{status:string,uid:?int} status: 'created' | 'exists' | 'invalid'
 */
function signup_email(string $name, string $email, string $pw): array {
    $email = normalize_email($email);
    $hash = pw_hash($pw); // sempre calcula (tempo de resposta semelhante para e-mail novo ou existente)
    $existing = find_email_user($email);
    if ($existing) {
        require_once APP_ROOT . '/lib/smtp.php';
        send_mail($email, 'Tentativa de cadastro com seu e-mail', email_layout('Você já tem uma conta',
            '<p>Alguém (talvez você) tentou criar uma conta no Bipolar com este e-mail, mas ele já está cadastrado.</p>'
            . '<p>Para entrar, use <a href="' . e(app_link('/')) . '">a página de login</a>. Se esqueceu a senha, use '
            . '<a href="' . e(app_link('/conta/esqueci.php')) . '">“Esqueci minha senha”</a>.</p>'
            . '<p style="font-size:13px;color:#50606c">Se não foi você, nenhuma ação é necessária.</p>'));
        return ['status' => 'exists', 'uid' => null];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $verified = cfg('require_email_verification', true) ? null : gmdate('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO users (name, email, timezone, password_hash, email_verified_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([mb_substr(trim($name), 0, 150), $email, cfg('default_timezone', 'America/Cuiaba'), $hash, $verified]);
        $uid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO oauth_identities (user_id, provider, subject) VALUES (?, 'email', ?)")->execute([$uid, $email]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() === '23000') return ['status' => 'exists', 'uid' => null]; // corrida de cadastro duplicado
        throw $e;
    }
    if (cfg('require_email_verification', true)) send_verification_email($uid, $email, trim($name));
    return ['status' => 'created', 'uid' => $uid];
}

/** Hash fictício para igualar o tempo quando o e-mail não existe. */
function dummy_hash(): string {
    static $h = null;
    if ($h === null) $h = pw_hash('senha-inexistente-' . bin2hex(random_bytes(8)));
    return $h;
}

/**
 * @return array{status:string,uid:?int} status: 'ok' | 'invalid' | 'unverified'
 */
function login_email(string $email, string $pw): array {
    $u = find_email_user($email);
    $hash = ($u && !empty($u['password_hash'])) ? (string)$u['password_hash'] : dummy_hash();
    $ok = password_verify($pw, $hash);
    if (!$u || !$ok || empty($u['password_hash'])) return ['status' => 'invalid', 'uid' => null];
    if (password_needs_rehash($hash, pw_algo())) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([pw_hash($pw), $u['id']]);
    }
    if (cfg('require_email_verification', true) && empty($u['email_verified_at'])) return ['status' => 'unverified', 'uid' => (int)$u['id']];
    return ['status' => 'ok', 'uid' => (int)$u['id']];
}

/** Envia link de redefinição, se a conta existir. Sem retorno observável (anti-enumeração). */
function request_password_reset(string $email): void {
    $u = find_email_user($email);
    if (!$u) { dummy_hash(); return; }
    require_once APP_ROOT . '/lib/smtp.php';
    $tok = create_auth_token((int)$u['id'], 'reset', RESET_TTL_MINUTES * 60);
    $link = app_link('/conta/redefinir.php?t=' . $tok);
    send_mail(normalize_email($email), 'Redefinição de senha', email_layout('Redefinir senha',
        '<p>Recebemos um pedido para redefinir a senha da sua conta.</p>'
        . '<p><a href="' . e($link) . '" style="display:inline-block;background:#2f6f73;color:#fff;padding:12px 20px;border-radius:10px;text-decoration:none">Criar nova senha</a></p>'
        . '<p style="font-size:13px;color:#50606c">O link vale por ' . RESET_TTL_MINUTES . ' minutos e só pode ser usado uma vez. Se não foi você, ignore este e-mail — sua senha continua a mesma.</p>'));
}

/** Define nova senha e invalida todas as sessões existentes do usuário. */
function set_password(int $uid, string $pw): void {
    db()->prepare('UPDATE users SET password_hash = ?, session_epoch = session_epoch + 1 WHERE id = ?')->execute([pw_hash($pw), $uid]);
}
