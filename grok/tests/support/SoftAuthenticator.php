<?php
/** Autenticador WebAuthn em software (ES256) para testes de ponta a ponta: gera atestação "none" e asserções. */
final class SoftAuthenticator
{
    public string $credId;
    private string $pem;
    private string $x;
    private string $y;
    public int $count = 0;
    public bool $uv = true;

    public function __construct(private string $rpId, private string $origin)
    {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($k, $pem);
        $this->pem = (string)$pem;
        $d = openssl_pkey_get_details($k);
        $this->x = str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $this->y = str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $this->credId = random_bytes(16);
    }
    public static function cbor($v): string
    {
        $head = function (int $mt, int $n): string {
            if ($n < 24) return chr($mt << 5 | $n);
            if ($n < 256) return chr($mt << 5 | 24) . chr($n);
            if ($n < 65536) return chr($mt << 5 | 25) . pack('n', $n);
            return chr($mt << 5 | 26) . pack('N', $n);
        };
        if (is_int($v)) return $v >= 0 ? $head(0, $v) : $head(1, -1 - $v);
        if (is_array($v) && isset($v['@bytes'])) return $head(2, strlen($v['@bytes'])) . $v['@bytes'];
        if (is_string($v)) return $head(3, strlen($v)) . $v;
        if (is_array($v) && array_is_list($v) && $v !== []) { $o = $head(4, count($v)); foreach ($v as $x) $o .= self::cbor($x); return $o; }
        if (is_array($v)) { $o = $head(5, count($v)); foreach ($v as $k => $x) $o .= self::cbor($k) . self::cbor($x); return $o; }
        throw new InvalidArgumentException('tipo');
    }
    private function flags(bool $at): int { return 0x01 | ($this->uv ? 0x04 : 0) | ($at ? 0x40 : 0); }
    private function cdj(string $type, string $challengeB64): string
    {
        return (string)json_encode(['type' => $type, 'challenge' => $challengeB64, 'origin' => $this->origin, 'crossOrigin' => false]);
    }
    /** @param array<string,mixed> $pk publicKey de reg_options @return array<string,string> */
    public function create(array $pk): array
    {
        $cose = self::cbor([1 => 2, 3 => -7, -1 => 1, -2 => ['@bytes' => $this->x], -3 => ['@bytes' => $this->y]]);
        $ad = hash('sha256', $this->rpId, true) . chr($this->flags(true)) . pack('N', $this->count) . str_repeat("\0", 16) . pack('n', strlen($this->credId)) . $this->credId . $cose;
        $att = self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => ['@bytes' => $ad]]);
        return ['clientDataJSON' => b64url($this->cdj('webauthn.create', $pk['challenge'])), 'attestationObject' => b64url($att)];
    }
    /** @param array<string,mixed> $pk publicKey de auth_options/login_options @return array<string,string> */
    public function get(array $pk): array
    {
        $this->count++;
        $ad = hash('sha256', $this->rpId, true) . chr($this->flags(false)) . pack('N', $this->count);
        $cdj = $this->cdj('webauthn.get', $pk['challenge']);
        openssl_sign($ad . hash('sha256', $cdj, true), $sig, $this->pem, OPENSSL_ALGO_SHA256);
        return ['rawId' => b64url($this->credId), 'clientDataJSON' => b64url($cdj), 'authenticatorData' => b64url($ad), 'signature' => b64url($sig)];
    }
}
