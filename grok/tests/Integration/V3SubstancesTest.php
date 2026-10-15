<?php
/** V3: módulo Substâncias — privado, PIN compartilhado, álcool consistente, favoritos, relatórios com opt-in. */
final class V3SubstancesTest extends IntegrationCase
{
    private function on(Http $c, array $extra = []): void
    {
        $r = $c->json('POST', '/api/account.php?action=prefs', ['modules' => ['substancias' => true, 'habitos' => true] + $extra]);
        $this->assertSame(200, $r['status'], $r['body']);
    }

    public function testOffByDefaultAndCrud(): void
    {
        $c = $this->user('v3crud');
        $p = ['entry_date' => '2026-10-10', 'substance' => 'cannabis', 'form' => 'vaporizada', 'amount' => 1, 'period' => 'noite', 'reason' => 'dormir', 'mood_effect' => 1, 'above_usual' => 0];
        $this->assertSame(403, $c->json('POST', '/api/day.php?action=subst_add', $p)['status']);
        $this->assertMatchesRegularExpression('/id="form-subst" class="card sex-card subst-card hidden/', $c->get('/')['body']);
        $this->on($c);
        $this->assertSame(200, $c->json('POST', '/api/day.php?action=subst_add', $p)['status']);
        $this->assertSame(200, $c->json('POST', '/api/day.php?action=subst_add', ['entry_date' => '2026-10-10', 'substance' => 'tabaco', 'form' => 'vape_pod', 'amount' => 10])['status']);
        $this->assertSame(422, $c->json('POST', '/api/day.php?action=subst_add', ['entry_date' => '2026-10-10', 'substance' => 'outra'])['status']);
        $g = $c->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json'];
        $this->assertCount(2, $g['substances'], 'vários registros por dia');
        $this->assertSame('vaporizada', $g['substances'][0]['form']);
        // outro usuário não remove
        $id = (int)$g['substances'][0]['id'];
        $o = $this->user('v3crud_b'); $this->on($o);
        $this->assertSame(404, $o->json('POST', '/api/day.php?action=subst_delete', ['id' => $id])['status']);
        $this->assertSame([], $o->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json']['substances']);
        $this->assertSame(200, $c->json('POST', '/api/day.php?action=subst_delete', ['id' => $id])['status']);
        $this->assertCount(1, $c->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json']['substances']);
        $exp = $c->get('/api/account.php?action=export&format=json')['json'];
        $this->assertSame('tabaco', $exp['substancias'][0]['substance']);
        $this->assertSame(422, $c->json('POST', '/api/day.php?action=subst_wipe', [])['status']);
        $this->assertSame(200, $c->json('POST', '/api/day.php?action=subst_wipe', ['confirm' => true])['status']);
        $this->assertSame([], $c->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json']['substances']);
    }

    public function testAlcoholStaysConsistent(): void
    {
        $c = $this->user('v3alc');
        $this->on($c);
        $c->json('POST', '/api/day.php?action=subst_add', ['entry_date' => '2026-10-10', 'substance' => 'alcool', 'amount' => 2]);
        $c->json('POST', '/api/day.php?action=subst_add', ['entry_date' => '2026-10-10', 'substance' => 'alcool', 'amount' => 1.5]);
        $g = $c->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json'];
        $this->assertSame(4, (int)$g['markers'][0]['alcohol_units'], 'soma (arredondada) dos registros de Álcool');
        $id = (int)$g['substances'][0]['id'];
        $c->json('POST', '/api/day.php?action=subst_delete', ['id' => $id]);
        $this->assertSame(2, (int)$c->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json']['markers'][0]['alcohol_units']);
        $this->assertStringContainsString('id="d-alc" name="alcohol_units" min="0" max="50" inputmode="numeric" readonly', $c->get('/')['body']);
    }

    public function testSharedPinAndFavorites(): void
    {
        $c = $this->user('v3pin');
        $this->on($c);
        $r = $c->json('POST', '/api/day.php?action=subst_fav', ['favorites' => [['substance' => 'cannabis', 'form' => 'fumada', 'amount' => 1]]]);
        $this->assertSame(200, $r['status']);
        // salvar módulos em Conta não apaga favoritos
        $this->on($c);
        $this->assertSame('cannabis', $c->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json']['subst']['favorites'][0]['substance']);
        $c->json('POST', '/api/account.php?action=sexpin', ['pin' => '1357']);
        $g = $c->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json'];
        $this->assertTrue($g['subst']['locked'], 'o mesmo PIN protege Substâncias');
        $this->assertSame(423, $c->json('POST', '/api/day.php?action=subst_add', ['entry_date' => '2026-10-10', 'substance' => 'cocaina'])['status']);
        $this->assertSame(200, $c->json('POST', '/api/day.php?action=unlock', ['pin' => '1357'])['status']);
        $this->assertSame(200, $c->json('POST', '/api/day.php?action=subst_add', ['entry_date' => '2026-10-10', 'substance' => 'cocaina'])['status']);
        $this->assertContains('uso de estimulante ou psicodélico', $c->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json']['indicators']['2026-10-10']['reasons']);
    }

    public function testHistorySummaryAndReportsOptIn(): void
    {
        $c = $this->user('v3rep');
        $uid = $this->uid('v3rep');
        $this->on($c);
        $y = (new DateTime('yesterday', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        $this->entry($c, ['entry_date' => $y, 'period' => 'noite', 'mood' => 1]);
        $c->json('POST', '/api/day.php?action=subst_add', ['entry_date' => $y, 'substance' => 'cannabis', 'amount' => 3, 'reason' => 'ansiedade', 'above_usual' => 1, 'period' => 'noite']);
        $sum = $c->get("/api/day.php?resumo=1&from=$y&to=$y")['json']['subst_summary'];
        $this->assertSame(1.0, (float)$sum[0]['mood_with']);
        $this->assertStringContainsString('id="subst-hist"', $c->get('/?aba=historico')['body']);
        foreach (['Sem Sub' => false, 'Com Sub' => true] as $name => $opt) {
            $c->json('POST', '/api/recipients.php', ['name' => $name, 'email' => strtolower(str_replace(' ', '', $name)) . '@example.com', 'frequency' => 'diaria', 'include_substances' => $opt]);
        }
        db()->exec("UPDATE recipients SET confirmed_at = UTC_TIMESTAMP(), next_send_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE user_id = $uid");
        TestEnv::clearMails();
        [$code, $out] = TestEnv::runCron('send_reports.php');
        $this->assertSame(0, $code, $out);
        $by = [];
        foreach (TestEnv::mails() as $raw) if (preg_match('/^To: .*?([\w.]+@example\.com)/mi', $raw, $m)) $by[$m[1]] = TestEnv::htmlPart($raw);
        $this->assertStringNotContainsString('Substâncias', $by['semsub@example.com']);
        $this->assertStringContainsString('Substâncias — dias por tipo', $by['comsub@example.com']);
        $this->assertStringContainsString('Cannabis/maconha: 1', $by['comsub@example.com']);
        foreach (['ansiedade', '3,0', 'noite'] as $leak) $this->assertStringNotContainsString($leak, substr($by['comsub@example.com'], (int)strpos($by['comsub@example.com'], 'Substâncias')), "resumo não traz $leak");
        $src = (string)file_get_contents(TestEnv::ROOT . '/cron/send_reminders.php') . (string)file_get_contents(TestEnv::ROOT . '/public_html/sw.js');
        $this->assertDoesNotMatchRegularExpression('/subst|cannabis|maconha/i', $src);
        $this->assertSame(200, $c->get("/relatorio.php?substancias=1&de=$y&ate=$y")['status']);
    }
}
