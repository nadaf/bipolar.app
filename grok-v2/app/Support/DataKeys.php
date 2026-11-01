<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;

/**
 * Abre valores cifrados com a APP_KEY atual e, se nao der, com chaves antigas do 2.0
 * listadas em OLD_APP_KEY (separadas por virgula). Nunca registra conteudo.
 */
final class DataKeys
{
    /** @return list<Encrypter> */
    public static function old(): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', (string) config('bipolar.old_app_key')))) as $k) {
            $raw = str_starts_with($k, 'base64:') ? base64_decode(substr($k, 7), true) : $k;
            $cipher = (string) config('app.cipher', 'AES-256-CBC');
            if (is_string($raw) && Encrypter::supported($raw, $cipher)) {
                $out[] = new Encrypter($raw, $cipher);
            }
        }

        return $out;
    }

    /** Abre com a chave atual. null se nao abrir. */
    public static function current(string $payload): ?string
    {
        try {
            return Crypt::decryptString($payload);
        } catch (DecryptException) {
            return null;
        }
    }

    /** Abre com alguma chave antiga (OLD_APP_KEY). null se nao abrir. */
    public static function withOld(string $payload): ?string
    {
        foreach (self::old() as $e) {
            try {
                return $e->decryptString($payload);
            } catch (DecryptException) {
            }
        }

        return null;
    }

    /** Cifra com a chave atual e confere que abre (nunca grava algo ilegivel). */
    public static function seal(string $plain): string
    {
        $c = Crypt::encryptString($plain);
        if (self::current($c) !== $plain) {
            throw new \RuntimeException('Cifra nao confere com a APP_KEY atual.');
        }

        return $c;
    }
}
