<?php
use PHPUnit\Framework\TestCase;

/** WebAuthn com os vetores de teste oficiais do W3C WebAuthn Level 3 (§16 Test Vectors): none/ES256 e packed/RS256. */
final class WebAuthnTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function v(string $k): array
    {
        $all = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/webauthn-l3-vectors.json'), true);
        return $all[$k];
    }
    /** @return array<string,array{0:string,1:int}> */
    public static function vectors(): array { return ['none ES256' => ['none_es256', -7], 'packed RS256' => ['packed_rs256', -257]]; }

    /** @dataProvider vectors */
    public function testRegistrationAndAssertion(string $k, int $alg): void
    {
        $v = self::v($k); $r = $v['registration']; $a = $v['authentication'];
        $reg = webauthn_verify_registration(hex2bin($r['clientDataJSON']), hex2bin($r['attestationObject']), hex2bin($r['challenge']), 'example.org', 'https://example.org', false);
        $this->assertSame($r['credential_id'], bin2hex($reg['credentialId']));
        $this->assertSame($alg, $reg['alg']);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $reg['pem']);
        $n = webauthn_verify_assertion(hex2bin($a['clientDataJSON']), hex2bin($a['authenticatorData']), hex2bin($a['signature']), hex2bin($a['challenge']), 'example.org', 'https://example.org', $reg['pem'], $reg['alg'], 0, false);
        $this->assertSame(unpack('N', substr(hex2bin($a['authenticatorData']), 33, 4))[1], $n);
        // assinatura adulterada
        $sig = hex2bin($a['signature']); $sig[10] = chr(ord($sig[10]) ^ 1);
        try { webauthn_verify_assertion(hex2bin($a['clientDataJSON']), hex2bin($a['authenticatorData']), $sig, hex2bin($a['challenge']), 'example.org', 'https://example.org', $reg['pem'], $reg['alg'], 0, false); $this->fail('aceitou assinatura inválida'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('Assinatura inválida', $e->getMessage()); }
    }
    /** Os vetores L3 de asserção não têm o bit UV: com verificação obrigatória (como no app) devem ser recusados. */
    public function testUserVerificationRequired(): void
    {
        $v = self::v('none_es256'); $r = $v['registration']; $a = $v['authentication'];
        $this->assertSame(0, ord(hex2bin($a['authenticatorData'])[32]) & 0x04);
        $reg = webauthn_verify_registration(hex2bin($r['clientDataJSON']), hex2bin($r['attestationObject']), hex2bin($r['challenge']), 'example.org', 'https://example.org', false);
        $this->expectExceptionMessage('Verificação biométrica');
        webauthn_verify_assertion(hex2bin($a['clientDataJSON']), hex2bin($a['authenticatorData']), hex2bin($a['signature']), hex2bin($a['challenge']), 'example.org', 'https://example.org', $reg['pem'], $reg['alg'], 0, true);
    }
    public function testPackedRegistrationHasUvAndPassesStrictMode(): void
    {
        $r = self::v('packed_rs256')['registration'];
        $reg = webauthn_verify_registration(hex2bin($r['clientDataJSON']), hex2bin($r['attestationObject']), hex2bin($r['challenge']), 'example.org', 'https://example.org', true);
        $this->assertSame(-257, $reg['alg']);
    }
    public function testWrongRpIdOriginChallengeAndType(): void
    {
        $r = self::v('none_es256')['registration'];
        $cases = [
            ['evil.example', 'https://example.org', hex2bin($r['challenge']), 'rpId'],
            ['example.org', 'https://evil.example', hex2bin($r['challenge']), 'Origem'],
            ['example.org', 'https://example.org', random_bytes(32), 'Desafio'],
        ];
        foreach ($cases as [$rp, $or, $ch, $msg]) {
            try { webauthn_verify_registration(hex2bin($r['clientDataJSON']), hex2bin($r['attestationObject']), $ch, $rp, $or, false); $this->fail("aceitou $msg errado"); }
            catch (RuntimeException $e) { $this->assertStringContainsString($msg, $e->getMessage()); }
        }
        $a = self::v('none_es256')['authentication'];
        $this->expectExceptionMessage('Tipo de operação');
        webauthn_verify_registration(hex2bin($a['clientDataJSON']), hex2bin($r['attestationObject']), hex2bin($a['challenge']), 'example.org', 'https://example.org', false);
    }
    public function testSignCounterDetectsClones(): void
    {
        $v = self::v('packed_rs256'); $r = $v['registration']; $a = $v['authentication'];
        $reg = webauthn_verify_registration(hex2bin($r['clientDataJSON']), hex2bin($r['attestationObject']), hex2bin($r['challenge']), 'example.org', 'https://example.org', false);
        $count = unpack('N', substr(hex2bin($a['authenticatorData']), 33, 4))[1];
        $args = [hex2bin($a['clientDataJSON']), hex2bin($a['authenticatorData']), hex2bin($a['signature']), hex2bin($a['challenge']), 'example.org', 'https://example.org', $reg['pem'], $reg['alg']];
        if ($count === 0) { $this->assertSame(0, webauthn_verify_assertion(...array_merge($args, [0, false]))); }
        $this->expectExceptionMessage('Contador');
        webauthn_verify_assertion(...array_merge($args, [max(1, $count), false]));
    }
    public function testCborDecoder(): void
    {
        $this->assertSame(['a' => 1, 'b' => [2, 3]], cbor_decode(hex2bin('a2616101616282020' . '3')));
        $this->assertSame(-7, cbor_decode(hex2bin('26')));
        $this->assertSame(['@bytes' => "\x01\x02"], cbor_decode(hex2bin('420102')));
        $this->assertSame(1000000, cbor_decode(hex2bin('1a000f4240')));
        $this->assertTrue(cbor_decode(hex2bin('f5')));
        $this->expectException(RuntimeException::class);
        cbor_decode(hex2bin('5f')); // comprimento indefinido
    }
    public function testCoseRejectsUnsupportedKeys(): void
    {
        $this->expectExceptionMessage('não suportado');
        cose_to_pem([1 => 1, 3 => -8, -1 => 6, -2 => ['@bytes' => str_repeat("\x01", 32)]]); // Ed25519 não habilitado
    }
}
