<?php
final class HeadersSessionTest extends IntegrationCase
{
    public function testPublicPagesLoad(): void
    {
        $c = $this->dev();
        foreach (['/', '/privacidade.php', '/escala.php', '/manifest.webmanifest', '/sw.js', '/offline.html', '/assets/css/app.css', '/assets/js/app.js'] as $p) {
            $this->assertSame(200, $c->get($p)['status'], $p);
        }
        $this->assertStringContainsString('Um lembrete sobre o sono', $c->get('/escala.php')['body']);
    }
    public function testSecurityHeadersOnPagesAndApi(): void
    {
        $c = $this->user('hdr');
        foreach (['/', '/privacidade.php', '/api/entries.php', '/relatorio.php', '/confirmar.php?t=x'] as $p) {
            $r = $c->get($p);
            $csp = (string)$c->header($r, 'Content-Security-Policy');
            $this->assertStringContainsString("frame-ancestors 'none'", $csp, $p);
            $this->assertStringContainsString("default-src 'self'", $csp, $p);
            $this->assertStringContainsString("script-src 'self'", $csp, $p);
            $this->assertStringNotContainsString("'unsafe-eval'", $csp, $p);
            $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp, $p);
            $this->assertSame('DENY', $c->header($r, 'X-Frame-Options'), $p);
            $this->assertSame('nosniff', $c->header($r, 'X-Content-Type-Options'), $p);
            $this->assertSame('strict-origin-when-cross-origin', $c->header($r, 'Referrer-Policy'), $p);
            $this->assertNotNull($c->header($r, 'Permissions-Policy'), $p);
        }
        $this->assertSame('no-store', $c->header($c->get('/api/entries.php'), 'Cache-Control'));
    }
    public function testSessionCookieFlags(): void
    {
        $c = $this->dev();
        $r = $c->get('/');
        $cookie = (string)$c->header($r, 'Set-Cookie');
        $this->assertStringStartsWith('bpsid=', $cookie);
        $this->assertStringContainsStringIgnoringCase('HttpOnly', $cookie);
        $this->assertStringContainsStringIgnoringCase('SameSite=Lax', $cookie);
        $this->assertStringContainsString('path=/', $cookie);
        // atrás de proxy HTTPS (como no cPanel com SSL)
        $r = $this->dev()->get('/', ['X-Forwarded-Proto' => 'https']);
        $cookie = (string)$c->header($r, 'Set-Cookie');
        $this->assertStringContainsStringIgnoringCase('; secure', $cookie);
        $this->assertStringContainsString('max-age=31536000', (string)$c->header($r, 'Strict-Transport-Security'));
    }
    public function testSessionIdRegeneratedOnLogin(): void
    {
        $c = $this->dev();
        preg_match('/bpsid=([^;]+)/', (string)$c->header($c->get('/'), 'Set-Cookie'), $before);
        $r = $c->get('/auth/dev.php?u=fix');
        preg_match('/bpsid=([^;]+)/', implode("\n", $r['headers']['set-cookie'] ?? []), $after);
        $this->assertNotEmpty($after[1] ?? null);
        $this->assertNotSame($before[1], $after[1], 'evita fixação de sessão');
    }
    public function testStrictSessionModeRejectsForgedId(): void
    {
        $c = $this->dev();
        $r = $c->get('/', ['Cookie' => 'bpsid=attackerchosenid1234567890']);
        $this->assertStringNotContainsString('bpsid=attackerchosenid1234567890', (string)$c->header($r, 'Set-Cookie'));
    }
    public function testInternalFilesNotServedAndNoErrorLeak(): void
    {
        $c = $this->prod();
        $r = $c->get('/_boot.php');
        $this->assertStringNotContainsString('APP_ROOT', $r['body']);
        $r = $c->get('/api/entries.php?from=x');
        $this->assertSame(401, $r['status']);
        $this->assertStringNotContainsString('Stack trace', $r['body']);
    }
}
