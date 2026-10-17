<?php
/** V4.7: toast que não some, estresse/eventos por período, botão Revelar/Ocultar, Alimentação, MFA nos termos, fuso do enabled_at, app público sem revenda. */
final class V47Test extends IntegrationCase
{
    private function css(): string { return (string)file_get_contents(TestEnv::ROOT . '/public_html/assets/css/app.css'); }
    private function js(string $f = 'app.js'): string { return (string)file_get_contents(TestEnv::ROOT . '/public_html/assets/js/' . $f); }
    /** Regra CSS exata (seletor no início da linha). */
    private function rule(string $css, string $selector): string
    {
        $this->assertSame(1, preg_match('/^' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m', $css, $m), "regra $selector");
        return $m[1];
    }

    /* ---------------- Bug 1: toast ---------------- */
    public function testHiddenToastIsReallyInvisibleAndAboveTabbar(): void
    {
        $css = $this->css();
        $base = $this->rule($css, '.toast');
        // antes: só translate(-50%, 150%) — com bottom acima da barra de abas, ficava parado em cima de "Histórico"
        $this->assertStringContainsString('visibility: hidden', $base);
        $this->assertStringContainsString('opacity: 0', $base);
        $this->assertStringContainsString('pointer-events: none', $base);
        $this->assertStringNotContainsString('150%', $base);
        $show = $this->rule($css, '.toast.show');
        $this->assertStringContainsString('visibility: visible', $show);
        $this->assertStringContainsString('opacity: 1', $show);
        $this->assertMatchesRegularExpression('/body\.app \.toast \{ bottom: calc\(var\(--tabbar-h[^)]*\) \+ \.6rem\); z-index: 19;/', $css, 'acima da barra e abaixo dela no empilhamento (barra = 20)');
        $this->assertMatchesRegularExpression('/prefers-reduced-motion: reduce\) \{ \.toast, \.toast\.show \{ transition: none/', $css);
    }
    public function testToastAutoDismissesOnTimerNavigationAndResume(): void
    {
        $js = $this->js();
        $this->assertStringContainsString("const TOAST_MS = { ok: 4000, warn: 7000, err: 7000 };", $js);
        $this->assertMatchesRegularExpression('/function selectTab\(tab, focus\) \{.*?hideToast\(\);/s', $js, 'trocar de aba fecha o toast');
        $this->assertStringContainsString("document.addEventListener('visibilitychange'", $js, 'voltar ao app depois do prazo fecha o toast');
        $this->assertStringContainsString("addEventListener('pagehide', hideToast)", $js);
        $this->assertStringContainsString("t.setAttribute('aria-live', 'polite')", $js);
        $this->assertStringContainsString("toastEl();\n", $js, 'região aria-live criada na carga, antes da primeira mensagem');
        $mfa = $this->js('mfa.js');
        $this->assertStringContainsString("setTimeout(() => { t._until = 0; t.classList.remove('show'); }, ms)", $mfa);
        // os arquivos novos chegam a quem já tem o app instalado (cache do service worker renovado)
        $sw = (string)file_get_contents(TestEnv::ROOT . '/public_html/sw.js');
        $this->assertStringContainsString("const VERSION = 'bp-v14';", $sw);
        $layout = (string)file_get_contents(TestEnv::ROOT . '/app/views/layout.php');
        $this->assertStringContainsString('/assets/css/app.css?v=12', $layout);
        $this->assertStringContainsString('/assets/css/app.css?v=12', $sw);
        $this->assertStringContainsString('/assets/js/app.js?v=12', $sw);
    }

    /* ---------------- Bug 2: estresse/acontecimentos por período ---------------- */
    public function testStressIsPerPeriodAndDoesNotLeakToOtherPeriods(): void
    {
        $c = $this->user('v47per');
        $r = $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-10', 'period' => 'noite', 'stress_level' => 3, 'stress_note' => 'discussão no trabalho']);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame('noite', $r['json']['period']);
        $g = $c->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json'];
        $this->assertCount(1, $g['period_markers']);
        $this->assertSame(['noite', 3, 'discussão no trabalho'], [$g['period_markers'][0]['period'], (int)$g['period_markers'][0]['stress_level'], $g['period_markers'][0]['stress_note']]);
        // Manhã continua vazia; salvar a Manhã não mexe na Noite
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-10', 'period' => 'manha', 'stress_level' => 1, 'stress_note' => null]);
        $g = $c->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json'];
        $by = array_column($g['period_markers'], null, 'period');
        $this->assertSame(1, (int)$by['manha']['stress_level']);
        $this->assertNull($by['manha']['stress_note']);
        $this->assertSame(3, (int)$by['noite']['stress_level']);
        $this->assertSame('discussão no trabalho', $by['noite']['stress_note']);
        // total do dia (alertas/relatórios/IA) = maior nível; a anotação leva o nome do período
        $this->assertSame(3, (int)$g['markers'][0]['stress_level']);
        $this->assertSame('discussão no trabalho', $g['markers'][0]['stress_note']);
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-10', 'period' => 'manha', 'stress_note' => 'trânsito']);
        $g = $c->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json'];
        $this->assertSame('Manhã: trânsito · Noite: discussão no trabalho', $g['markers'][0]['stress_note']);
        // limpar a Noite reduz o total do dia
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-10', 'period' => 'noite', 'stress_level' => null, 'stress_note' => null]);
        $g = $c->get('/api/day.php?from=2026-10-10&to=2026-10-10')['json'];
        $this->assertSame(1, (int)$g['markers'][0]['stress_level']);
        // período inválido é recusado; marcadores do dia (sono) sem período continuam funcionando
        $this->assertSame(422, $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-10', 'period' => 'madrugada', 'stress_level' => 1])['status']);
        $r = $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-11', 'bed_time' => '23:00']);
        $this->assertSame(200, $r['status']);
        $this->assertNull($r['json']['period']);
        $this->assertSame([], $c->get('/api/day.php?from=2026-10-11&to=2026-10-11')['json']['period_markers']);
    }
    public function testOldCachedClientWithoutPeriodUsesClockPeriod(): void
    {
        $c = $this->user('v47old');
        $r = $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-12', 'stress_level' => 2]);
        $this->assertSame(200, $r['status']);
        $tz = new DateTimeZone('America/Cuiaba');
        $this->assertContains($r['json']['period'], ['manha', 'tarde', 'noite']);
        $h = (int)(new DateTime('now', $tz))->format('G');
        if ($h !== 11 && $h !== 17) $this->assertSame($h < 12 ? 'manha' : ($h < 18 ? 'tarde' : 'noite'), $r['json']['period']);
    }
    public function testOtherUserCannotSeePeriodMarkers(): void
    {
        $a = $this->user('v47a'); $b = $this->user('v47b');
        $a->json('POST', '/api/day.php', ['entry_date' => '2026-10-13', 'period' => 'tarde', 'food_sweets' => 3]);
        $this->assertSame([], $b->get('/api/day.php?from=2026-10-13&to=2026-10-13')['json']['period_markers']);
    }
    public function testDayFormAndJsResetPerPeriodFields(): void
    {
        $c = $this->user('v47ui');
        $html = $c->get('/')['body'];
        $this->assertStringContainsString('data-mod="estresse" data-per-period', $html);
        $this->assertStringContainsString('data-mod="alimentacao" data-per-period', $html);
        $this->assertStringNotContainsString('vale para o dia inteiro, não para cada período', $html);
        $js = $this->js();
        $this->assertStringContainsString("r.addEventListener('change', () => { fillPeriodFields(); loadSlot(); })", $js, 'trocar o período recarrega os campos do período');
        $this->assertMatchesRegularExpression('/function setPeriod\(p\) \{[^\n]*fillPeriodFields\(\)/', $js, 'troca automática de período também');
        $this->assertStringContainsString("const p = { entry_date: d, period: curPeriod() };", $js, 'salvar envia o período');
        $this->assertDoesNotMatchRegularExpression("/'stress_note'\\]\\.forEach\\(\\(k\\) => \\{ const i = dayForm/", $js, 'estresse não é mais preenchido a partir do dia');
    }

    /* ---------------- Bug 3: Revelar / Ocultar ---------------- */
    public function testHideToggleSaysRevelarWhenHidden(): void
    {
        $c = $this->user('v47hide');
        $c->json('POST', '/api/account.php?action=prefs', ['modules' => ['sexualidade' => true, 'substancias' => true], 'sex_blur' => true, 'subst_blur' => false]);
        $html = $c->get('/')['body'];
        $this->assertMatchesRegularExpression('#id="sex-hide" aria-controls="sex-body" aria-label="Revelar dados de Sexualidade">Revelar</button>#', $html, 'oculto (desfocado) → Revelar');
        $this->assertMatchesRegularExpression('#id="subst-hide" aria-controls="subst-body" aria-label="Ocultar dados de Substâncias">Ocultar</button>#', $html, 'visível → Ocultar');
        $js = $this->js();
        $this->assertStringContainsString("const verb = privHidden(k) ? 'Revelar' : 'Ocultar';", $js);
        $this->assertStringContainsString("if (privHidden('sex')) return revealPriv('sex');", $js);
        $this->assertStringContainsString("if (privHidden('subst')) return revealPriv('subst');", $js);
        $this->assertStringContainsString('new MutationObserver(() => syncHideBtn(k))', $js, 'acompanha desfocar, trancar e desbloquear');
        $this->assertStringNotContainsString('aria-pressed', (string)file_get_contents(TestEnv::ROOT . '/app/views/day_panels.php'), 'rótulo muda com o estado: sem aria-pressed (WAI-ARIA)');
    }

    /* ---------------- Alimentação ---------------- */
    public function testFoodPerPeriodValidationHistoryAndExport(): void
    {
        $c = $this->user('v47food');
        $this->entry($c, ['entry_date' => '2026-10-14', 'period' => 'tarde', 'mood' => 1]);
        $r = $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-14', 'period' => 'tarde', 'food_sweets' => 3, 'food_processed' => 2, 'food_binge' => 1]);
        $this->assertSame(200, $r['status'], $r['body']);
        // V4.7.1: fora da escala é recusado (antes virava "não informado" em silêncio)
        $this->assertSame(422, $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-14', 'period' => 'manha', 'food_sweets' => 0, 'food_processed' => 9, 'food_binge' => ''])['status']);
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-14', 'period' => 'manha', 'food_sweets' => 0, 'food_processed' => null, 'food_binge' => '']);
        $g = $c->get('/api/day.php?from=2026-10-14&to=2026-10-14')['json'];
        $by = array_column($g['period_markers'], null, 'period');
        $this->assertSame([0, null, null], [(int)$by['manha']['food_sweets'], $by['manha']['food_processed'], $by['manha']['food_binge']], 'vazio = não informado');
        $this->assertSame([3, 2, 1], [(int)$by['tarde']['food_sweets'], (int)$by['tarde']['food_processed'], (int)$by['tarde']['food_binge']]);
        $this->assertSame([3, 2, 1], [(int)$g['markers'][0]['food_sweets'], (int)$g['markers'][0]['food_processed'], (int)$g['markers'][0]['food_binge']], 'total do dia');
        // Histórico: o registro da Tarde traz a alimentação da Tarde
        $e = $c->get('/api/entries.php?from=2026-10-14&to=2026-10-14')['json']['entries'][0];
        $this->assertSame(['tarde', 3, 2, 1], [$e['period'], (int)$e['pm_sweets'], (int)$e['pm_processed'], (int)$e['pm_binge']]);
        // exportação: CSV com colunas novas, JSON com marcadores por período
        $csv = $c->get('/api/account.php?action=export&format=csv')['body'];
        $this->assertStringContainsString(';estresse;acontecimento;doces;ultraprocessados;comeu_fora_de_hora', $csv);
        $this->assertStringContainsString(';Muito;Moderado;sim', $csv);
        $json = $c->get('/api/account.php?action=export&format=json')['json'];
        $this->assertCount(2, $json['marcadores_por_periodo']);
        $this->assertArrayHasKey('food_sweets', $json['marcadores_diarios'][0]);
        $this->assertArrayHasKey('include_food', $json['destinatarios'] ? $json['destinatarios'][0] : ['include_food' => 0]);
        // tela: escala de 4 níveis + "não informado" acessível + caixa opcional
        $html = $c->get('/')['body'];
        foreach (['Nenhum', 'Pouco', 'Moderado', 'Muito', 'Doces', 'Industrializados / ultraprocessados', 'Comi fora de hora / compulsão'] as $t) $this->assertStringContainsString($t, $html);
        $this->assertStringContainsString('<span class="sr-only">Não informado</span>', $html);
    }
    public function testFoodModuleOnByDefaultAndCanBeTurnedOff(): void
    {
        $this->assertTrue(user_prefs(['prefs' => null])['modules']['alimentacao']);
        $c = $this->user('v47off');
        $c->json('POST', '/api/account.php?action=prefs', ['modules' => ['estresse' => true]]);
        $this->assertMatchesRegularExpression('/data-mod="alimentacao" data-per-period hidden/', $c->get('/')['body']);
    }
    public function testFoodInReportsOnlyWithOptIn(): void
    {
        $c = $this->user('v47rep');
        $uid = $this->uid('v47rep');
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-14', 'period' => 'noite', 'food_sweets' => 2, 'food_binge' => 1]);
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-15', 'period' => 'manha', 'food_processed' => 3]);
        $no = markers_report_data($uid, '2026-10-01', '2026-10-31', false, false, false);
        $this->assertNull($no['food']);
        $this->assertStringNotContainsString('Alimentação', markers_report_html($no));
        $yes = markers_report_data($uid, '2026-10-01', '2026-10-31', false, false, true);
        $this->assertSame(['days' => 2, 'sweets' => 1, 'processed' => 1, 'binge' => 1], $yes['food']);
        $this->assertStringContainsString('Alimentação — dias em que comeu fora de hora / compulsão', markers_report_html($yes));
        $this->assertStringContainsString('Alimentação', $c->get('/relatorio.php?de=2026-10-01&ate=2026-10-31&alimentacao=1')['body']);
        $this->assertStringNotContainsString('Alimentação —', $c->get('/relatorio.php?de=2026-10-01&ate=2026-10-31')['body']);
        // destinatário: desligado por padrão, liga só com true
        $r = $c->json('POST', '/api/recipients.php', ['name' => 'Dra Food', 'email' => 'dra.food@example.com', 'frequency' => 'semanal']);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame('0', (string)db()->query("SELECT include_food FROM recipients WHERE email = 'dra.food@example.com'")->fetchColumn());
        $this->assertSame(200, $c->json('PATCH', '/api/recipients.php?id=' . $r['json']['id'], ['include_food' => true])['status']);
        $this->assertSame('1', (string)db()->query("SELECT include_food FROM recipients WHERE email = 'dra.food@example.com'")->fetchColumn());
    }
    public function testFoodIsOwnAiCategoryOptIn(): void
    {
        $this->assertSame(false, AI_CATEGORIES['alimentacao'][1], 'fora por padrão');
        $this->assertNotContains('alimentacao', ai_settings(['prefs' => null])['categories']);
        $c = $this->user('v47ai');
        $uid = $this->uid('v47ai');
        $today = (new DateTime('now', new DateTimeZone('America/Cuiaba')))->format('Y-m-d');
        $c->json('POST', '/api/day.php', ['entry_date' => $today, 'period' => 'tarde', 'food_sweets' => 3]);
        $u = db()->query("SELECT * FROM users WHERE id = $uid")->fetch();
        $this->assertStringNotContainsString('alimentação', ai_context(db(), $u, ['humor', 'marcadores']));
        $this->assertStringContainsString('tarde alimentação: doces muito', ai_context(db(), $u, ['alimentacao']));
    }
    public function testCustomAlertSweetsAndAnxietyForThreeDays(): void
    {
        require_once TestEnv::ROOT . '/app/lib/custom_alerts.php';
        $tpl = custom_templates()['doces_ansiedade'];
        [$err, $rule] = custom_rule_validate($tpl + ['severity' => 1], []);
        $this->assertNull($err);
        $this->assertSame('doces ≥ moderado (2) por 3 dias seguidos E ansiedade ≥ moderado (2) por 3 dias seguidos', custom_describe_rule($rule));
        $c = $this->user('v47ca');
        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $i => $d) {
            $this->entry($c, ['entry_date' => $d, 'period' => 'noite', 'mood' => 0, 'anxiety' => 3]);
            $c->json('POST', '/api/day.php', ['entry_date' => $d, 'period' => $i === 1 ? 'manha' : 'noite', 'food_sweets' => 3]);
        }
        $u = db()->query('SELECT * FROM users WHERE id = ' . $this->uid('v47ca'))->fetch();
        $D = custom_load(db(), $u, '2026-10-01', '2026-10-03');
        $this->assertSame(3, $D['2026-10-02']['sweets'], 'doces de qualquer período contam no dia');
        $this->assertTrue(custom_rule_on($D, $rule, '2026-10-03')[0]);
        $this->assertFalse(custom_rule_on($D, $rule, '2026-10-02')[0], 'só 2 dias');
        $this->assertSame('muito (3)', custom_fmt_value('sweets', 3));
        $this->assertStringContainsString("if (m.type === 'food') return '0 nenhum · 1 pouco · 2 moderado · 3 muito';", $this->js());
    }
    public function testPrivacyPolicyListsFoodCategory(): void
    {
        $b = $this->prod()->get('/privacidade.php')['body'];
        $this->assertStringContainsString('id="alimentacao"', $b);
        $this->assertStringContainsString('Versão V4.7', $b);
        $this->assertStringContainsString('Anotações, alimentação, sexualidade e substâncias ficam de fora', $b);
    }

    /* ---------------- itens baixos ---------------- */
    public function testTermsMentionMfa(): void
    {
        $b = $this->prod()->get('/termos.php')['body'];
        $this->assertStringContainsString('verificação em duas etapas', $b);
        $this->assertStringContainsString('códigos de recuperação', $b);
    }
    public function testMfaEnabledAtInUserTimezone(): void
    {
        $l = mfa_enabled_at_local(['mfa_enabled_at' => '2026-10-16 02:30:00', 'timezone' => 'America/Cuiaba']);
        $this->assertSame(['iso' => '2026-10-15T22:30:00-04:00', 'br' => '15/10/2026 22:30', 'date' => '15/10/2026'], $l);
        $this->assertNull(mfa_enabled_at_local(['mfa_enabled_at' => null, 'timezone' => 'America/Cuiaba']));
        $this->assertStringContainsString("mfa_enabled_at_local(\$user)['date']", (string)file_get_contents(TestEnv::ROOT . '/app/views/account_mfa.php'));
    }

    /* ---------------- C: app público sem revenda ---------------- */
    public function testPublicAppWorksWithoutPrivateModule(): void
    {
        if (TestEnv::hasPrivate()) {
            // árvore completa do site: as páginas e o webhook vêm do módulo privado
            $this->assertTrue(ext_has('leads'));
            $this->assertStringContainsString('R$ 49,90', $this->prod()->get('/profissionais.php')['body']);
            $this->assertStringContainsString('id="contatos"', $this->prod()->get('/privacidade.php')['body']);
            $this->assertSame(404, $this->prod()->get('/api/billing-webhook.php')['status'], 'cobrança desligada no config de teste');
            return;
        }
        foreach (['app/lib/billing.php', 'app/lib/leads.php', 'app/views/marketing.php', 'public_html/api/billing-webhook.php', 'tools/billing_checkout.php', 'tools/export_leads.php', 'cron/billing_retry.php', 'cron/purge_leads.php', 'app/ext'] as $f) {
            $this->assertFileDoesNotExist(TestEnv::ROOT . '/' . $f, "$f é do módulo privado");
        }
        $this->assertSame([], glob(TestEnv::ROOT . '/migrations/*{leads,billing}*', GLOB_BRACE) ?: []);
        $this->assertDoesNotMatchRegularExpression('/billing_|CREATE TABLE IF NOT EXISTS leads/', (string)file_get_contents(TestEnv::ROOT . '/schema.sql'));
        $this->assertDoesNotMatchRegularExpression("/'billing' =>|'sales' =>|mercadopago/i", (string)file_get_contents(TestEnv::ROOT . '/app/config.example.php'));
        $this->assertFalse(ext_has('leads'));
        $this->assertFalse(ext_page('profissionais'));
        $this->assertSame('', ext_partial('privacidade-contatos'));
        foreach (['/profissionais.php', '/comecar.php'] as $p) {
            $r = $this->prod()->get($p);
            $this->assertSame(200, $r['status'], $p);
            $this->assertStringNotContainsString('<form', $r['body'], "$p sem formulário de contato");
            $this->assertStringNotContainsString('R$', $r['body'], "$p sem preços");
            $this->assertStringContainsString('rel="canonical"', $r['body']);
        }
        $this->assertStringNotContainsString('id="contatos"', $this->prod()->get('/privacidade.php')['body']);
        $this->assertSame(404, $this->prod()->get('/api/billing-webhook.php')['status']);
    }
    public function testExtensionNamesAreSafe(): void
    {
        $this->assertTrue(ext_name_ok('privacidade-contatos'));
        foreach (['../config', 'a/b', '', 'Pagina', str_repeat('a', 41)] as $bad) $this->assertFalse(ext_name_ok($bad), $bad);
    }
}
