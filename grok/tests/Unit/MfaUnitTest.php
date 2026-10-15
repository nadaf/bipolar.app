<?php
use PHPUnit\Framework\TestCase;

/** V4.4: TOTP (vetores oficiais RFC 6238 / RFC 4226), base32, janela, reuso, cifra do segredo, QR local e placeholders do OAuth. */
final class MfaUnitTest extends TestCase
{
    /** RFC 6238, apêndice B (8 dígitos). */
    public function testRfc6238Vectors(): void
    {
        $keys = ['sha1' => '12345678901234567890', 'sha256' => '12345678901234567890123456789012',
            'sha512' => '1234567890123456789012345678901234567890123456789012345678901234'];
        $vec = [
            [59, '94287082', '46119246', '90693936'], [1111111109, '07081804', '68084774', '25091201'],
            [1111111111, '14050471', '67062674', '99943326'], [1234567890, '89005924', '91819424', '93441116'],
            [2000000000, '69279037', '90698825', '38618901'], [20000000000, '65353130', '77737706', '47863826'],
        ];
        foreach ($vec as [$t, $s1, $s256, $s512]) {
            $this->assertSame($s1, totp_code($keys['sha1'], $t, 8, 'sha1'), "SHA1 T=$t");
            $this->assertSame($s256, totp_code($keys['sha256'], $t, 8, 'sha256'), "SHA256 T=$t");
            $this->assertSame($s512, totp_code($keys['sha512'], $t, 8, 'sha512'), "SHA512 T=$t");
        }
    }
    /** RFC 4226, apêndice D (6 dígitos, o formato dos apps autenticadores). */
    public function testRfc4226HotpVectors(): void
    {
        $exp = ['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'];
        foreach ($exp as $i => $c) $this->assertSame($c, hotp('12345678901234567890', $i));
    }
    public function testBase32Rfc4648(): void
    {
        foreach (['' => '', 'f' => 'MY', 'fo' => 'MZXQ', 'foo' => 'MZXW6', 'foob' => 'MZXW6YQ', 'fooba' => 'MZXW6YTB', 'foobar' => 'MZXW6YTBOI'] as $plain => $b32) {
            $this->assertSame($b32, base32_encode($plain));
            if ($plain !== '') $this->assertSame($plain, base32_decode($b32));
        }
        $this->assertSame('foobar', base32_decode('mzxw 6ytb-oi'), 'aceita minúsculas, espaços e hífens');
        $this->assertNull(base32_decode('ABC1'));
        $s = mfa_new_secret();
        $this->assertSame(32, strlen($s), '160 bits');
        $this->assertSame(20, strlen((string)base32_decode($s)));
    }
    public function testWindowPlusMinusOneAndReplay(): void
    {
        $key = random_bytes(20);
        $t = 1_800_000_015;
        $now = totp_step($t);
        $this->assertSame($now, totp_match($key, totp_code($key, $t), $t));
        $this->assertSame($now - 1, totp_match($key, totp_code($key, $t - 30), $t), 'passo anterior aceito');
        $this->assertSame($now + 1, totp_match($key, totp_code($key, $t + 30), $t), 'passo seguinte aceito');
        $this->assertNull(totp_match($key, totp_code($key, $t - 60), $t), '2 passos atrás: recusado');
        $this->assertNull(totp_match($key, totp_code($key, $t + 60), $t), '2 passos à frente: recusado');
        $this->assertNull(totp_match($key, totp_code($key, $t), $t, $now), 'reuso do mesmo passo');
        $this->assertNull(totp_match($key, totp_code($key, $t - 30), $t, $now), 'passo mais antigo que o último aceito');
        $this->assertSame($now + 1, totp_match($key, totp_code($key, $t + 30), $t, $now));
        foreach (['', '12345', '1234567', 'abcdef', '12 3456'] as $bad) $this->assertNull(totp_match($key, $bad, $t), $bad);
    }
    public function testSecretEncryptedAndBoundToUser(): void
    {
        $this->assertTrue(mfa_available());
        $enc = mfa_encrypt_secret(7, 'JBSWY3DPEHPK3PXP');
        $this->assertStringStartsWith('m1:', $enc);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $enc);
        $this->assertNotSame($enc, mfa_encrypt_secret(7, 'JBSWY3DPEHPK3PXP'), 'nonce aleatório');
        $this->assertSame('JBSWY3DPEHPK3PXP', mfa_decrypt_secret(7, $enc));
        $this->assertNull(mfa_decrypt_secret(8, $enc), 'segredo copiado para outra conta não abre');
        $this->assertNull(mfa_decrypt_secret(7, 'v1:' . substr($enc, 3)));
        // subchave própria: não é a mesma chave da IA
        $this->assertNotSame(ai_server_key(), mfa_key());
        $this->assertNull(ai_decrypt_key('v1:' . substr($enc, 3)));
    }
    public function testWithoutSecretKeyMfaIsUnavailable(): void
    {
        $old = $GLOBALS['CONFIG']['ai'] ?? null;
        try {
            $GLOBALS['CONFIG']['ai']['secret_key'] = '';
            $this->assertFalse(mfa_available());
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('ai.secret_key');
            mfa_encrypt_secret(1, 'JBSWY3DPEHPK3PXP');
        } finally { $GLOBALS['CONFIG']['ai'] = $old; }
    }
    public function testRecoveryCodeHashing(): void
    {
        $this->assertSame(mfa_recovery_hash(3, 'ab2cd-ef3gh'), mfa_recovery_hash(3, ' AB2CD EF3GH '));
        $this->assertNotSame(mfa_recovery_hash(3, 'ab2cd-ef3gh'), mfa_recovery_hash(4, 'ab2cd-ef3gh'), 'hash amarrado à conta');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', mfa_recovery_hash(3, 'x'));
    }
    public function testOtpauthUriAndLocalQr(): void
    {
        $uri = mfa_otpauth_uri('JBSWY3DPEHPK3PXP', 'ana+teste@example.com');
        $this->assertSame('otpauth://totp/Bipolar:ana%2Bteste%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Bipolar&algorithm=SHA1&digits=6&period=30', $uri);
        $this->assertSame('JBSW Y3DP EHPK 3PXP', mfa_secret_display('JBSWY3DPEHPK3PXP'));
        require_once TestEnv::ROOT . '/app/lib/qr.php';
        $m = qr_matrix($uri);
        $this->assertSame(121, strlen($uri));
        $this->assertCount(17 + 4 * 7, $m, '121 bytes → versão 7 (45×45), com bloco de versão');
        // padrões de localização nos 3 cantos
        foreach ([[0, 0], [count($m) - 7, 0], [0, count($m) - 7]] as [$x, $y]) {
            $this->assertTrue($m[$y][$x] && $m[$y + 6][$x + 6] && $m[$y + 3][$x + 3]);
            $this->assertFalse($m[$y + 1][$x + 1]);
        }
        $svg = qr_svg($uri);
        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertStringNotContainsString('http://', substr($svg, 40), 'nenhuma referência externa');
        $this->assertCount(17 + 4 * 15, qr_matrix(str_repeat('a', 400)), 'versão 15');
        $this->expectException(InvalidArgumentException::class);
        qr_matrix(str_repeat('a', 500));
    }
    public function testOauthPlaceholdersFromExampleConfigAreHidden(): void
    {
        $example = require TestEnv::ROOT . '/app/config.example.php';
        foreach ($example['oauth'] as $p => $v) {
            $this->assertTrue(oauth_placeholder((string)$v['client_id']), "$p client_id");
            $this->assertTrue(oauth_placeholder((string)$v['client_secret']), "$p client_secret");
        }
        foreach (['', '  ', 'seu_client_id', 'abc-SEU_X', 'TROQUE_ISTO', 'cole_aqui', '<client id>', '...', 'xxxxxxxx'] as $v) $this->assertTrue(oauth_placeholder($v), $v);
        foreach (['123-abc.apps.googleusercontent.com', 'GOCSPX-aB3_dEf', '0c9b1a2e-3f4d-4e5f-8a9b-0c1d2e3f4a5b', 'aBc~1.2_3-x', 'TjNsX2Rlc2tfaWQ6MTpjaQ'] as $v) $this->assertFalse(oauth_placeholder($v), $v);
        $old = $GLOBALS['CONFIG']['oauth'];
        try {
            $GLOBALS['CONFIG']['oauth'] = $example['oauth'];
            $this->assertSame([], oauth_enabled_providers(), 'config.example.php não mostra nenhum botão');
            $GLOBALS['CONFIG']['oauth']['x'] = ['client_id' => 'TjNsX2Rlc2tfaWQ6MTpjaQ', 'client_secret' => ''];
            $this->assertSame(['x'], array_keys(oauth_enabled_providers()), 'X público (sem segredo) aparece');
            $GLOBALS['CONFIG']['oauth']['google'] = ['client_id' => '123-abc.apps.googleusercontent.com', 'client_secret' => 'SEU_CLIENT_SECRET'];
            $this->assertArrayNotHasKey('google', oauth_enabled_providers(), 'segredo ainda de exemplo');
        } finally { $GLOBALS['CONFIG']['oauth'] = $old; }
    }
}
