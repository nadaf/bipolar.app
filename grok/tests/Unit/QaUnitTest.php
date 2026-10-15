<?php
use PHPUnit\Framework\TestCase;

final class QaUnitTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $cfgBackup;
    protected function setUp(): void { $this->cfgBackup = $GLOBALS['CONFIG']; }
    protected function tearDown(): void { $GLOBALS['CONFIG'] = $this->cfgBackup; unset($_SERVER['HTTP_X_LOADTEST_BYPASS']); }

    public function testFmtNumBrazilian(): void
    {
        $this->assertSame('6,5', fmt_num(6.5));
        $this->assertSame('7,0', fmt_num('7'));
        $this->assertSame('1.234,57', fmt_num(1234.567, 2));
        $this->assertSame('−0,33', fmt_num(-0.333, 2, true));
        $this->assertSame('0,0', fmt_num(-0.01, 1));
        $this->assertSame('', fmt_num(null));
        $this->assertSame('', fmt_num(''));
    }
    public function testPushConfigured(): void
    {
        $this->assertTrue(push_configured(), 'config de teste tem VAPID');
        $GLOBALS['CONFIG']['vapid']['public_key'] = '';
        $this->assertFalse(push_configured());
        $GLOBALS['CONFIG'] = $this->cfgBackup;
        $GLOBALS['CONFIG']['vapid']['private_key_pem'] = '';
        $this->assertFalse(push_configured());
        $GLOBALS['CONFIG']['vapid'] = ['public_key' => 'SEU_...', 'private_key_pem' => 'x'];
        $this->assertFalse(push_configured());
    }
    public function testLoadtestBypassRules(): void
    {
        $t = str_repeat('a', 40);
        $GLOBALS['CONFIG']['loadtest_bypass_ratelimit_token'] = '';
        $_SERVER['HTTP_X_LOADTEST_BYPASS'] = '';
        $this->assertFalse(loadtest_bypass(), 'vazio = desativado');
        $GLOBALS['CONFIG']['loadtest_bypass_ratelimit_token'] = 'curto';
        $_SERVER['HTTP_X_LOADTEST_BYPASS'] = 'curto';
        $this->assertFalse(loadtest_bypass(), 'token curto (< 32) é ignorado');
        $GLOBALS['CONFIG']['loadtest_bypass_ratelimit_token'] = $t;
        $_SERVER['HTTP_X_LOADTEST_BYPASS'] = $t . 'x';
        $this->assertFalse(loadtest_bypass());
        $_SERVER['HTTP_X_LOADTEST_BYPASS'] = $t;
        $this->assertTrue(loadtest_bypass());
        $this->assertTrue(rate_limit('qa-unit-bypass', 0, 60), 'rate_limit liberado com bypass');
        unset($_SERVER['HTTP_X_LOADTEST_BYPASS']);
        $this->assertFalse(rate_limit('qa-unit-bypass', 0, 60));
    }
    public function testTimezoneOptions(): void
    {
        $o = timezone_options();
        $this->assertSame('America/Sao_Paulo', array_key_first($o['brasil']));
        $this->assertSame('America/Eirunepe', array_key_last($o['brasil']));
        $this->assertCount(16, $o['brasil']);
        $this->assertNotContains('America/Cuiaba', $o['outros']);
        $this->assertContains('UTC', $o['outros']);
        $this->assertContains('Asia/Tokyo', $o['outros']);
    }
    public function testMaskEmail(): void
    {
        $this->assertSame('te***@bipolar.app.br', mask_email('teste.grok+dest@bipolar.app.br'));
        $this->assertSame('a***@x.com', mask_email('a@x.com'));
    }

    /** SMTP com SSL implícito (porta 465): mensagem grande (escrita parcial) e destinatário recusado. */
    public function testSmtpImplicitSslLargeMessageAndRejectedRcpt(): void
    {
        $dir = TestEnv::$tmp . '/mail-ssl';
        @mkdir($dir, 0777, true);
        array_map('unlink', glob("$dir/*") ?: []);
        $cert = $dir . '/cert.pem';
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'localhost'], $key);
        $x509 = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export($x509, $crt);
        openssl_pkey_export($key, $pem);
        file_put_contents($cert, $crt . $pem);
        $s = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)substr((string)strrchr((string)stream_socket_get_name($s, false), ':'), 1); fclose($s);
        $proc = proc_open([PHP_BINARY, __DIR__ . '/../tools/fake_smtp.php', (string)$port, $dir, $cert], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        usleep(400000);
        try {
            $GLOBALS['CONFIG']['smtp'] = ['host' => 'localhost', 'port' => $port, 'secure' => 'ssl', 'user' => 'u', 'pass' => 'p', 'allow_self_signed' => true,
                'from_email' => 'nao-responda@bipolar.app.br', 'from_name' => 'Bipolar'];
            $big = '<p>' . str_repeat('Relatório com acentuação — linha longa de teste. ', 8000) . '</p>';
            $r = send_mail_ex('dest@example.com', 'Grande', $big, '', 'teste');
            $this->assertTrue($r['ok'], json_encode($r));
            $files = glob("$dir/*.eml") ?: [];
            $this->assertCount(1, $files);
            $this->assertSame($big, TestEnv::htmlPart((string)file_get_contents($files[0])), 'mensagem de ~400 KB chega íntegra via SSL');

            $log = tempnam(sys_get_temp_dir(), 'elog');
            $old = ini_set('error_log', $log);
            $r = send_mail_ex('ninguem+rejeitar@bipolar.app.br', 'X', '<p>x</p>', '', 'convite');
            ini_set('error_log', (string)$old);
            $this->assertFalse($r['ok']);
            $this->assertTrue($r['recipient_rejected']);
            $this->assertSame('rcpt', $r['stage']);
            $this->assertSame(550, $r['code']);
            $this->assertStringContainsString('SMTP [convite] DESTINATÁRIO RECUSADO ni***@bipolar.app.br', (string)file_get_contents($log));

            // porta/modo trocados: falha registrada com dica
            $GLOBALS['CONFIG']['smtp']['secure'] = 'tls';
            $GLOBALS['CONFIG']['smtp']['port'] = 465;
            $GLOBALS['CONFIG']['smtp']['host'] = '127.0.0.1';
            ini_set('error_log', $log);
            $r = send_mail_ex('dest@example.com', 'X', '<p>x</p>', '', 'teste');
            ini_set('error_log', (string)$old);
            $this->assertFalse($r['ok']);
            $this->assertFalse($r['recipient_rejected']);
        } finally {
            $st = proc_get_status($proc); if ($st['running']) posix_kill($st['pid'], SIGTERM); proc_close($proc);
        }
    }

    public function testLoadtestSeedAndCleanupOnlyTouchTestAccounts(): void
    {
        require_once APP_ROOT . '/lib/loadtest.php';
        $pdo = db();
        foreach (['loadtest+abc@lt.test', 'xloadtest+1@lt.test', 'loadtest+1@outro.test'] as $decoy) {
            $pdo->prepare('INSERT INTO users (name, email) VALUES (?, ?)')->execute(['isca', $decoy]);
        }
        $r = loadtest_seed(3, 'Senha-de-teste-123', 2, 'lt.test');
        $this->assertSame(['created' => 3, 'existing' => 0, 'entries' => 18], $r);
        $this->assertSame(0, loadtest_seed(3, 'Senha-de-teste-123', 2, 'lt.test')['created'], 'idempotente');
        $u = find_email_user('loadtest+002@lt.test');
        $this->assertNotNull($u['email_verified_at']);
        $this->assertTrue(password_verify('Senha-de-teste-123', (string)$u['password_hash']));
        $dry = loadtest_cleanup(true, 'lt.test');
        $this->assertSame(3, $dry['found']);
        $this->assertSame(0, $dry['deleted']);
        $this->assertSame(3, loadtest_cleanup(false, 'lt.test')['deleted']);
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM entries e JOIN users u ON u.id = e.user_id WHERE u.email LIKE 'loadtest+0%@lt.test'")->fetchColumn());
        $this->assertSame(3, (int)$pdo->query("SELECT COUNT(*) FROM users WHERE name = 'isca'")->fetchColumn(), 'iscas preservadas');
        $this->expectException(InvalidArgumentException::class);
        loadtest_seed(1, 'curta', 0, 'lt.test');
    }
}
