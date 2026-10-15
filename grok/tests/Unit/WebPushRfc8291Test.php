<?php
use PHPUnit\Framework\TestCase;

/** Criptografia do payload do Web Push contra o vetor de teste oficial (RFC 8291, seção 5 e Apêndice A). */
final class WebPushRfc8291Test extends TestCase
{
    private const AS_PUB = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
    private const AS_PRIV = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
    private const UA_PUB = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    private const UA_PRIV = 'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94';
    private const AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';
    private const SALT = 'DGv6ra1nlYgDCS1FRnbzlw';
    private const BODY = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

    public function testMatchesRfc8291Vector(): void
    {
        $pem = p256_private_pem(b64url_decode(self::AS_PRIV), b64url_decode(self::AS_PUB));
        $out = webpush_encrypt('When I grow up, I want to be a watermelon', b64url_decode(self::UA_PUB), b64url_decode(self::AUTH), $pem, b64url_decode(self::AS_PUB), b64url_decode(self::SALT));
        $this->assertSame(self::BODY, b64url($out));
        $this->assertSame(86 + 41 + 1 + 16, strlen($out), 'cabeçalho de 86 bytes + texto + delimitador + tag GCM');
    }
    public function testIntermediateValuesFromAppendixA(): void
    {
        $ecdh = openssl_pkey_derive(openssl_pkey_get_public(p256_public_pem(b64url_decode(self::UA_PUB))), openssl_pkey_get_private(p256_private_pem(b64url_decode(self::AS_PRIV), b64url_decode(self::AS_PUB))), 32);
        $this->assertSame('kyrL1jIIOHEzg3sM2ZWRHDRB62YACZhhSlknJ672kSs', b64url((string)$ecdh));
        $ikm = hkdf_sha256(b64url_decode(self::AUTH), (string)$ecdh, "WebPush: info\x00" . b64url_decode(self::UA_PUB) . b64url_decode(self::AS_PUB), 32);
        $this->assertSame('S4lYMb_L0FxCeq0WhDx813KgSYqU26kOyzWUdsXYyrg', b64url($ikm));
        $this->assertSame('09_eUZGrsvxChDCGRCdkLiDXrReGOEVeSCdCcPBSJSc', b64url(hash_hmac('sha256', $ikm, b64url_decode(self::SALT), true)), 'PRK');
        $this->assertSame('oIhVW04MRdy2XN9CiKLxTg', b64url(hkdf_sha256(b64url_decode(self::SALT), $ikm, "Content-Encoding: aes128gcm\x00", 16)), 'CEK');
        $this->assertSame('4h_95klXJ5E_qnoN', b64url(hkdf_sha256(b64url_decode(self::SALT), $ikm, "Content-Encoding: nonce\x00", 12)), 'NONCE');
    }
    /** Receptor decifra o que geramos com chaves efêmeras aleatórias (ida e volta). */
    public function testRoundTripWithRandomEphemeralKeys(): void
    {
        $out = webpush_encrypt('{"title":"Teste","body":"Como está seu humor nesta tarde?"}', b64url_decode(self::UA_PUB), b64url_decode(self::AUTH));
        $salt = substr($out, 0, 16);
        $this->assertSame(4096, unpack('N', substr($out, 16, 4))[1]);
        $idlen = ord($out[20]);
        $this->assertSame(65, $idlen);
        $asPub = substr($out, 21, 65);
        $ct = substr($out, 86);
        $ecdh = openssl_pkey_derive(openssl_pkey_get_public(p256_public_pem($asPub)), openssl_pkey_get_private(p256_private_pem(b64url_decode(self::UA_PRIV), b64url_decode(self::UA_PUB))), 32);
        $ikm = hkdf_sha256(b64url_decode(self::AUTH), (string)$ecdh, "WebPush: info\x00" . b64url_decode(self::UA_PUB) . $asPub, 32);
        $pt = openssl_decrypt(substr($ct, 0, -16), 'aes-128-gcm', hkdf_sha256($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16), OPENSSL_RAW_DATA, hkdf_sha256($salt, $ikm, "Content-Encoding: nonce\x00", 12), substr($ct, -16));
        $this->assertSame('{"title":"Teste","body":"Como está seu humor nesta tarde?"}' . "\x02", $pt, 'delimitador 0x02 do último registro');
    }
    public function testRejectsBadKeysAndHugePayload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        webpush_encrypt(str_repeat('x', 5000), b64url_decode(self::UA_PUB), b64url_decode(self::AUTH));
    }
    public function testReminderPayloadIsGenericAndHasActions(): void
    {
        $p = reminder_payload('America/Cuiaba');
        $this->assertMatchesRegularExpression('/^Como está seu humor nesta (manhã|tarde|noite)\?$/u', $p['body']);
        $this->assertStringStartsWith('/registro-rapido.php?p=', $p['url']);
        $this->assertSame(['registrar', 'snooze'], array_column($p['actions'], 'action'));
        $this->assertSame(['Registrar agora', 'Lembrar em 30 min'], array_column($p['actions'], 'title'));
    }
}
