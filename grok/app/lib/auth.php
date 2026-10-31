<?php
function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('bpsid');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
    if (!empty($_SESSION['uid']) && (time() - ($_SESSION['last'] ?? 0)) > 60 * 60 * 24 * 14) {
        $_SESSION = []; session_regenerate_id(true);
    }
    $_SESSION['last'] = time();
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
/** @param mixed $t */
function csrf_valid($t): bool {
    return is_string($t) && $t !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'] = ['msg' => $msg, 'type' => $type]; }
/** @return array{msg:string,type:string}|null */
function take_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return is_array($f) ? $f : null; }
function csrf_check(): void {
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (!csrf_valid($t)) json_out(['erro' => 'Token CSRF inválido. Recarregue a página.'], 403);
}
/** @return array<string,mixed>|null */
function current_user(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $st = db()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$_SESSION['uid']]);
        $u = $st->fetch() ?: null;
        // Troca/redefinição de senha incrementa session_epoch e invalida sessões antigas
        if ($u && (int)($u['session_epoch'] ?? 0) !== (int)($_SESSION['epoch'] ?? 0)) {
            $u = null;
            $_SESSION = [];
            session_regenerate_id(true);
        }
    }
    return $u;
}
/** @return array<string,mixed> */
function require_user_api(): array {
    $u = current_user();
    if (!$u) json_out(['erro' => 'Sessão expirada. Entre novamente.'], 401);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') csrf_check();
    // "Bloquear app inteiro": só as rotas de desbloqueio funcionam até a biometria/PIN
    if (!defined('ALLOW_LOCKED') && app_locked($u)) json_out(['erro' => 'App bloqueado. Desbloqueie com biometria ou PIN.', 'bloqueado_app' => true], 423);
    return $u;
}
function login_user(int $uid): void {
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    ga4_flash('login'); // V5.2: evento de funil (sem ID de usuário)
    $st = db()->prepare('SELECT session_epoch FROM users WHERE id = ?');
    $st->execute([$uid]);
    $_SESSION['epoch'] = (int)$st->fetchColumn();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    // V5.1: hora do login (confirmação de identidade recente) e dono da fila offline deste login.
    // A fila do aparelho só é enviada se o dono bater (outra conta no mesmo navegador nunca envia registros alheios).
    $_SESSION['login_at'] = time();
    $_SESSION['q_owner'] = bin2hex(random_bytes(16));
    unset($_SESSION['stepup_at']);
    // Registra o último acesso em todos os tipos de login (e-mail, cadastro, verificação, OAuth, redefinição)
    db()->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$uid]);
}

/** V5.1: dono da fila offline da sessão atual (aleatório, trocado a cada login, nunca derivado de dados da pessoa). */
function queue_owner(): string {
    if (empty($_SESSION['q_owner']) || !is_string($_SESSION['q_owner'])) $_SESSION['q_owner'] = bin2hex(random_bytes(16));
    return $_SESSION['q_owner'];
}

/** V5.1: confirmação de identidade vale 5 minutos; login sem senha/PIN/biometria vale como confirmação por 15 minutos. */
const STEPUP_FRESH_SECONDS = 300;
const STEPUP_LOGIN_SECONDS = 900;
function stepup_mark(): void { $_SESSION['stepup_at'] = time(); }

/**
 * Meios que esta conta tem para confirmar que é ela mesma.
 * @param array<string,mixed> $u
 * @return list<string> 'senha' | 'pin' | 'biometria' | 'novo_login'
 */
function stepup_methods(array $u): array {
    $m = [];
    if (!empty($u['password_hash'])) $m[] = 'senha';
    if (!empty($u['sex_pin_hash'])) $m[] = 'pin';
    if (webauthn_count($u) > 0) $m[] = 'biometria';
    if (!$m) $m[] = 'novo_login';
    return $m;
}

/**
 * V5.1: ações sensíveis (exportar dados, trocar e-mail) pedem confirmação recente da identidade:
 * senha atual, PIN, biometria (desbloqueio das seções privadas) ou, para contas sem nenhum desses, um login recente.
 * @param array<string,mixed> $u
 * @param array<string,mixed> $in pode trazer 'senha' ou 'pin'
 */
function stepup_check(array $u, array $in): bool {
    if ((int)($_SESSION['stepup_at'] ?? 0) > time() - STEPUP_FRESH_SECONDS) return true;
    $pw = is_string($in['senha'] ?? null) ? $in['senha'] : '';
    $pin = is_string($in['pin'] ?? null) ? $in['pin'] : '';
    if ($pw !== '' || $pin !== '') {
        if (!rate_limit('stepup_' . (int)$u['id'], 8, 900, false)) json_out(['erro' => 'Muitas tentativas. Aguarde 15 minutos.'], 429);
        if ($pw !== '' && !empty($u['password_hash']) && password_verify($pw, (string)$u['password_hash'])) { stepup_mark(); return true; }
        if ($pin !== '' && !empty($u['sex_pin_hash']) && password_verify($pin, (string)$u['sex_pin_hash'])) { stepup_mark(); return true; }
        return false;
    }
    // PIN/biometria usados há pouco (desbloqueio das seções privadas dentro do prazo escolhido pela pessoa)
    if (lock_has_factor($u) && private_unlocked($u)) return true;
    // conta só com login externo, sem PIN nem biometria: vale um login feito há pouco
    if (empty($u['password_hash']) && !lock_has_factor($u) && (int)($_SESSION['login_at'] ?? 0) > time() - STEPUP_LOGIN_SECONDS) return true;
    return false;
}
/**
 * @param array<string,mixed> $u
 * @return never
 */
function stepup_required(array $u, string $what): void {
    $m = stepup_methods($u);
    $how = $m === ['novo_login'] ? 'saia e entre de novo na sua conta' : 'confirme com ' . implode(' ou ', array_map(function (string $x): string { return ['senha' => 'sua senha atual', 'pin' => 'seu PIN', 'biometria' => 'a biometria do aparelho'][$x] ?? $x; }, $m));
    json_out(['erro' => "Para $what, $how.", 'stepup' => true, 'metodos' => $m], 403);
}
