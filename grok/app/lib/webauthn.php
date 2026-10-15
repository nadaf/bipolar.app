<?php
/**
 * WebAuthn (Face ID / Touch ID / Windows Hello / biometria do Android) em PHP puro.
 * - CBOR (RFC 8949) mínimo, COSE (RFC 9053) ES256 (-7) e RS256 (-257) → PEM.
 * - Registro com atestação "none" (o attStmt é ignorado; não confiamos em fabricante, só na chave).
 * - Asserção: rpIdHash, flags UP/UV, desafio, origem, assinatura e contador.
 */
final class CborReader
{
    private int $p = 0;
    public function __construct(private string $b) {}
    public function pos(): int { return $this->p; }
    private function take(int $n): string
    {
        if ($this->p + $n > strlen($this->b)) throw new RuntimeException('CBOR truncado');
        $s = substr($this->b, $this->p, $n); $this->p += $n; return $s;
    }
    private function len(int $ai): int
    {
        if ($ai < 24) return $ai;
        if ($ai === 24) return ord($this->take(1));
        if ($ai === 25) return unpack('n', $this->take(2))[1];
        if ($ai === 26) return unpack('N', $this->take(4))[1];
        if ($ai === 27) { $v = unpack('J', $this->take(8))[1]; if ($v < 0 || $v > PHP_INT_MAX) throw new RuntimeException('CBOR: inteiro grande'); return $v; }
        throw new RuntimeException('CBOR: comprimento indefinido não suportado');
    }
    /** @return mixed */
    public function read(int $depth = 0)
    {
        if ($depth > 16) throw new RuntimeException('CBOR: profundidade excessiva');
        $ib = ord($this->take(1));
        $mt = $ib >> 5; $ai = $ib & 31;
        switch ($mt) {
            case 0: return $this->len($ai);
            case 1: return -1 - $this->len($ai);
            case 2: return ['@bytes' => $this->take($this->len($ai))];
            case 3: return $this->take($this->len($ai));
            case 4: $n = $this->len($ai); $a = []; for ($i = 0; $i < $n; $i++) $a[] = $this->read($depth + 1); return $a;
            case 5: $n = $this->len($ai); $m = []; for ($i = 0; $i < $n; $i++) { $k = $this->read($depth + 1); if (is_array($k)) throw new RuntimeException('CBOR: chave inválida'); $m[$k] = $this->read($depth + 1); } return $m;
            case 6: $this->len($ai); return $this->read($depth + 1); // tag: ignora
            default:
                if ($ai === 20) return false;
                if ($ai === 21) return true;
                if ($ai === 22 || $ai === 23) return null;
                throw new RuntimeException('CBOR: tipo simples não suportado');
        }
    }
}
/** @return mixed */
function cbor_decode(string $b, int &$consumed = 0)
{
    $r = new CborReader($b);
    $v = $r->read();
    $consumed = $r->pos();
    return $v;
}
/** @param mixed $v */
function cbor_bytes($v): string
{
    if (!is_array($v) || !isset($v['@bytes'])) throw new RuntimeException('CBOR: esperado byte string');
    return (string)$v['@bytes'];
}

function der_len(int $n): string
{
    if ($n < 128) return chr($n);
    $s = ltrim(pack('N', $n), "\x00");
    return chr(0x80 | strlen($s)) . $s;
}
function der_int(string $bytes): string
{
    $bytes = ltrim($bytes, "\x00");
    if ($bytes === '' || (ord($bytes[0]) & 0x80)) $bytes = "\x00" . $bytes;
    return "\x02" . der_len(strlen($bytes)) . $bytes;
}
function der_seq(string $c): string { return "\x30" . der_len(strlen($c)) . $c; }

/**
 * COSE_Key → [PEM, alg]. Suporta EC2/P-256/ES256 e RSA/RS256.
 * @param array<int|string,mixed> $k
 * @return array{0:string,1:int}
 */
function cose_to_pem(array $k): array
{
    $kty = $k[1] ?? null; $alg = $k[3] ?? null;
    if ($kty === 2 && $alg === -7 && ($k[-1] ?? null) === 1) {
        $x = cbor_bytes($k[-2] ?? null); $y = cbor_bytes($k[-3] ?? null);
        if (strlen($x) !== 32 || strlen($y) !== 32) throw new RuntimeException('COSE: coordenadas inválidas');
        return [p256_public_pem("\x04" . $x . $y), -7];
    }
    if ($kty === 3 && $alg === -257) {
        $n = cbor_bytes($k[-1] ?? null); $e = cbor_bytes($k[-2] ?? null);
        if (strlen(ltrim($n, "\x00")) < 256) throw new RuntimeException('COSE: RSA < 2048 bits');
        $rsa = der_seq(der_int($n) . der_int($e));
        $spki = der_seq(der_seq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00") . "\x03" . der_len(strlen($rsa) + 1) . "\x00" . $rsa);
        return ["-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n", -257];
    }
    throw new RuntimeException('Algoritmo de chave não suportado');
}

/**
 * Lê authenticatorData.
 * @return array{rpIdHash:string,flags:int,signCount:int,credentialId:?string,cose:?array<int|string,mixed>}
 */
function webauthn_parse_auth_data(string $ad): array
{
    if (strlen($ad) < 37) throw new RuntimeException('authData curto');
    $r = ['rpIdHash' => substr($ad, 0, 32), 'flags' => ord($ad[32]), 'signCount' => unpack('N', substr($ad, 33, 4))[1], 'credentialId' => null, 'cose' => null];
    if ($r['flags'] & 0x40) {
        if (strlen($ad) < 55) throw new RuntimeException('authData sem credencial');
        $len = unpack('n', substr($ad, 53, 2))[1];
        $r['credentialId'] = substr($ad, 55, $len);
        if (strlen($r['credentialId']) !== $len) throw new RuntimeException('credentialId truncado');
        $cose = cbor_decode(substr($ad, 55 + $len));
        if (!is_array($cose)) throw new RuntimeException('COSE inválido');
        $r['cose'] = $cose;
    }
    return $r;
}

/** @return array<string,mixed> */
function webauthn_check_client_data(string $cdj, string $type, string $challenge, string $origin): array
{
    $c = json_decode($cdj, true);
    if (!is_array($c)) throw new RuntimeException('clientDataJSON inválido');
    if (($c['type'] ?? '') !== $type) throw new RuntimeException('Tipo de operação incorreto');
    if (!hash_equals(b64url($challenge), (string)($c['challenge'] ?? ''))) throw new RuntimeException('Desafio incorreto ou expirado');
    if (($c['origin'] ?? '') !== $origin) throw new RuntimeException('Origem incorreta');
    if (!empty($c['crossOrigin'])) throw new RuntimeException('Origem cruzada não permitida');
    return $c;
}

/**
 * Verifica o registro (navigator.credentials.create).
 * @return array{credentialId:string,pem:string,alg:int,signCount:int}
 */
function webauthn_verify_registration(string $clientDataJSON, string $attestationObject, string $challenge, string $rpId, string $origin, bool $requireUV = true): array
{
    webauthn_check_client_data($clientDataJSON, 'webauthn.create', $challenge, $origin);
    $att = cbor_decode($attestationObject);
    if (!is_array($att) || !isset($att['fmt'], $att['authData'])) throw new RuntimeException('attestationObject inválido');
    $ad = webauthn_parse_auth_data(cbor_bytes($att['authData']));
    if (!hash_equals(hash('sha256', $rpId, true), $ad['rpIdHash'])) throw new RuntimeException('rpId incorreto');
    if (!($ad['flags'] & 0x01)) throw new RuntimeException('Presença do usuário não confirmada');
    if ($requireUV && !($ad['flags'] & 0x04)) throw new RuntimeException('Verificação biométrica/PIN do aparelho não realizada');
    if ($ad['credentialId'] === null || $ad['cose'] === null) throw new RuntimeException('Credencial ausente');
    [$pem, $alg] = cose_to_pem($ad['cose']);
    return ['credentialId' => $ad['credentialId'], 'pem' => $pem, 'alg' => $alg, 'signCount' => $ad['signCount']];
}

/**
 * Verifica uma asserção (navigator.credentials.get). Retorna o novo contador.
 */
function webauthn_verify_assertion(string $clientDataJSON, string $authenticatorData, string $signature, string $challenge, string $rpId, string $origin, string $pem, int $alg, int $storedCount, bool $requireUV = true): int
{
    webauthn_check_client_data($clientDataJSON, 'webauthn.get', $challenge, $origin);
    $ad = webauthn_parse_auth_data($authenticatorData);
    if (!hash_equals(hash('sha256', $rpId, true), $ad['rpIdHash'])) throw new RuntimeException('rpId incorreto');
    if (!($ad['flags'] & 0x01)) throw new RuntimeException('Presença do usuário não confirmada');
    if ($requireUV && !($ad['flags'] & 0x04)) throw new RuntimeException('Verificação biométrica/PIN do aparelho não realizada');
    if (!in_array($alg, [-7, -257], true)) throw new RuntimeException('Algoritmo não suportado');
    $ok = openssl_verify($authenticatorData . hash('sha256', $clientDataJSON, true), $signature, $pem, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) throw new RuntimeException('Assinatura inválida');
    // Contador: se algum lado usa contador, o novo deve ser maior (detecta autenticador clonado)
    if (($storedCount > 0 || $ad['signCount'] > 0) && $ad['signCount'] <= $storedCount) throw new RuntimeException('Contador de assinatura inválido (possível clone)');
    return $ad['signCount'];
}

function webauthn_rp_id(): string
{
    $id = (string)cfg('webauthn.rp_id', '');
    return $id !== '' ? $id : (string)parse_url((string)cfg('app_url'), PHP_URL_HOST);
}
function webauthn_origin(): string
{
    $u = parse_url((string)cfg('app_url'));
    return ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '');
}
/** Novo desafio guardado na sessão (vale 5 min, uso único). */
function webauthn_challenge(string $purpose): string
{
    $c = random_bytes(32);
    $_SESSION['wa_' . $purpose] = ['c' => $c, 't' => time()];
    return $c;
}
function webauthn_take_challenge(string $purpose): string
{
    $s = $_SESSION['wa_' . $purpose] ?? null;
    unset($_SESSION['wa_' . $purpose]);
    if (!is_array($s) || time() - (int)$s['t'] > 300) throw new RuntimeException('Desafio expirado. Tente de novo.');
    return (string)$s['c'];
}
