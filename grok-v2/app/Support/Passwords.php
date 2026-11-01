<?php

namespace App\Support;

use App\Models\User;

/**
 * Conferencia de senha que aceita os hashes do Bipolar 0.5 (bcrypt ou Argon2id, ambos de password_hash
 * do PHP) alem do bcrypt do 2.0. Hash::check do bcrypt recusa Argon2id, por isso usamos password_verify.
 * Depois de um login certo com hash antigo, a senha e regravada no formato atual (rehash).
 */
final class Passwords
{
    public static function check(string $plain, ?string $hash): bool
    {
        if ($hash === null || $hash === '' || $plain === '') {
            return false;
        }

        return password_verify($plain, $hash);
    }

    /** Regrava no formato do 2.0 se o hash veio da 0.5 em outro algoritmo/custo. */
    public static function upgrade(User $user, string $plain): void
    {
        $hash = (string) $user->getAttributes()['password'];
        if (str_starts_with($hash, '$2y$') && ! password_needs_rehash($hash, PASSWORD_BCRYPT, ['rounds' => (int) config('hashing.bcrypt.rounds', 12)])) {
            return;
        }
        $user->forceFill(['password' => $plain])->save();
    }
}
