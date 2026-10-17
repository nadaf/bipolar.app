<?php
require_once TestEnv::ROOT . '/app/lib/custom_alerts.php';
require_once TestEnv::ROOT . '/app/lib/care.php';
/** V4.8 (0.5.0-beta): pensamentos por período, pensamentos de morte (privado) + painel de apoio, nicotina. */
final class V48Test extends IntegrationCase
{
    private function mods(Http $c, array $on): void
    {
        $mods = array_fill_keys(array_keys(DAY_MODULES), true);
        foreach (['sexualidade', 'substancias', 'pensamentos_morte'] as $k) $mods[$k] = false;
        $r = $c->json('POST', '/api/account.php?action=prefs', ['modules' => $on + $mods]);
        $this->assertSame(200, $r['status'], $r['body']);
    }

    public function testThoughtsArePerPeriodAndRacingFeedsTheDay(): void
    {
        $c = $this->user('v48th');
        $r = $c->json('POST', '/api/day.php', ['entry_date' => '2026-11-01', 'period' => 'manha', 'th_positive' => 2, 'th_racing' => 1, 'th_grandiose' => 0]);
        $this->assertSame(200, $r['status'], $r['body']);
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-11-01', 'period' => 'noite', 'th_negative' => 3, 'th_racing' => 3, 'th_rumination' => 2]);
        $d = $c->get('/api/day.php?from=2026-11-01&to=2026-11-01')['json'];
        $pm = array_column($d['period_markers'], null, 'period');
        $this->assertSame(2, (int)$pm['manha']['th_positive']);
        $this->assertNull($pm['manha']['th_negative'], 'nada de um período aparece em outro');
        $this->assertSame(3, (int)$pm['noite']['th_negative']);
        $m = $d['markers'][0];
        $this->assertSame(3, (int)$m['racing_thoughts'], 'acelerados do dia = maior valor dos períodos (compatível com alertas/relatórios)');
        $this->assertSame(2, (int)$m['th_positive']);
        $this->assertSame(3, (int)$m['th_negative']);
        // limpar a noite recalcula o dia
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-11-01', 'period' => 'noite', 'th_negative' => null, 'th_racing' => null, 'th_rumination' => null]);
        $m = $c->get('/api/day.php?from=2026-11-01&to=2026-11-01')['json']['markers'][0];
        $this->assertSame(1, (int)$m['racing_thoughts']);
        $this->assertNull($m['th_negative']);
        // o campo antigo do dia continua aceito (quem desligou "Pensamentos por período")
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-11-02', 'racing_thoughts' => 2]);
        $this->assertSame(2, (int)$c->get('/api/day.php?from=2026-11-02&to=2026-11-02')['json']['markers'][0]['racing_thoughts']);
        // módulo desligado: itens por período enviados juntos (campos ocultos) não zeram o campo do dia
        $this->mods($c, ['pensamentos' => false]);
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-11-03', 'period' => 'tarde', 'racing_thoughts' => 2, 'th_racing' => null, 'th_positive' => 3]);
        $d = $c->get('/api/day.php?from=2026-11-03&to=2026-11-03')['json'];
        $this->assertSame(2, (int)$d['markers'][0]['racing_thoughts']);
        $this->assertNull($d['markers'][0]['th_positive']);
    }

    public function testStrictValidation422(): void
    {
        $c = $this->user('v48val');
        foreach ([['th_positive' => 4], ['th_grandiose' => 'muito'], ['cigarettes' => 101], ['cigarettes' => -1], ['cigarettes' => 'dez'], ['cigarettes' => 2.5], ['nicotine_other' => 'fumo'], ['th_rumination' => -1]] as $bad) {
            $r = $c->json('POST', '/api/day.php', ['entry_date' => '2026-11-03', 'period' => 'tarde'] + $bad);
            $this->assertSame(422, $r['status'], json_encode($bad) . ' ' . $r['body']);
        }
        $ok = $c->json('POST', '/api/day.php', ['entry_date' => '2026-11-03', 'cigarettes' => 100, 'nicotine_other' => 'narguile', 'caffeine_cups' => 3]);
        $this->assertSame(200, $ok['status'], $ok['body']);
        $m = $c->get('/api/day.php?from=2026-11-03&to=2026-11-03')['json']['markers'][0];
        $this->assertSame(100, (int)$m['cigarettes']);
        $this->assertSame('narguile', $m['nicotine_other']);
        $this->assertSame(200, $c->json('POST', '/api/day.php', ['entry_date' => '2026-11-04', 'cigarettes' => 0])['status'], 'zero é valor válido');
    }

    public function testDeathMarkerIsPrivateBehindModuleAndPin(): void
    {
        $c = $this->user('v48death');
        $base = ['entry_date' => '2026-11-05', 'period' => 'noite', 'th_death' => 2];
        $this->assertSame(403, $c->json('POST', '/api/day.php', $base)['status'], 'módulo desligado por padrão');
        $this->assertFalse(user_prefs(['prefs' => null])['modules']['pensamentos_morte']);
        $this->mods($c, ['pensamentos_morte' => true]);
        $this->assertSame(200, $c->json('POST', '/api/account.php?action=sexpin', ['pin' => '2468'])['status']);
        $this->assertSame(423, $c->json('POST', '/api/day.php', $base)['status'], 'sem desbloqueio');
        $this->assertSame(200, $c->json('POST', '/api/day.php?action=unlock', ['pin' => '2468'])['status']);
        $r = $c->json('POST', '/api/day.php', $base);
        $this->assertSame(200, $r['status'], $r['body']);
        $d = $c->get('/api/day.php?from=2026-11-05&to=2026-11-05')['json'];
        $this->assertSame(2, (int)$d['period_markers'][0]['th_death']);
        $this->assertSame(2, (int)$d['markers'][0]['th_death']);
        $this->assertFalse($d['death']['locked']);
        $hist = $c->get('/api/entries.php?from=2026-11-05&to=2026-11-05');
        $this->assertSame(200, $hist['status']);
        // bloqueado: some do GET (dia, períodos e Histórico)
        $c->json('POST', '/api/day.php?action=lock', []);
        $d = $c->get('/api/day.php?from=2026-11-05&to=2026-11-05')['json'];
        $this->assertTrue($d['death']['locked']);
        $this->assertArrayNotHasKey('th_death', $d['period_markers'][0]);
        $this->assertArrayNotHasKey('th_death', $d['markers'][0]);
        $this->entry($c, ['entry_date' => '2026-11-05', 'period' => 'noite', 'mood' => -2]);
        $e = $c->get('/api/entries.php?from=2026-11-05&to=2026-11-05')['json']['entries'][0];
        $this->assertArrayNotHasKey('pm_th_death', $e);
        // nunca aparece para outra conta
        $o = $this->user('v48death2')->get('/api/day.php?from=2026-11-05&to=2026-11-05')['json'];
        $this->assertSame([], $o['markers']);
        // módulo desligado: volta a esconder mesmo sem PIN
        $c->json('POST', '/api/account.php?action=sexpin', ['atual' => '2468', 'pin' => '']);
        $this->mods($c, []);
        $d = $c->get('/api/day.php?from=2026-11-05&to=2026-11-05')['json'];
        $this->assertArrayNotHasKey('th_death', $d['markers'][0]);
    }

    public function testSupportPanelIsStaticAccessibleAndNeverNotifies(): void
    {
        $c = $this->user('v48panel');
        $this->mods($c, ['pensamentos_morte' => true]);
        $c->json('POST', '/api/account.php?action=prefs', ['modules' => array_fill_keys(array_keys(DAY_MODULES), true), 'trusted_contact' => ['name' => 'Mãe', 'phone' => '(65) 99999-1234']]);
        $html = $c->get('/')['body'];
        $this->assertStringContainsString('id="support-panel"', $html);
        $this->assertMatchesRegularExpression('/<dialog id="support-panel"[^>]*role="alertdialog"[^>]*aria-modal="true"[^>]*aria-labelledby="support-title"/', $html);
        $this->assertStringContainsString('href="tel:188"', $html);
        $this->assertStringContainsString('cvv.org.br', $html);
        $this->assertStringContainsString('href="tel:192"', $html);
        $this->assertStringContainsString('Se estiver em perigo agora, ligue 192 ou vá ao pronto-socorro.', $html);
        $this->assertStringContainsString('href="tel:65999991234"', $html);
        $this->assertStringContainsString('Ligar para Mãe', $html);
        $this->assertStringContainsString('id="form-death"', $html);
        $this->assertStringContainsString('id="death-reveal"', $html);
        // salvar não dispara e-mail nem push para ninguém
        TestEnv::clearMails();
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-11-06', 'period' => 'manha', 'th_death' => 3]);
        $this->assertSame([], TestEnv::mails(), 'ninguém é avisado automaticamente');
        $this->assertSame('0', (string)db()->query('SELECT COUNT(*) FROM alerts WHERE user_id = ' . $this->uid('v48panel'))->fetchColumn());
        // o JS abre o painel antes da rede e devolve o foco
        $js = (string)file_get_contents(dirname(__DIR__, 2) . '/public_html/assets/js/app.js');
        $this->assertStringContainsString('if (v !== null && v >= 1) openSupport(); // antes da rede', $js);
        $this->assertStringContainsString("\$('#support-title').focus();", $js);
        // telefone inválido não é guardado
        $this->assertSame(['name' => '', 'phone' => ''], normalize_trusted_contact(['name' => 'X', 'phone' => '12']));
    }

    public function testAlertTemplatesAndMetrics(): void
    {
        $t = custom_templates();
        foreach (['hipomania', 'cigarros_acima', 'pensamentos_morte'] as $k) $this->assertArrayHasKey($k, $t);
        $this->assertSame(CUSTOM_PRIV_DEATH, custom_private_mask($t['pensamentos_morte']['conditions']));
        [$err] = custom_rule_validate($t['pensamentos_morte'] + ['template' => 'pensamentos_morte'], ['pensamentos_morte' => false]);
        $this->assertNotNull($err, 'exige o módulo');
        $c = $this->user('v48alert');
        $this->assertSame([], $c->get('/api/custom_alerts.php')['json']['rules'], 'modelos não ficam ativos sozinhos');
        $keys = array_column($c->get('/api/custom_alerts.php')['json']['templates'], 'key');
        $this->assertContains('hipomania', $keys);
        $this->assertNotContains('pensamentos_morte', $keys, 'só aparece com o módulo ligado');
        $this->mods($c, ['pensamentos_morte' => true]);
        $this->assertContains('pensamentos_morte', array_column($c->get('/api/custom_alerts.php')['json']['templates'], 'key'));
        // regra de cigarros: > 10 por 3 dias seguidos
        $D = custom_daily([], [['entry_date' => '2026-11-01', 'cigarettes' => 12], ['entry_date' => '2026-11-02', 'cigarettes' => 15], ['entry_date' => '2026-11-03', 'cigarettes' => 11]]);
        $rule = custom_rule_validate($t['cigarros_acima'] + ['template' => 'cigarros_acima'])[1];
        $this->assertTrue(custom_rule_on($D, $rule, '2026-11-03', '2026-11-04')[0]);
        $this->assertFalse(custom_rule_on($D, $rule, '2026-11-02', '2026-11-04')[0]);
        // hipomania: acelerados + grandiosidade + sono baixo
        $D = custom_daily([['entry_date' => '2026-11-01', 'period' => 'manha', 'mood' => 2, 'sleep_hours' => 5]], [['entry_date' => '2026-11-01', 'racing_thoughts' => 2, 'th_grandiose' => 3]]);
        $rule = custom_rule_validate($t['hipomania'] + ['template' => 'hipomania'])[1];
        $this->assertTrue(custom_rule_on($D, $rule, '2026-11-01', '2026-11-02')[0]);
    }

    public function testAiCategoriesOffByDefault(): void
    {
        $this->assertFalse(AI_CATEGORIES['pensamentos'][1]);
        $this->assertFalse(AI_CATEGORIES['pensamentos_morte'][1]);
        $this->assertNotContains('pensamentos_morte', ai_settings(['prefs' => null])['categories']);
    }

    public function testReportsAndExports(): void
    {
        $c = $this->user('v48rep');
        $this->mods($c, ['pensamentos_morte' => true]);
        $this->entry($c, ['entry_date' => '2026-11-08', 'period' => 'tarde', 'mood' => 1]);
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-11-08', 'period' => 'tarde', 'th_grandiose' => 3, 'th_death' => 1, 'cigarettes' => 8, 'nicotine_other' => 'vape_pod']);
        $plain = $c->get('/relatorio.php?de=2026-11-01&ate=2026-11-10')['body'];
        $this->assertStringContainsString('Nicotina — cigarros', $plain);
        $this->assertStringContainsString('grandiosidade', $plain);
        $this->assertStringNotContainsString('Pensamentos de morte —', $plain, 'só com seleção explícita');
        $with = $c->get('/relatorio.php?de=2026-11-01&ate=2026-11-10&pensamentos_morte=1')['body'];
        $this->assertStringContainsString('Pensamentos de morte —', $with);
        $md = markers_report_data($this->uid('v48rep'), '2026-11-01', '2026-11-10', false);
        $this->assertNull($md['death']);
        $this->assertSame(8.0, (float)$md['cigarettes_avg']);
        $this->assertSame(1, $md['nicotine_other_days']);
        $csv = $c->get('/api/account.php?action=export&format=csv')['body'];
        $this->assertStringContainsString('pens_grandiosidade', $csv);
        $this->assertStringContainsString('cigarros_dia', $csv);
        $this->assertStringContainsString('Vape / pod', $csv);
        $json = json_decode($c->get('/api/account.php?action=export&format=json')['body'], true);
        $this->assertSame(8, (int)$json['marcadores_diarios'][0]['cigarettes']);
        $this->assertSame(3, (int)$json['marcadores_por_periodo'][0]['th_grandiose']);
        $this->assertArrayHasKey('compartilhamentos_profissionais', $json);
        $this->assertArrayHasKey('acessos_profissionais', $json);
        // destinatário: pensamentos de morte desligado por padrão
        $r = $c->json('POST', '/api/recipients.php', ['name' => 'Dra', 'email' => 'dra-v48@example.com', 'role' => 'psiquiatra', 'frequency' => 'semanal']);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame('0', (string)db()->query('SELECT include_death FROM recipients WHERE id = ' . (int)$r['json']['id'])->fetchColumn());
        $hist = $c->get('/api/entries.php?from=2026-11-08&to=2026-11-08')['json']['entries'][0];
        $this->assertSame(3, (int)$hist['pm_th_grandiose']);
        $this->assertSame(8, (int)$hist['dm_cigarettes']);
        $ind = mania_indicators([['entry_date' => '2026-11-08', 'mood' => 0, 'sleep_hours' => null, 'energy' => null, 'irritability' => null]], [['entry_date' => '2026-11-08', 'th_grandiose' => 2]], [], 0);
        $this->assertContains('grandiosidade', $ind['2026-11-08']['reasons']);
    }

    public function testTobaccoLogsFeedCigarettes(): void
    {
        $c = $this->user('v48tab');
        $this->mods($c, ['substancias' => true]);
        $this->assertSame(200, $c->json('POST', '/api/day.php?action=subst_add', ['entry_date' => '2026-11-09', 'substance' => 'tabaco', 'form' => 'cigarro', 'amount' => 4, 'period' => 'manha'])['status']);
        $c->json('POST', '/api/day.php?action=subst_add', ['entry_date' => '2026-11-09', 'substance' => 'tabaco', 'form' => 'vape_pod', 'amount' => 30, 'period' => 'tarde']);
        $m = $c->get('/api/day.php?from=2026-11-09&to=2026-11-09')['json']['markers'][0];
        $this->assertSame(4, (int)$m['cigarettes'], 'vape não conta como cigarro');
    }

    public function testUiAndMigration(): void
    {
        $c = $this->user('v48ui');
        $html = $c->get('/')['body'];
        $this->assertStringContainsString('data-mod="pensamentos"', $html);
        $this->assertStringContainsString('name="th_grandiose"', $html);
        $this->assertStringNotContainsString('name="racing_thoughts"', $html, 'sem item duplicado com Pensamentos por período ligado');
        $this->assertStringContainsString('name="cigarettes"', $html);
        $this->assertStringContainsString('name="nicotine_other"', $html);
        $this->assertStringContainsString('id="care-card"', $html);
        $this->assertStringContainsString('/assets/js/care.js?v=1', $html);
        $mods = array_fill_keys(array_keys(DAY_MODULES), true);
        $mods['pensamentos'] = false;
        $c->json('POST', '/api/account.php?action=prefs', ['modules' => $mods]);
        $this->assertStringContainsString('name="racing_thoughts"', $c->get('/')['body'], 'sem o módulo, volta o item do dia');
        $mig = (string)file_get_contents(dirname(__DIR__, 2) . '/migrations/2026-11-10-v4-8-pensamentos-nicotina-profissionais.sql');
        $this->assertStringContainsString('Duplicate column', $mig);
        $this->assertStringNotContainsStringIgnoringCase('phpmyadmin', $mig);
        foreach (preg_split('/\n/', $mig) as $line) if (str_starts_with($line, '--')) $this->assertStringNotContainsString(';', $line);
        $this->assertSame('0.5.0-beta', trim((string)file_get_contents(dirname(__DIR__, 2) . '/VERSION')));
        $sw = (string)file_get_contents(dirname(__DIR__, 2) . '/public_html/sw.js');
        $this->assertStringContainsString("'bp-v15'", $sw);
        $this->assertStringContainsString('/assets/js/care.js?v=1', $sw);
    }
}
