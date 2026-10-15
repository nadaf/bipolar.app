<?php
use PHPUnit\Framework\TestCase;

final class SecurityUnitTest extends TestCase
{
    protected function setUp(): void { $_SESSION = []; $_GET = []; }

    public function testCsrfToken(): void
    {
        $t = csrf_token();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $t);
        $this->assertSame($t, csrf_token(), 'estável na sessão');
        $this->assertTrue(csrf_valid($t));
        $this->assertFalse(csrf_valid(substr($t, 0, -1) . 'x'));
        $this->assertFalse(csrf_valid(''));
        $this->assertFalse(csrf_valid(null));
        $this->assertFalse(csrf_valid(['x']));
        $_SESSION = [];
        $this->assertFalse(csrf_valid($t), 'sem sessão não valida');
    }
    public function testEscapeHtml(): void
    {
        $this->assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;&#039;', e('<script>alert("x")</script>\''));
        $this->assertSame("\u{FFFD}", e("\xC3"), 'UTF-8 inválido substituído');
    }
    public function testB64url(): void
    {
        $bin = random_bytes(33);
        $this->assertSame($bin, b64url_decode(b64url($bin)));
        $this->assertDoesNotMatchRegularExpression('#[+/=]#', b64url("\xfb\xff\xfe"));
    }
    public function testTimezones(): void
    {
        $this->assertTrue(valid_timezone('America/Cuiaba'));
        $this->assertFalse(valid_timezone('America/../../etc'));
    }
    public function testExampleConfigIsProductionSafe(): void
    {
        $c = require TestEnv::ROOT . '/app/config.example.php';
        $this->assertFalse($c['dev_login']);
        $this->assertStringStartsWith('https://', $c['app_url']);
    }
}
