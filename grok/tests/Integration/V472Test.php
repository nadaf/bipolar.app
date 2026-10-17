<?php
/** V4.7.2 (0.4.2-beta): "Meu dia" mostrava os dados de outra data depois de trocar a data. */
final class V472Test extends IntegrationCase
{
    private function js(): string { return (string)file_get_contents(dirname(__DIR__, 2) . '/public_html/assets/js/app.js'); }

    public function testApiReturnsOnlyTheRequestedDay(): void
    {
        $c = $this->user('v472api');
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-17', 'period' => 'manha', 'bed_time' => '23:00', 'caffeine_cups' => 2, 'stress_level' => 3, 'stress_note' => 'hoje']);
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-16', 'period' => 'tarde', 'bed_time' => '01:30', 'caffeine_cups' => 6, 'stress_level' => 1, 'stress_note' => 'ontem']);
        $y = $c->get('/api/day.php?from=2026-10-16&to=2026-10-16')['json'];
        $this->assertCount(1, $y['markers']);
        $this->assertSame('2026-10-16', $y['markers'][0]['entry_date']);
        $this->assertSame(6, (int)$y['markers'][0]['caffeine_cups']);
        $this->assertCount(1, $y['period_markers']);
        $this->assertSame('ontem', $y['period_markers'][0]['stress_note']);
        $e = $c->get('/api/day.php?from=2026-10-15&to=2026-10-15')['json'];
        $this->assertSame([], $e['markers'], 'dia sem dados volta vazio');
        $this->assertSame([], $e['period_markers']);
    }
    public function testEveryProgrammaticDateChangeReloadsMyDay(): void
    {
        $js = $this->js();
        // entryDate.set (usado por Hoje/Editar/Novo registro/salvar/IA) agenda a sincronização de "Meu dia"
        $this->assertMatchesRegularExpression('/entryDate\.set = \(iso\) => \{ rawSet\(iso\); queueMicrotask\(daySync\); \}/', $js);
        $this->assertStringContainsString("function daySync() { const d = entryDate.get(); if (d && d !== dayWant) loadDay(); }", $js);
    }
    public function testLoadDayDiscardsStaleResponsesAndNeverShowsAnotherDate(): void
    {
        $js = $this->js();
        $this->assertMatchesRegularExpression('/const my = \+\+dayReq; dayWant = d;/', $js);
        $this->assertStringContainsString("if (my !== dayReq || entryDate.get() !== d) return;", $js);
        $this->assertStringContainsString("if (dayLoaded !== d) { dayLoaded = null; dayBlank(d); }", $js);
        // a saída antiga (offline) deixava o formulário com a data anterior
        $this->assertStringNotContainsString("if (!d || !navigator.onLine) return;", $js);
    }
    public function testSavingIsBlockedUntilTheChosenDayIsLoaded(): void
    {
        $js = $this->js();
        $this->assertSame(2, substr_count($js, 'if (!dayReady(d)) return;'), 'Meu dia e Sexualidade');
        $this->assertStringContainsString('id="day-load-note"', (string)file_get_contents(dirname(__DIR__, 2) . '/app/views/day_panels.php'));
    }
}
