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
    $st = db()->prepare('SELECT session_epoch FROM users WHERE id = ?');
    $st->execute([$uid]);
    $_SESSION['epoch'] = (int)$st->fetchColumn();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    // Registra o último acesso em todos os tipos de login (e-mail, cadastro, verificação, OAuth, redefinição)
    db()->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$uid]);
}
