<?php
use PHPUnit\Framework\TestCase;

final class OAuthTest extends TestCase
{
    protected function setUp(): void { $_SESSION = []; $_GET = []; }

    public function testPkceRfc7636Vector(): void
    {
        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', pkce_challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
    }
    /** @return list<array{string,string}> */
    public static function providers(): array
    {
        return [['google', 'https://accounts.google.com/'], ['microsoft', 'https://login.microsoftonline.com/common/'], ['x', 'https://twitter.com/']];
    }
    /** @dataProvider providers */
    public function testAuthUrl(string $p, string $prefix): void
    {
        $url = (string)oauth_auth_url($p);
        $this->assertStringStartsWith($prefix, $url);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $s = $_SESSION['oauth'];
        $this->assertSame($p, $s['p']);
        $this->assertSame($s['state'], $q['state']);
        $this->assertGreaterThanOrEqual(32, strlen($s['state']));
        $this->assertGreaterThanOrEqual(43, strlen($s['verifier']));
        $this->assertSame(pkce_challenge($s['verifier']), $q['code_challenge']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame(cfg('app_url') . '/auth/callback.php', $q['redirect_uri']);
        $this->assertSame('code', $q['response_type']);
    }
    public function testUnknownProvider(): void
    {
        $this->assertNull(oauth_auth_url('evil'));
        $this->assertNull(oauth_auth_url('google/../x'));
    }
    public function testStateMismatchRejected(): void
    {
        oauth_auth_url('google');
        $_GET = ['state' => 'forjado', 'code' => 'abc'];
        $this->expectExceptionMessage('state inválido');
        oauth_callback('google');
    }
    public function testStateArrayRejected(): void
    {
        oauth_auth_url('google');
        $_GET = ['state' => ['x'], 'code' => 'abc'];
        $this->expectException(RuntimeException::class);
        oauth_callback('google');
    }
    public function testProviderSwapRejected(): void
    {
        oauth_auth_url('google');
        $_GET = ['state' => $_SESSION['oauth']['state'], 'code' => 'abc'];
        $this->expectExceptionMessage('expirada');
        oauth_callback('x');
    }
    public function testExpiredAndSingleUse(): void
    {
        oauth_auth_url('google');
        $_SESSION['oauth']['t'] = time() - 601;
        $_GET = ['state' => $_SESSION['oauth']['state'], 'code' => 'abc'];
        try { oauth_callback('google'); $this->fail('deveria expirar'); } catch (RuntimeException $e) { $this->assertStringContainsString('expirada', $e->getMessage()); }
        $this->assertArrayNotHasKey('oauth', $_SESSION, 'state é de uso único');
        $this->expectException(RuntimeException::class);
        oauth_callback('google'); // replay
    }
    public function testProviderErrorParam(): void
    {
        oauth_auth_url('microsoft');
        $_GET = ['state' => $_SESSION['oauth']['state'], 'error' => 'access_denied'];
        $this->expectExceptionMessage('cancelado');
        oauth_callback('microsoft');
    }
}
