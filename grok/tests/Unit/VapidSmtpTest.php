<?php
use PHPUnit\Framework\TestCase;

final class VapidSmtpTest extends TestCase
{
    public function testVapidJwtIsValidEs256(): void
    {
        $jwt = vapid_jwt('https://fcm.googleapis.com');
        [$h, $c, $s] = explode('.', $jwt);
        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(b64url_decode($h), true));
        $claims = json_decode(b64url_decode($c), true);
        $this->assertSame('https://fcm.googleapis.com', $claims['aud']);
        $this->assertSame('mailto:contato@bipolar.app.br', $claims['sub']);
        $this->assertLessThanOrEqual(time() + 86400, $claims['exp'], 'RFC 8292: exp ≤ 24h');
        $raw = b64url_decode($s);
        $this->assertSame(64, strlen($raw));
        // converte r||s de volta para DER e verifica com a chave pública derivada do PEM
        $int = function (string $x): string { $x = ltrim($x, "\0"); if ($x === '' || ord($x[0]) & 0x80) $x = "\0" . $x; return "\x02" . chr(strlen($x)) . $x; };
        $der = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
        $der = "\x30" . chr(strlen($der)) . $der;
        $pub = openssl_pkey_get_details(openssl_pkey_get_private(cfg('vapid.private_key_pem')))['key'];
        $this->assertSame(1, openssl_verify("$h.$c", $der, $pub, OPENSSL_ALGO_SHA256));
        // a chave pública do config bate com a privada
        $d = openssl_pkey_get_details(openssl_pkey_get_private(cfg('vapid.private_key_pem')));
        $this->assertSame(cfg('vapid.public_key'), b64url("\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT)));
    }
    public function testDerToRawPadsShortIntegers(): void
    {
        $r = "\x00" . str_repeat("\x81", 32);   // 33 bytes com zero à esquerda
        $s = str_repeat("\x01", 31);            // 31 bytes (precisa padding)
        $der = "\x30" . chr(4 + 33 + 31) . "\x02\x21" . $r . "\x02\x1f" . $s;
        $raw = der_to_raw_sig($der);
        $this->assertSame(64, strlen($raw));
        $this->assertSame(str_repeat("\x81", 32), substr($raw, 0, 32));
        $this->assertSame("\x00" . str_repeat("\x01", 31), substr($raw, 32));
    }
    public function testMimeMessage(): void
    {
        $msg = build_mime_message('ana@example.com', 'Relatório de humor — ç', '<p>Olá <b>Ana</b></p><p>.linha</p>');
        $this->assertStringContainsString("To: <ana@example.com>\r\n", $msg);
        $this->assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Relatório de humor — ç') . "?=\r\n", $msg);
        $this->assertStringContainsString("MIME-Version: 1.0\r\n", $msg);
        $this->assertMatchesRegularExpression('/boundary="(b[a-f0-9]{24})"/', $msg);
        $this->assertSame('<p>Olá <b>Ana</b></p><p>.linha</p>', TestEnv::htmlPart($msg));
        $this->assertDoesNotMatchRegularExpression("/(?<!\r)\n/", $msg, 'somente CRLF');
        foreach (explode("\r\n", $msg) as $line) $this->assertLessThanOrEqual(998, strlen($line));
    }
    public function testHeaderInjectionRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        build_mime_message("ana@example.com\r\nBcc: x@evil.com", 's', 'b');
    }
    public function testSubjectCannotInjectHeaders(): void
    {
        $msg = build_mime_message('a@example.com', "Oi\r\nBcc: x@evil.com", 'b');
        $this->assertStringNotContainsString("\r\nBcc:", $msg);
    }
    public function testDotStuffing(): void
    {
        $this->assertSame("a\r\n..b\r\n", preg_replace('/^\./m', '..', "a\r\n.b\r\n"));
        $this->assertDoesNotMatchRegularExpression('/^\.[^.]/m', build_mime_message('a@example.com', 's', 'x', ".\r\n.oi"));
    }
    public function testHtmlToText(): void
    {
        $this->assertSame("Olá\nmundo & cia", html_to_text('<p>Olá</p>mundo &amp; cia'));
    }
    public function testSendMailRejectsInvalidAddress(): void
    {
        $this->assertFalse(send_mail('not-an-email', 's', 'b'));
    }
}
