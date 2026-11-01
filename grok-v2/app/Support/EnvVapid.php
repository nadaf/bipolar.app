<?php

namespace App\Support;

use Minishlink\WebPush\VAPID;

/**
 * Hospedagem sem SSH: o instalador web (/instalar, /atualizar) cria as chaves de notificacao (VAPID)
 * no .env quando estao vazias, como a APP_KEY. Nunca sobrescreve chave existente e nunca mostra a privada.
 */
final class EnvVapid
{
    /** @return bool true se gravou chaves novas */
    public static function ensure(string $path): bool
    {
        if (! is_file($path) || ! is_writable($path)) {
            return false;
        }
        $env = (string) file_get_contents($path);
        $changed = false;
        $pub = self::get($env, 'VAPID_PUBLIC_KEY');
        $priv = self::get($env, 'VAPID_PRIVATE_KEY');
        if ($pub === '' && $priv === '') {
            $k = VAPID::createVapidKeys();
            $env = self::set($env, 'VAPID_PUBLIC_KEY', $k['publicKey']);
            $env = self::set($env, 'VAPID_PRIVATE_KEY', $k['privateKey']);
            $changed = true;
        }
        if (self::get($env, 'VAPID_SUBJECT') === '') {
            $from = self::get($env, 'MAIL_FROM_ADDRESS');
            $env = self::set($env, 'VAPID_SUBJECT', filter_var($from, FILTER_VALIDATE_EMAIL) ? 'mailto:'.$from : 'https://bipolar.app.br');
        }
        file_put_contents($path, $env, LOCK_EX);

        return $changed;
    }

    private static function get(string $env, string $k): string
    {
        return preg_match('/^'.$k.'=(.*)$/m', $env, $m) ? trim(trim($m[1]), '"\'') : '';
    }

    private static function set(string $env, string $k, string $v): string
    {
        if (preg_match('/^'.$k.'=.*$/m', $env)) {
            return (string) preg_replace('/^'.$k.'=.*$/m', $k.'='.$v, $env, 1);
        }

        return rtrim($env, "\n")."\n".$k.'='.$v."\n";
    }
}
