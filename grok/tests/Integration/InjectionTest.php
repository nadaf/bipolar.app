<?php
final class InjectionTest extends IntegrationCase
{
    private const XSS = ['<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '"><svg onload=alert(1)>', "javascript:alert('x')"];

    public function testSqlInjectionPayloads(): void
    {
        $c = $this->user('sqli');
        foreach (["2026-01-01' OR '1'='1", "2026-01-01'; DROP TABLE entries; --", '1 UNION SELECT 1'] as $p) {
            $this->assertSame(422, $c->get('/api/entries.php?from=' . rawurlencode($p) . '&to=2026-12-31')['status'], $p);
        }
        $payload = "x'); DROP TABLE entries; -- \" OR 1=1 #";
        $this->entry($c, ['note' => $payload, 'tags' => "a' OR '1'='1"]);
        $this->assertSame($payload, db()->query('SELECT note FROM entries WHERE user_id = ' . $this->uid('sqli'))->fetchColumn());
        $this->assertSame(422, $c->json('POST', '/api/entries.php', ['entry_date' => '2026-10-10', 'period' => "manha' OR 1=1 --", 'mood' => 0])['status']);
        $other = $this->user('sqlivictim');
        $vid = $this->entry($other)['id'];
        $c->json('DELETE', '/api/entries.php?id=' . rawurlencode("$vid OR 1=1"), null);
        $c->json('DELETE', '/api/media.php?id=' . rawurlencode('0 OR 1=1'), null);
        $this->assertSame(1, (int)db()->query("SELECT COUNT(*) FROM entries WHERE id = $vid")->fetchColumn());
        $this->assertSame(404, $c->get('/api/media.php?id=' . rawurlencode('1 OR 1=1'))['status']);
        $this->assertSame(422, $c->json('POST', '/api/account.php?action=settings', ['timezone' => "UTC' --"])['status']);
        $r = $this->dev()->get('/confirmar.php?t=' . rawurlencode("' OR '1'='1"));
        $this->assertStringContainsString('Link inválido', $r['body']);
        $this->assertContains('entries', db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    }
    public function testXssEscapedInAppReportAndEmails(): void
    {
        $c = $this->user('xss');
        $c->json('POST', '/api/account.php?action=settings', ['name' => '<script>alert("nome")</script>', 'email' => 'xss@example.com', 'timezone' => 'America/Cuiaba', 'extreme_alerts' => true]);
        $this->entry($c, ['entry_date' => '2026-10-12', 'note' => self::XSS[0] . self::XSS[3], 'tags' => self::XSS[1] . ',' . self::XSS[2]]);
        TestEnv::clearMails();
        $r = $c->json('POST', '/api/recipients.php', ['name' => self::XSS[2], 'email' => 'medica@example.com', 'role' => 'psiquiatra', 'frequency' => 'diaria', 'include_notes' => 1]);
        $this->assertTrue($r['json']['email_enviado']);

        $pages = [
            'app' => $c->get('/')['body'],
            'relatorio' => $c->get('/relatorio.php?de=2026-10-01&ate=2026-10-14&notas=1')['body'],
            'convite' => TestEnv::htmlPart(TestEnv::mails()[0]),
        ];
        // relatório por e-mail via cron
        db()->exec("UPDATE recipients SET confirmed_at = UTC_TIMESTAMP(), next_send_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE, created_at = '2026-10-01' WHERE user_id = " . $this->uid('xss'));
        TestEnv::clearMails();
        [$code, $out] = TestEnv::runCron('send_reports.php');
        $this->assertSame(0, $code, $out);
        $mails = TestEnv::mails();
        $this->assertCount(1, $mails, $out);
        $pages['email-relatorio'] = TestEnv::htmlPart($mails[0]);
        $this->assertStringContainsString('&lt;script&gt;', $pages['email-relatorio'], 'anotações incluídas (escapadas)');
        $this->assertStringContainsString('Anotações', $pages['relatorio']);

        foreach ($pages as $where => $html) {
            $this->assertNotSame('', $html, $where);
            foreach (['<script>alert', '<img src=x', '<svg onload', 'onerror=alert(1)>'] as $bad) {
                $this->assertStringNotContainsString($bad, $html, "$where contém '$bad' sem escape");
            }
        }
        // JSON embutido na página usa JSON_HEX_TAG (não fecha o <script>)
        $this->assertStringContainsString('\u003Cscript\u003E', $pages['app']);
    }
    public function testReportWithoutNotesOmitsNotes(): void
    {
        $c = $this->user('nonotes');
        $this->entry($c, ['entry_date' => '2026-10-12', 'note' => 'PRIVADO-123']);
        $this->assertStringNotContainsString('PRIVADO-123', $c->get('/relatorio.php?de=2026-10-01&ate=2026-10-14')['body']);
        $this->assertStringContainsString('PRIVADO-123', $c->get('/relatorio.php?de=2026-10-01&ate=2026-10-14&notas=1')['body']);
        $this->assertStringNotContainsString('PRIVADO-123', report_html(['id' => $this->uid('nonotes'), 'name' => 'x'], report_data($this->uid('nonotes'), '2026-10-01', '2026-10-14'), false));
    }
}
