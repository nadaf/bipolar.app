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
    /** V5.1: link de confirmação de e-mail mais recente enviado para $email (captura do SMTP falso). */
    protected function confirmLink(string $email): ?string
    {
        $link = null;
        foreach (TestEnv::mails() as $m) {
            if (!str_contains($m, 'RCPT TO:<' . $email . '>')) continue;
            if (preg_match('#/conta/confirmar-email\.php\?t=([A-Za-z0-9_-]+)#', TestEnv::htmlPart($m), $mm)) $link = '/conta/confirmar-email.php?t=' . $mm[1];
        }
        return $link;
    }
    /** V5.1: troca (ou define) o e-mail pela tela de Conta e confirma pelo link, como a pessoa faria. */
    protected function setEmail(Http $c, string $email, array $settings = []): void
    {
        $r = $c->json('POST', '/api/account.php?action=settings', $settings + ['name' => 'Teste', 'email' => $email, 'timezone' => 'America/Cuiaba', 'extreme_alerts' => true]);
        $this->assertSame(200, $r['status'], $r['body']);
        $link = $this->confirmLink($email);
        $this->assertNotNull($link, 'link de confirmação enviado');
        $t = substr((string)$link, strlen('/conta/confirmar-email.php?t='));
        $r = $c->form((string)$link, '/conta/confirmar-email.php', ['t' => $t]);
        $this->assertSame(302, $r['status'], $r['body']);
        $c->refreshCsrf();
    }
    protected function png(int $padBytes = 0): string
    {
        $f = tempnam(sys_get_temp_dir(), 'png');
        $img = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAwS2OUAAAAABJRU5ErkJggg==');
        file_put_contents($f, $img . str_repeat("\0", $padBytes));
        return $f;
    }
}
