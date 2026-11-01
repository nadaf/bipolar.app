<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;

/**
 * Abre valores cifrados com a APP_KEY atual e, se nao der, com chaves antigas do 2.0
 * listadas em OLD_APP_KEY (separadas por virgula). Nunca registra conteudo nem chaves.
 */
final class DataKeys
{
    /** Limpa espacos e aspas que costumam vir junto ao colar. @return list<string> */
    private static function oldRaw(): array
    {
        return array_values(array_filter(array_map(fn ($k) => trim($k, " \t\n\r\"'"), explode(',', (string) config('bipolar.old_app_key')))));
    }

    private static function bytes(string $k): ?string
    {
        if (str_starts_with($k, 'base64:')) {
            $b = base64_decode(substr($k, 7), true);

            return is_string($b) ? $b : null;
        }

        return $k;
    }

    private static function cipher(): string
    {
        return (string) config('app.cipher', 'AES-256-CBC');
    }

    /** Problema com a APP_KEY atual (sem mostrar a chave), ou null. Nunca altera a chave. */
    public static function appKeyProblem(): ?string
    {
        $k = (string) config('app.key');
        if ($k === '') {
            return 'A linha APP_KEY= do .env está vazia.';
        }
        $b = self::bytes($k);
        if ($b === null || ! Encrypter::supported($b, self::cipher())) {
            return 'A linha APP_KEY= do .env parece errada: ela deveria começar com base64: e ter 51 caracteres no total '
                .'(ou ter exatamente 32 caracteres sem base64:). Hoje tem '.strlen($k).' caracteres. NÃO troque por uma chave nova: '
                .'os dados já gravados só abrem com a chave com que foram gravados. Procure a APP_KEY certa num backup do .env.';
        }

        return null;
    }

    /** Problema com a OLD_APP_KEY (sem mostrar a chave), ou null se vazia/ok. */
    public static function oldKeyProblem(): ?string
    {
        foreach (self::oldRaw() as $i => $k) {
            $b = self::bytes($k);
            $n = count(self::oldRaw()) > 1 ? ' (a '.($i + 1).'ª chave)' : '';
            if ($b === null || ! Encrypter::supported($b, self::cipher())) {
                return 'A linha OLD_APP_KEY parece incompleta'.$n.': ela deve ser uma APP_KEY antiga do 2.0, começando com base64: e com 51 caracteres no total '
                    .'(hoje tem '.strlen($k).'). A secret_key da versão 0.5 NÃO serve aqui. Se não tiver a APP_KEY antiga, apague a linha OLD_APP_KEY.';
            }
            if (hash_equals((string) self::bytes((string) config('app.key')), $b)) {
                return 'A linha OLD_APP_KEY'.$n.' é igual à APP_KEY atual, então não ajuda. Use a APP_KEY de antes (de um backup do .env) ou apague a linha OLD_APP_KEY.';
            }
        }

        return null;
    }

    /** @return list<Encrypter> */
    public static function old(): array
    {
        $out = [];
        foreach (self::oldRaw() as $k) {
            $b = self::bytes($k);
            if ($b !== null && Encrypter::supported($b, self::cipher())) {
                $out[] = new Encrypter($b, self::cipher());
            }
        }

        return $out;
    }

    /** Tira chaves e tokens de uma mensagem antes de registrar. */
    public static function redact(string $msg): string
    {
        foreach (array_merge([(string) config('app.key'), (string) config('bipolar.installer_token')], self::oldRaw()) as $s) {
            if (strlen($s) >= 8) {
                $msg = str_replace($s, '[oculto]', $msg);
            }
        }

        return (string) preg_replace('/base64:[A-Za-z0-9+\/=]{20,}/', 'base64:[oculto]', $msg);
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
