<?php
use PHPUnit\Framework\TestCase;

abstract class IntegrationCase extends TestCase
{
    protected function setUp(): void
    {
        db()->exec('DELETE FROM rate_limits');
    }
    protected function dev(): Http { return new Http('http://127.0.0.1:' . TestEnv::$devPort); }
    protected function prod(): Http { return new Http('http://127.0.0.1:' . TestEnv::$prodPort); }
    protected function user(string $u): Http { return $this->dev()->login($u); }
    protected function uid(string $u): int
    {
        $st = db()->prepare("SELECT user_id FROM oauth_identities WHERE provider = 'dev' AND subject = ?");
        $st->execute(['dev' . $u]);
        return (int)$st->fetchColumn();
    }
    protected function entry(Http $c, array $over = []): array
    {
        $r = $c->json('POST', '/api/entries.php', $over + ['entry_date' => '2026-10-10', 'period' => 'manha', 'mood' => 0]);
        $this->assertSame(200, $r['status'], $r['body']);
        return $r['json'];
    }
    protected function png(int $padBytes = 0): string
    {
        $f = tempnam(sys_get_temp_dir(), 'png');
        $img = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAwS2OUAAAAABJRU5ErkJggg==');
        file_put_contents($f, $img . str_repeat("\0", $padBytes));
        return $f;
    }
}
