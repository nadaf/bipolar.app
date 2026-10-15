<?php
final class CsrfAuthzTest extends IntegrationCase
{
    /** @return list<array{string,string}> */
    private function mutating(): array
    {
        return [
            ['POST', '/api/entries.php'], ['DELETE', '/api/entries.php?id=1'],
            ['POST', '/api/media.php'], ['DELETE', '/api/media.php?id=1'],
            ['POST', '/api/recipients.php'], ['DELETE', '/api/recipients.php?id=1'],
            ['POST', '/api/reminders.php'], ['DELETE', '/api/reminders.php?id=1'],
            ['POST', '/api/push.php'], ['POST', '/api/account.php?action=settings'], ['POST', '/api/account.php?action=delete'],
        ];
    }
    public function testCsrfRequiredOnAllMutatingEndpoints(): void
    {
        $c = $this->user('csrf');
        $before = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        foreach ($this->mutating() as [$m, $p]) {
            $body = ['entry_date' => '2026-10-10', 'period' => 'manha', 'mood' => 0, 'confirm' => 'EXCLUIR', 'name' => 'x', 'email' => 'a@b.co',
                'time_local' => '08:00', 'days' => [1], 'endpoint' => 'https://push.example/x', 'timezone' => 'UTC'];
            $this->assertSame(403, $c->json($m, $p, $body, false)['status'], "$m $p sem token");
            $this->assertSame(403, $c->json($m, $p, $body, str_repeat('0', 64))['status'], "$m $p token errado");
        }
        $this->assertSame($before, (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'conta não foi excluída');
        $this->assertSame(0, (int)db()->query('SELECT COUNT(*) FROM entries WHERE user_id = ' . $this->uid('csrf'))->fetchColumn());
        // token de outra sessão não serve
        $other = $this->user('csrf2');
        $this->assertSame(403, $c->json('POST', '/api/entries.php', ['entry_date' => '2026-10-10', 'period' => 'manha', 'mood' => 0], $other->csrf)['status']);
        // logout sem token não desloga
        $c->request('POST', '/auth/logout.php', 'csrf=errado', ['Content-Type' => 'application/x-www-form-urlencoded']);
        $this->assertSame(200, $c->get('/api/entries.php')['status']);
        $c->request('POST', '/auth/logout.php', 'csrf=' . $c->csrf, ['Content-Type' => 'application/x-www-form-urlencoded']);
        $this->assertSame(401, $c->get('/api/entries.php')['status']);
    }
    public function testUnauthenticated(): void
    {
        $c = $this->dev();
        foreach (['/api/entries.php', '/api/media.php', '/api/media.php?id=1', '/api/recipients.php', '/api/reminders.php', '/api/account.php?action=export'] as $p) {
            $this->assertSame(401, $c->get($p)['status'], $p);
        }
        foreach ($this->mutating() as [$m, $p]) $this->assertSame(401, $c->json($m, $p, [])['status'], "$m $p");
        $r = $c->get('/relatorio.php');
        $this->assertSame(302, $r['status']);
    }
    public function testIdorEntries(): void
    {
        $a = $this->user('ida'); $b = $this->user('idb');
        $id = $this->entry($a, ['note' => 'SEGREDO-A', 'mood' => -1])['id'];
        $list = $b->get('/api/entries.php?from=2026-01-01&to=2026-12-31')['json']['entries'];
        $this->assertSame([], $list);
        $this->assertSame(200, $b->json('DELETE', '/api/entries.php?id=' . $id, null)['status']);
        $this->assertSame(1, (int)db()->query("SELECT COUNT(*) FROM entries WHERE id = $id")->fetchColumn(), 'B não apaga entrada de A');
        // mesma data/período de B cria entrada própria, não sobrescreve a de A
        $this->entry($b, ['mood' => 3, 'note' => 'B']);
        $row = db()->query("SELECT mood, note FROM entries WHERE id = $id")->fetch();
        $this->assertSame(['mood' => -1, 'note' => 'SEGREDO-A'], ['mood' => (int)$row['mood'], 'note' => $row['note']]);
        $this->assertStringNotContainsString('SEGREDO-A', $b->get('/relatorio.php?de=2026-01-01&ate=2026-12-31&notas=1')['body']);
    }
    public function testIdorMedia(): void
    {
        $a = $this->user('ma'); $b = $this->user('mb');
        $eid = $this->entry($a)['id'];
        $r = $a->upload('/api/media.php', $this->png(), 'foto.png', 'image/png', ['entry_id' => (string)$eid]);
        $this->assertSame(200, $r['status'], $r['body']);
        $mid = $r['json']['id'];
        $this->assertSame(200, $a->get('/api/media.php?id=' . $mid)['status']);
        $this->assertSame(404, $b->get('/api/media.php?id=' . $mid)['status']);
        $this->assertSame([], $b->get('/api/media.php')['json']['media']);
        $b->json('DELETE', '/api/media.php?id=' . $mid, null);
        $this->assertSame(200, $a->get('/api/media.php?id=' . $mid)['status'], 'arquivo de A continua');
        // B não consegue anexar mídia na entrada de A
        $r = $b->upload('/api/media.php', $this->png(), 'b.png', 'image/png', ['entry_id' => (string)$eid]);
        $this->assertNull(db()->query('SELECT entry_id FROM media WHERE id = ' . (int)$r['json']['id'])->fetchColumn());
    }
    public function testIdorRecipientsAndReminders(): void
    {
        $a = $this->user('ra'); $b = $this->user('rb');
        $a->json('POST', '/api/recipients.php', ['name' => 'Dr', 'email' => 'dr@example.com', 'role' => 'psiquiatra', 'frequency' => 'semanal', 'weekday' => 1]);
        $a->json('POST', '/api/reminders.php', ['time_local' => '08:00', 'days' => [1]]);
        $rid = (int)db()->query('SELECT id FROM recipients WHERE user_id = ' . $this->uid('ra'))->fetchColumn();
        $mid = (int)db()->query('SELECT id FROM reminders WHERE user_id = ' . $this->uid('ra'))->fetchColumn();
        $this->assertSame([], $b->get('/api/recipients.php')['json']['recipients']);
        $this->assertSame([], $b->get('/api/reminders.php')['json']['reminders']);
        $b->json('DELETE', "/api/recipients.php?id=$rid", null);
        $b->json('DELETE', "/api/reminders.php?id=$mid", null);
        $this->assertSame(1, (int)db()->query("SELECT COUNT(*) FROM recipients WHERE id = $rid")->fetchColumn());
        $this->assertSame(1, (int)db()->query("SELECT COUNT(*) FROM reminders WHERE id = $mid")->fetchColumn());
        // push: a assinatura fica vinculada a quem a registrou
        $a->json('POST', '/api/push.php', ['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => b64url("\x04" . str_repeat('k', 64)), 'auth' => b64url(str_repeat('a', 16))]]);
        $this->assertSame($this->uid('ra'), (int)db()->query("SELECT user_id FROM push_subscriptions WHERE endpoint_hash = '" . hash('sha256', 'https://push.example/abc') . "'")->fetchColumn());
        $this->assertSame(422, $a->json('POST', '/api/push.php', ['endpoint' => 'http://insecure.example/x'])['status']);
        $this->assertSame(422, $a->json('POST', '/api/push.php', ['endpoint' => 'javascript:alert(1)'])['status']);
    }
    public function testExportOnlyOwnData(): void
    {
        $a = $this->user('ea'); $b = $this->user('eb');
        $this->entry($a, ['note' => 'NOTA-DE-A']);
        $this->entry($b, ['note' => 'NOTA-DE-B']);
        $json = $b->get('/api/account.php?action=export&format=json');
        $csv = $b->get('/api/account.php?action=export&format=csv');
        $this->assertStringContainsString('NOTA-DE-B', $json['body']);
        $this->assertStringNotContainsString('NOTA-DE-A', $json['body']);
        $this->assertStringNotContainsString('NOTA-DE-A', $csv['body']);
        $this->assertStringContainsString('attachment', (string)$b->header($csv, 'Content-Disposition'));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv['body']);
        $this->assertArrayNotHasKey('id', $json['json']['usuario']);
    }
}
