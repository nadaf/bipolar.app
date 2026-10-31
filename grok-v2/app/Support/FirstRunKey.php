<?php

namespace App\Support;

/**
 * Hospedagem sem SSH: na primeira execucao, se o .env existir, APP_KEY estiver vazia
 * e o instalador estiver habilitado (INSTALLER_TOKEN >= 32), gera a APP_KEY no .env
 * ANTES do Laravel iniciar (senao nada funciona, nem o instalador).
 */
final class FirstRunKey
{
    public static function ensure(string $basePath): void
    {
        $path = $basePath.'/.env';
        if (! is_file($path) || ! is_writable($path)) {
            return;
        }
        $env = (string) file_get_contents($path);
        if (! preg_match('/^APP_KEY=\s*$/m', $env) || ! preg_match('/^INSTALLER_TOKEN=(\S{32,})\s*$/m', $env)) {
            return;
        }
        $key = 'base64:'.base64_encode(random_bytes(32));
        file_put_contents($path, preg_replace('/^APP_KEY=\s*$/m', 'APP_KEY='.$key, $env, 1), LOCK_EX);
    }
}
