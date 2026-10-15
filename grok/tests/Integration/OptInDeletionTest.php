<?php
final class OptInDeletionTest extends IntegrationCase
{
    private function invite(Http $c, string $email): string
    {
        $r = $c->json('POST', '/api/recipients.php', ['name' => 'Psi', 'email' => $email, 'role' => 'psicologo', 'frequency' => 'diaria']);
        $this->assertSame(200, $r['status'], $r['body']);
        $st = db()->prepare('SELECT confirm_token FROM recipients WHERE email = ?');
        $st->execute([$email]);
        return (string)$st->fetchColumn();
    }
    public function testOptInFlow(): void
    {
        $c = $this->user('optin');
        $tok = $this->invite($c, 'psi@example.com');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $tok);
        $anon = $this->dev();
        // token forjado
        $forged = substr($tok, 0, 63) . ($tok[63] === 'a' ? 'b' : 'a');
        $this->assertStringContainsString('Link inválido', $anon->request('POST', '/confirmar.php', 't=' . $forged)['body']);
        // GET apenas mostra (scanners de link não confirmam)
        $anon->get('/confirmar.php?t=' . $tok);
        $this->assertNull(db()->query("SELECT confirmed_at FROM recipients WHERE confirm_token = '$tok'")->fetchColumn());
        // cron não envia sem confirmação
        db()->exec('UPDATE recipients SET next_send_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE user_id = ' . $this->uid('optin'));
        TestEnv::clearMails();
        TestEnv::runCron('send_reports.php');
        $this->assertCount(0, TestEnv::mails());
        TestEnv::clearMails();
        // confirma via POST
        $this->assertStringContainsString('Recebimento confirmado', $anon->request('POST', '/confirmar.php', 't=' . $tok)['body']);
        TestEnv::runCron('send_reports.php');
        $mails = TestEnv::mails();
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('RCPT TO:<psi@example.com>', $mails[0]);
        $this->assertStringContainsString('cancelar=1&amp;t=' . $tok, TestEnv::htmlPart($mails[0]));
        // cancelamento
        $anon->request('POST', '/confirmar.php', 't=' . $tok . '&cancelar=1');
        db()->exec('UPDATE recipients SET next_send_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE user_id = ' . $this->uid('optin'));
        TestEnv::clearMails();
        TestEnv::runCron('send_reports.php');
        $this->assertCount(0, TestEnv::mails());
    }
    public function testInviteExpires(): void
    {
        $c = $this->user('expire');
        $tok = $this->invite($c, 'old@example.com');
        db()->exec("UPDATE recipients SET created_at = UTC_TIMESTAMP() - INTERVAL 15 DAY WHERE confirm_token = '$tok'");
        $r = $this->dev()->request('POST', '/confirmar.php', 't=' . $tok);
        $this->assertStringContainsString('Convite expirado', $r['body']);
        $this->assertNull(db()->query("SELECT confirmed_at FROM recipients WHERE confirm_token = '$tok'")->fetchColumn());
    }
    public function testInviteEmailNotSentToInvalidAddress(): void
    {
        $c = $this->user('badmail');
        $this->assertSame(422, $c->json('POST', '/api/recipients.php', ['name' => 'X', 'email' => "a@b.com\r\nBcc: evil@x.com"])['status']);
        $this->assertSame(422, $c->json('POST', '/api/recipients.php', ['name' => '', 'email' => 'a@b.com'])['status']);
    }
    public function testAccountDeletionRemovesEverything(): void
    {
        $c = $this->user('del');
        $uid = $this->uid('del');
        $eid = $this->entry($c, ['note' => 'apagar'])['id'];
        $c->upload('/api/media.php', $this->png(), 'a.png', 'image/png', ['entry_id' => (string)$eid]);
        $this->invite($c, 'del-psi@example.com');
        $c->json('POST', '/api/reminders.php', ['time_local' => '08:00', 'days' => [1]]);
        $c->json('POST', '/api/push.php', ['endpoint' => 'https://push.example/del']);
        $dir = TestEnv::$tmp . '/storage/media/' . $uid;
        $this->assertNotEmpty(glob($dir . '/*'));
        $keep = $this->user('keep');
        $this->entry($keep);

        $this->assertSame(422, $c->json('POST', '/api/account.php?action=delete', ['confirm' => 'excluir'])['status']);
        $this->assertSame(200, $c->json('POST', '/api/account.php?action=delete', ['confirm' => 'EXCLUIR'])['status']);
        foreach (['users' => 'id', 'oauth_identities' => 'user_id', 'entries' => 'user_id', 'media' => 'user_id', 'recipients' => 'user_id', 'reminders' => 'user_id', 'push_subscriptions' => 'user_id'] as $t => $col) {
            $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM $t WHERE $col = $uid")->fetchColumn(), $t);
        }
        $this->assertDirectoryDoesNotExist($dir);
        $this->assertSame(401, $c->get('/api/entries.php')['status'], 'sessão encerrada');
        $this->assertSame(1, (int)db()->query('SELECT COUNT(*) FROM entries WHERE user_id = ' . $this->uid('keep'))->fetchColumn(), 'outros usuários intactos');
    }
    public function testRemindersCronSendsOnceInWindow(): void
    {
        $c = $this->user('remind');
        $c->json('POST', '/api/account.php?action=settings', ['name' => 'Rê', 'email' => 'remind@example.com', 'timezone' => 'America/Cuiaba', 'extreme_alerts' => true]);
        $now = new DateTime('now', new DateTimeZone('America/Cuiaba'));
        $c->json('POST', '/api/reminders.php', ['time_local' => $now->format('H:i'), 'days' => [(int)$now->format('w')], 'channel' => 'email']);
        $c->json('POST', '/api/reminders.php', ['time_local' => $now->modify('+2 hours')->format('H:i'), 'days' => [0, 1, 2, 3, 4, 5, 6], 'channel' => 'email']);
        TestEnv::clearMails();
        [$code, $out] = TestEnv::runCron('send_reminders.php');
        $this->assertSame(0, $code, $out);
        $mine = array_filter(TestEnv::mails(), fn ($m) => str_contains($m, 'RCPT TO:<remind@example.com>'));
        $this->assertCount(1, $mine, $out);
        TestEnv::clearMails();
        TestEnv::runCron('send_reminders.php');
        $this->assertCount(0, array_filter(TestEnv::mails(), fn ($m) => str_contains($m, 'remind@example.com')), 'não repete no mesmo dia');
    }
}
