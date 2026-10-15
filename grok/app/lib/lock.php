<?php
/**
 * Bloqueio das seções privadas (Sexualidade, Substâncias) e, opcionalmente, do app inteiro.
 * Fatores: biometria do aparelho (WebAuthn) e/ou PIN. O desbloqueio vale N minutos (configurável).
 */

/**
 * @param array<string,mixed> $u
 * @return array{minutes:int,app:bool,on_hide:bool}
 */
function lock_settings(array $u): array
{
    $p = json_decode((string)($u['prefs'] ?? ''), true);
    $l = is_array($p) && is_array($p['lock'] ?? null) ? $p['lock'] : [];
    return ['minutes' => max(1, min(240, (int)($l['minutes'] ?? 15))), 'app' => !empty($l['app']), 'on_hide' => !isset($l['on_hide']) || !empty($l['on_hide'])];
}
/** @param array<string,mixed> $u */
function webauthn_count(array $u): int
{
    static $cache = [];
    $id = (int)$u['id'];
    if (!isset($cache[$id])) {
        try { $st = db()->prepare('SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = ?'); $st->execute([$id]); $cache[$id] = (int)$st->fetchColumn(); }
        catch (PDOException $e) { $cache[$id] = 0; } // tabela ainda não criada (migração V4 pendente)
    }
    return $cache[$id];
}
/**
 * Há PIN ou biometria cadastrados?
 * @param array<string,mixed> $u
 */
function lock_has_factor(array $u): bool { return !empty($u['sex_pin_hash']) || webauthn_count($u) > 0; }

/** @param array<string,mixed> $u */
function private_unlocked(array $u): bool
{
    if (!lock_has_factor($u)) return true;
    return (int)($_SESSION['sex_unlocked'] ?? 0) > time() - 60 * lock_settings($u)['minutes'];
}
/**
 * App inteiro bloqueado? Janela deslizante: cada uso renova o prazo.
 * @param array<string,mixed> $u
 */
function app_locked(array $u): bool
{
    $s = lock_settings($u);
    if (!$s['app'] || !lock_has_factor($u)) return false;
    $t = (int)($_SESSION['app_unlocked'] ?? 0);
    if ($t > time() - 60 * $s['minutes']) { $_SESSION['app_unlocked'] = time(); return false; }
    return true;
}
function lock_mark_unlocked(): void { $_SESSION['sex_unlocked'] = time(); $_SESSION['app_unlocked'] = time(); }
function lock_now(): void { unset($_SESSION['sex_unlocked'], $_SESSION['app_unlocked']); }
