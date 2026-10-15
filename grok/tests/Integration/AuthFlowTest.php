<?php
final class AuthFlowTest extends IntegrationCase
{
    public function testOauthStartRedirectsOnlyToProvider(): void
    {
        $c = $this->dev();
        $r = $c->get('/auth/start.php?provider=google');
        $this->assertSame(302, $r['status']);
        $loc = (string)$c->header($r, 'Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $loc);
        parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
        $this->assertSame('http://127.0.0.1:' . TestEnv::$devPort . '/auth/callback.php', $q['redirect_uri'], 'V4.4: sem query string (exigência da Microsoft)');
        foreach (['evil', 'https://evil.com', '../x', ''] as $p) {
            $r = $c->get('/auth/start.php?provider=' . rawurlencode($p));
            $this->assertSame(400, $r['status'], $p);
            $this->assertNull($c->header($r, 'Location'));
        }
    }
    public function testNoOpenRedirect(): void
    {
        $c = $this->dev();
        foreach (['/auth/callback.php?provider=google&state=x&code=y&redirect=https://evil.com',
                  '/auth/callback.php?provider=google&next=//evil.com', '/auth/callback.php?provider=evil'] as $p) {
            $r = $c->get($p);
            $this->assertSame(400, $r['status'], $p);
            $this->assertNull($c->header($r, 'Location'), $p);
        }
        foreach (['//evil.com', 'https://evil.com', '/\\evil.com'] as $p) {
            $loc = (string)$c->header($c->get('/auth/dev.php?u=redir&aba=' . rawurlencode($p)), 'Location');
            $this->assertMatchesRegularExpression('#^/(\?aba=[a-z]*)?$#', $loc, $p);
        }
        $this->assertSame('/', $c->header($c->request('POST', '/auth/logout.php?next=https://evil.com', ''), 'Location'));
    }
    public function testCallbackStateValidation(): void
    {
        $c = $this->dev();
        $c->get('/auth/start.php?provider=google');
        $r = $c->get('/auth/callback.php?provider=google&state=forjado&code=abc');
        $this->assertSame(400, $r['status']);
        $this->assertStringContainsString('state inválido', $r['body']);
        // state válido mas provedor trocado
        $loc = (string)$c->header($c->get('/auth/start.php?provider=google'), 'Location');
        parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
        $r = $c->get('/auth/callback.php?provider=x&state=' . $q['state'] . '&code=abc');
        $this->assertSame(400, $r['status']);
        $this->assertSame(401, $c->get('/api/entries.php')['status'], 'não autenticou');
    }
    public function testRateLimitOnLogin(): void
    {
        $c = $this->dev();
        $codes = [];
        for ($i = 0; $i < 22; $i++) $codes[] = $c->get('/auth/start.php?provider=google')['status'];
        $this->assertSame(array_fill(0, 20, 302), array_slice($codes, 0, 20));
        $this->assertSame(429, $codes[20]);
        $this->assertSame(429, $codes[21]);
    }
    public function testRateLimitOnRecipientInvites(): void
    {
        $c = $this->user('rl');
        $codes = [];
        for ($i = 0; $i < 11; $i++) $codes[] = $c->json('POST', '/api/recipients.php', ['name' => "R$i", 'email' => "r$i@example.com"])['status'];
        $this->assertSame(429, $codes[10]);
    }
    public function testDevLoginDisabledInProduction(): void
    {
        $p = $this->prod();
        $r = $p->get('/auth/dev.php?u=x');
        $this->assertSame(404, $r['status']);
        $this->assertNull($p->header($r, 'Location'));
        $this->assertStringNotContainsString('/auth/dev.php', $p->get('/')['body']);
        $this->assertStringContainsString('/auth/dev.php', $this->dev()->get('/')['body'], 'controle: aparece no modo dev');
    }
    public function testMadrugadaRejected(): void
    {
        $c = $this->user('madr');
        $r = $c->json('POST', '/api/entries.php', ['entry_date' => '2026-10-10', 'period' => 'madrugada', 'mood' => 0]);
        $this->assertSame(422, $r['status']);
        $body = $c->get('/')['body'];
        $this->assertStringNotContainsString('value="madrugada"', $body);
        $this->assertStringContainsString('id="sleep-tip"', $body);
    }
    public function testExtremeAlert(): void
    {
        $c = $this->user('alert');
        $this->assertNull($this->entry($c, ['entry_date' => '2026-10-10', 'period' => 'manha', 'mood' => -2])['alerta']);
        $this->entry($c, ['entry_date' => '2026-10-10', 'period' => 'tarde', 'mood' => -3]);
        $r = $this->entry($c, ['entry_date' => '2026-10-10', 'period' => 'noite', 'mood' => -2]);
        $this->assertStringContainsString('CVV (188)', (string)$r['alerta']);
        $c->json('POST', '/api/account.php?action=settings', ['name' => 'x', 'timezone' => 'America/Cuiaba', 'extreme_alerts' => false]);
        $this->assertNull($this->entry($c, ['entry_date' => '2026-10-11', 'period' => 'manha', 'mood' => -3])['alerta']);
    }
}
