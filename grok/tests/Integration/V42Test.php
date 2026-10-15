<?php
/** V4.2: alertas personalizados (API, bloqueio privado, disparo ao salvar), relatório × Histórico, convites, crons e café. */
final class V42Test extends IntegrationCase
{
    private function today(int $offset = 0): string
    {
        return (new DateTime('now', new DateTimeZone('America/Cuiaba')))->modify("$offset day")->format('Y-m-d');
    }

    public function testCrudPreviewToggleDelete(): void
    {
        $c = $this->user('v42crud');
        $g = $c->get('/api/custom_alerts.php')['json'];
        $this->assertSame([], $g['rules']);
        $keys = array_column($g['metrics'], 'key');
        $this->assertContains('sleep', $keys);
        $this->assertNotContains('libido', $keys, 'módulo Sexualidade desligado: métrica oculta');
        $this->assertNotContains('humor_alto_desejo', array_column($g['templates'], 'key'));
        $bad = $c->json('POST', '/api/custom_alerts.php?action=save', ['name' => '', 'conditions' => []]);
        $this->assertSame(422, $bad['status']);
        $this->assertSame('Dê um nome para o alerta.', $bad['json']['erro']);
        for ($i = 3; $i >= 1; $i--) $this->entry($c, ['entry_date' => $this->today(-$i), 'period' => 'manha', 'mood' => -2]);
        $rule = ['name' => 'Humor baixo', 'logic' => 'and', 'conditions' => [['metric' => 'mood_min', 'op' => '<=', 'value' => -2, 'mode' => 'streak', 'days' => 2]], 'cooldown_days' => 3];
        $p = $c->json('POST', '/api/custom_alerts.php?action=preview', $rule + ['dias' => 30]);
        $this->assertSame(200, $p['status'], $p['body']);
        $this->assertSame([$this->today(-2), $this->today(-1)], $p['json']['matched']);
        $this->assertSame([$this->today(-2)], $p['json']['fired']);
        $this->assertSame(30, $p['json']['dias']);
        $s = $c->json('POST', '/api/custom_alerts.php?action=save', $rule);
        $this->assertSame(200, $s['status'], $s['body']);
        $id = (int)$s['json']['rule']['id'];
        $this->assertStringContainsString('por 2 dias seguidos', $s['json']['rule']['description']);
        $this->assertSame(1, $s['json']['disparou'], 'ontem atende; avisa no app sem enviar nada');
        $a = array_values(array_filter($c->get('/api/alerts.php')['json']['alerts'], fn($x) => $x['custom']));
        $this->assertCount(1, $a);
        $this->assertSame('Alerta personalizado: Humor baixo', $a[0]['title']);
        $this->assertSame('0', (string)db()->query("SELECT pending_delivery FROM alerts WHERE id = " . (int)$a[0]['id'])->fetchColumn(), 'sem push nem e-mail: nada a entregar');
        $e = $c->json('POST', '/api/custom_alerts.php?action=save', ['id' => $id] + ['name' => 'Humor baixo 2'] + $rule);
        $this->assertSame('Humor baixo 2', $e['json']['rule']['name']);
        $this->assertSame(['ok' => true, 'active' => false], $c->json('POST', '/api/custom_alerts.php?action=toggle', ['id' => $id, 'active' => false])['json']);
        $this->assertSame(0, (int)$c->get('/api/custom_alerts.php')['json']['rules'][0]['active']);
        $o = $this->user('v42crud_o');
        $this->assertSame(404, $o->json('POST', '/api/custom_alerts.php?action=delete', ['id' => $id])['status'], 'regra de outra pessoa');
        $this->assertSame(200, $c->json('POST', '/api/custom_alerts.php?action=delete', ['id' => $id])['status']);
        $this->assertSame([], $c->get('/api/custom_alerts.php')['json']['rules']);
        $this->assertCount(1, array_filter($c->get('/api/alerts.php')['json']['alerts'], fn($x) => $x['custom']), 'o aviso já mostrado continua no Histórico');
    }

    public function testPrivateRuleLockedAndFiresAfterSave(): void
    {
        $c = $this->user('v42priv');
        $c->json('POST', '/api/account.php?action=prefs', ['modules' => ['substancias' => true]]);
        $rule = ['name' => 'Muito café', 'conditions' => [['metric' => 'sub_cafe', 'op' => '>=', 'value' => 4]], 'notify_push' => true];
        $s = $c->json('POST', '/api/custom_alerts.php?action=save', $rule);
        $this->assertSame(200, $s['status'], $s['body']);
        $id = (int)$s['json']['rule']['id'];
        $this->assertTrue($s['json']['rule']['private']);
        $r = $c->json('POST', '/api/day.php?action=subst_add', ['entry_date' => $this->today(), 'substance' => 'cafe', 'amount' => 4, 'period' => 'manha']);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame(1, $r['json']['alertas_personalizados'], 'avalia logo depois de salvar');
        $m = $c->get('/api/day.php?from=' . $this->today() . '&to=' . $this->today())['json']['markers'][0];
        $this->assertSame(4, (int)$m['caffeine_cups'], 'café em Substâncias vai para os marcadores');
        $c->json('POST', '/api/account.php?action=sexpin', ['pin' => '2468']);
        $c->json('POST', '/api/day.php?action=lock', []);
        $g = $c->get('/api/custom_alerts.php')['json'];
        $this->assertTrue($g['locked']);
        $this->assertSame('Alerta privado', $g['rules'][0]['name']);
        $this->assertArrayNotHasKey('conditions', $g['rules'][0]);
        $al = array_values(array_filter($c->get('/api/alerts.php')['json']['alerts'], fn($x) => $x['custom']))[0];
        $this->assertTrue($al['locked']);
        $this->assertSame('Alerta personalizado privado', $al['title']);
        $this->assertSame([], $al['evidence']);
        foreach (['toggle' => ['id' => $id, 'active' => false], 'delete' => ['id' => $id], 'save' => $rule, 'preview' => $rule] as $act => $body) {
            $x = $c->json('POST', '/api/custom_alerts.php?action=' . $act, $body);
            $this->assertSame(423, $x['status'], $act);
            $this->assertTrue($x['json']['bloqueado']);
        }
        $this->assertSame(200, $c->json('POST', '/api/day.php?action=unlock', ['pin' => '2468'])['status']);
        $this->assertContains('Alerta personalizado: Muito café', array_column($c->get('/api/alerts.php')['json']['alerts'], 'title'));
        // o cron entrega o pendente só com texto genérico
        TestEnv::runCron('analyze_signals.php');
        $this->assertSame('0', (string)db()->query("SELECT COUNT(*) FROM alerts WHERE pending_delivery = 1 AND user_id = " . $this->uid('v42priv'))->fetchColumn());
        $hb = json_decode((string)file_get_contents(TestEnv::$tmp . '/storage/heartbeat-analise.json'), true);
        $this->assertGreaterThanOrEqual(1, $hb['pendentes_entregues']);
    }

    public function testEntrySaveReturnsEntryAndQuickSaves(): void
    {
        $c = $this->user('v42quick');
        $d = $this->today();
        $r1 = $this->entry($c, ['entry_date' => $d, 'period' => 'manha', 'mood' => 1]);
        $r2 = $this->entry($c, ['entry_date' => $d, 'period' => 'tarde', 'mood' => 2]);
        $this->assertSame('manha', $r1['entry']['period']);
        $this->assertSame('tarde', $r2['entry']['period']);
        $this->assertGreaterThan(0, (int)$r2['id']);
        $this->assertSame(0, $r2['alertas_personalizados']);
    }

    public function testReportMatchesHistoryAndListsAlerts(): void
    {
        $c = $this->user('v42rep');
        $d = $this->today(-1);
        $this->entry($c, ['entry_date' => $d, 'period' => 'manha', 'mood' => 2]);
        $r = $c->json('POST', '/api/day.php', ['entry_date' => $d, 'bed_time' => '23:00', 'wake_time' => '06:30', 'racing_thoughts' => 1, 'impulsive_spending' => 1]);
        $this->assertSame(200, $r['status'], $r['body']);
        $c->json('POST', '/api/custom_alerts.php?action=save', ['name' => 'Humor alto', 'conditions' => [['metric' => 'mood_max', 'op' => '>=', 'value' => 2]]]);
        $h = $c->get('/relatorio.php?de=' . $this->today(-6) . '&ate=' . $this->today())['body'];
        $this->assertStringContainsString('7,5 h', $h, 'coluna Sono preenchida');
        $this->assertMatchesRegularExpression('/pensamentos \/ fala acelerados.*?<strong>1 \/ 0<\/strong>/s', $h);
        $this->assertMatchesRegularExpression('/gastos impulsivos · irritabilidade.*?<strong>1 · 0<\/strong>/s', $h);
        $this->assertStringContainsString('Alertas do app no período', $h);
        $this->assertStringContainsString('Alerta personalizado: Humor alto', $h);
        $this->assertStringNotContainsString('America/Cuiaba', $h, 'fuso traduzido');
    }

    public function testInviteStatusResendAndCustomOptIn(): void
    {
        TestEnv::clearMails();
        $c = $this->user('v42inv');
        $r = $c->json('POST', '/api/recipients.php', ['name' => 'Dra Bia', 'email' => 'bia@example.com', 'role' => 'psiquiatra', 'frequency' => 'semanal']);
        $this->assertContains($r['status'], [200, 201], $r['body']);
        $this->assertSame('enviado', $r['json']['invite_status']);
        $row = $c->get('/api/recipients.php')['json']['recipients'][0];
        $this->assertSame('enviado', $row['invite_status']);
        $this->assertSame(0, (int)$row['include_custom_alerts'], 'alertas personalizados para apoio nunca vêm ligados');
        $id = (int)$r['json']['id'];
        db()->prepare("UPDATE recipients SET invite_status = 'recusado', invite_error_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$id]);
        $this->assertSame('recusado', $c->get('/api/recipients.php')['json']['recipients'][0]['invite_status']);
        $x = $c->json('POST', '/api/recipients.php?action=resend&id=' . $id, []);
        $this->assertSame(200, $x['status'], $x['body']);
        $this->assertSame('enviado', $x['json']['invite_status']);
        $this->assertCount(2, TestEnv::mails());
        $o = $this->user('v42inv_o');
        $this->assertSame(404, $o->json('POST', '/api/recipients.php?action=resend&id=' . $id, [])['status']);
    }

    public function testCronStatusIsServerSideOnly(): void
    {
        TestEnv::runCron('analyze_signals.php');
        $c = $this->user('v42cron');
        // V4.3: sem API nem tela de crons (projeto aberto, sem área de administração)
        $this->assertSame(400, $c->get('/api/account.php?action=cron')['status']);
        $h = $c->get('/')['body'];
        $this->assertStringNotContainsString('id="conta-cron"', $h);
        $this->assertStringNotContainsString('Tarefas automáticas', $h);
        foreach (['id="alertas-personalizados"', 'name="include_custom_alerts"'] as $n) $this->assertStringContainsString($n, $h);
        // o batimento continua, para o operador conferir no servidor
        require_once TestEnv::ROOT . '/app/lib/cron_status.php';
        $j = cron_status_all('America/Cuiaba');
        $this->assertSame(['analise', 'lembretes', 'relatorios'], array_column($j, 'job'));
        $this->assertSame('ok', $j[0]['status']);
    }
}
