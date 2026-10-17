<?php
/** V2: marcadores diários, Sexualidade privada (PIN, opt-in por destinatário), indicador e relatórios. */
final class V2MarkersTest extends IntegrationCase
{
    public function testDayMarkersSaveAndPartialUpdate(): void
    {
        $c = $this->user('v2day');
        $r = $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-10', 'bed_time' => '23:00', 'wake_time' => '07:00', 'sun_minutes' => 30, 'sun_period' => 'manha', 'caffeine_cups' => 2]);
        $this->assertSame(200, $r['status'], $r['body']);
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-10', 'racing_thoughts' => 2]);
        $g = $c->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json'];
        $m = $g['markers'][0];
        $this->assertSame('23:00', $m['bed_time'], 'campos não enviados preservados');
        $this->assertSame(30, (int)$m['sun_minutes']);
        $this->assertSame(2, (int)$m['racing_thoughts']);
        $this->assertSame(8.0, (float)$g['indicators']['2026-10-10']['sleep']);
        $this->assertFalse($g['sex']['enabled']);
        $this->assertSame(422, $c->json('POST', '/api/day.php', ['entry_date' => 'ontem'])['status']);
        $this->assertSame(422, $c->get('/api/day.php?from=2026-10-10&to=2026-10-01')['status']);
    }

    public function testMarkersAreIsolatedPerUser(): void
    {
        $a = $this->user('v2iso_a'); $b = $this->user('v2iso_b');
        $a->json('POST', '/api/day.php', ['entry_date' => '2026-10-10', 'substances' => 'segredo-a']);
        $this->assertSame([], $b->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json']['markers']);
        $this->assertSame(401, $this->dev()->get('/api/day.php?from=2026-10-10&to=2026-10-10')['status']);
    }

    public function testSexualityRequiresModuleAndPin(): void
    {
        $c = $this->user('v2sex');
        $payload = ['entry_date' => '2026-10-10', 'libido' => 2, 'activities' => ['nova_parceria', 'sem_protecao'], 'frequency' => 3, 'different_from_usual' => 1, 'note' => 'nota íntima'];
        $this->assertSame(403, $c->json('POST', '/api/day.php?action=sex', $payload)['status'], 'desligado por padrão');
        $html = $c->get('/')['body'];
        $this->assertMatchesRegularExpression('/id="form-sex" class="card sex-card hidden/', $html);
        $this->assertSame(200, $c->json('POST', '/api/account.php?action=prefs', ['modules' => ['sexualidade' => true, 'sono' => true], 'sex_blur' => true])['status']);
        $this->assertSame(200, $c->json('POST', '/api/day.php?action=sex', $payload)['status']);
        $g = $c->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json'];
        $this->assertSame('nota íntima', $g['sexuality'][0]['note']);
        // PIN
        $this->assertSame(422, $c->json('POST', '/api/account.php?action=sexpin', ['pin' => '12ab'])['status']);
        $this->assertSame(200, $c->json('POST', '/api/account.php?action=sexpin', ['pin' => '4321'])['status']);
        $g = $c->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json'];
        $this->assertTrue($g['sex']['locked']);
        $this->assertSame([], $g['sexuality'], 'bloqueado: nada é devolvido');
        $this->assertSame(423, $c->json('POST', '/api/day.php?action=sex', $payload)['status']);
        $this->assertSame(422, $c->json('POST', '/api/day.php?action=unlock', ['pin' => '0000'])['status']);
        $this->assertSame(200, $c->json('POST', '/api/day.php?action=unlock', ['pin' => '4321'])['status']);
        $this->assertCount(1, $c->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json']['sexuality']);
        // trocar PIN exige o atual
        $this->assertSame(422, $c->json('POST', '/api/account.php?action=sexpin', ['atual' => '9999', 'pin' => ''])['status']);
        db()->exec('DELETE FROM rate_limits');
        $this->assertSame(200, $c->json('POST', '/api/account.php?action=sexpin', ['atual' => '4321', 'pin' => ''])['status']);
        // o PIN não vaza na exportação; os dados sim (são da pessoa)
        $exp = $c->get('/api/account.php?action=export&format=json')['json'];
        $this->assertArrayNotHasKey('sex_pin_hash', $exp['usuario']);
        $this->assertSame('nota íntima', $exp['sexualidade'][0]['note']);
        // apagar tudo
        $this->assertSame(200, $c->json('POST', '/api/day.php?action=sex_delete', ['all' => true])['status']);
        $this->assertSame(0, (int)db()->query('SELECT COUNT(*) FROM sexuality_log WHERE user_id = ' . $this->uid('v2sex'))->fetchColumn());
    }

    public function testUnlockIsRateLimited(): void
    {
        $c = $this->user('v2rl');
        $c->json('POST', '/api/account.php?action=prefs', ['modules' => ['sexualidade' => true]]);
        $c->json('POST', '/api/account.php?action=sexpin', ['pin' => '1234']);
        db()->exec('DELETE FROM rate_limits');
        $codes = [];
        for ($i = 0; $i < 6; $i++) $codes[] = $c->json('POST', '/api/day.php?action=unlock', ['pin' => '0000'])['status'];
        $this->assertSame(429, end($codes));
    }

    public function testEarlyWarningAndActionPlan(): void
    {
        $c = $this->user('v2warn');
        $c->json('POST', '/api/account.php?action=prefs', ['modules' => ['sexualidade' => true, 'sono' => true, 'mente' => true], 'action_plan' => 'Ligar para a Dra. Ana']);
        for ($i = 10; $i >= 3; $i--) $c->json('POST', '/api/day.php', ['entry_date' => sprintf('2026-10-%02d', $i), 'bed_time' => '23:00', 'wake_time' => '07:00']);
        foreach (['2026-10-11', '2026-10-12'] as $d) {
            $c->json('POST', '/api/day.php', ['entry_date' => $d, 'bed_time' => '03:00', 'wake_time' => '06:00', 'racing_thoughts' => 3]);
            $this->entry($c, ['entry_date' => $d, 'period' => 'tarde', 'mood' => 2, 'energy' => 3]);
            $c->json('POST', '/api/day.php?action=sex', ['entry_date' => $d, 'libido' => 2, 'activities' => ['pensamentos']]);
        }
        $g = $c->get('/api/day.php?from=2026-10-01&to=2026-10-12')['json'];
        $this->assertTrue($g['indicators']['2026-10-12']['triad']);
        $this->assertSame('alerta', $g['indicators']['2026-10-12']['level']);
        $this->assertNotNull($g['warning']);
        $this->assertSame('Ligar para a Dra. Ana', $g['action_plan']);
        $this->assertSame('ok', $g['indicators']['2026-10-05']['level']);
    }

    public function testReportsIncludeSexualityOnlyWithPerRecipientOptIn(): void
    {
        $c = $this->user('v2rep');
        $uid = $this->uid('v2rep');
        $c->json('POST', '/api/account.php?action=prefs', ['modules' => ['sexualidade' => true, 'sono' => true]]);
        $today = (new DateTime('yesterday', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        $this->entry($c, ['entry_date' => $today, 'period' => 'manha', 'mood' => 1]);
        $c->json('POST', '/api/day.php', ['entry_date' => $today, 'bed_time' => '23:00', 'wake_time' => '07:00', 'sun_minutes' => 45]);
        $c->json('POST', '/api/day.php?action=sex', ['entry_date' => $today, 'libido' => 2, 'activities' => ['sem_protecao'], 'note' => 'NOTA-SECRETA']);
        foreach (['Sem Opt' => false, 'Com Opt' => true] as $name => $opt) {
            $r = $c->json('POST', '/api/recipients.php', ['name' => $name, 'email' => strtolower(str_replace(' ', '', $name)) . '@example.com', 'role' => 'psiquiatra', 'frequency' => 'diaria', 'include_sexuality' => $opt]);
            $this->assertSame(200, $r['status'], $r['body']);
        }
        // string "1" não conta como opt-in (precisa ser true explícito)
        $c->json('POST', '/api/recipients.php', ['name' => 'Str', 'email' => 'str@example.com', 'frequency' => 'diaria', 'include_sexuality' => '1']);
        $this->assertSame(['0', '1', '0'], array_map('strval', db()->query("SELECT include_sexuality FROM recipients WHERE user_id = $uid ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
        db()->exec("UPDATE recipients SET confirmed_at = UTC_TIMESTAMP(), next_send_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE user_id = $uid AND name <> 'Str'");
        TestEnv::clearMails();
        [$code, $out] = TestEnv::runCron('send_reports.php');
        $this->assertSame(0, $code, $out);
        $byTo = [];
        foreach (TestEnv::mails() as $raw) if (preg_match('/^To: .*?([\w.]+@example\.com)/mi', $raw, $m)) $byTo[$m[1]] = TestEnv::htmlPart($raw);
        $this->assertArrayHasKey('semopt@example.com', $byTo);
        $this->assertArrayHasKey('comopt@example.com', $byTo);
        foreach ($byTo as $html) {
            $this->assertStringContainsString('Marcadores diários', $html);
            $this->assertStringContainsString('45,0 min', $html);
            $this->assertStringNotContainsString('NOTA-SECRETA', $html);
            $this->assertStringNotContainsString('sem proteção', $html);
        }
        $this->assertStringNotContainsString('Sexualidade', $byTo['semopt@example.com']);
        $this->assertStringContainsString('Sexualidade — desejo médio', $byTo['comopt@example.com']);
        // PATCH desliga
        $id = (int)db()->query("SELECT id FROM recipients WHERE user_id = $uid AND name = 'Com Opt'")->fetchColumn();
        $this->assertSame(200, $c->json('PATCH', "/api/recipients.php?id=$id", ['include_sexuality' => false])['status']);
        $this->assertSame('0', (string)db()->query("SELECT include_sexuality FROM recipients WHERE id = $id")->fetchColumn());
    }

    public function testRemindersNeverMentionSexuality(): void
    {
        $src = (string)file_get_contents(TestEnv::ROOT . '/cron/send_reminders.php') . (string)file_get_contents(TestEnv::ROOT . '/public_html/sw.js');
        $this->assertDoesNotMatchRegularExpression('/sexual|libido|sex_/i', $src);
    }

    public function testPrintableReportAndUi(): void
    {
        $c = $this->user('v2ui');
        $html = $c->get('/')['body'];
        foreach (['id="form-day"', 'id="early-warning"', 'id="conta-modulos"', 'id="indicators"', 'name="include_sexuality"', 'app.js?v=12', 'app.css?v=12'] as $n) $this->assertStringContainsString($n, $html);
        $this->assertStringNotContainsString('name="sexualidade" value="1"', $html, 'opção de relatório só com o módulo ligado');
        $c->json('POST', '/api/account.php?action=prefs', ['modules' => ['ciclo' => true]]);
        $html = $c->get('/')['body'];
        $this->assertMatchesRegularExpression('/data-mod="sono" hidden/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-mod="ciclo" hidden/', $html);
        $this->assertSame(200, $c->get('/relatorio.php?de=2026-10-01&ate=2026-10-12&sexualidade=1')['status']);
        $this->assertStringContainsString('id="sexualidade"', $this->dev()->get('/privacidade.php')['body']);
    }
}
