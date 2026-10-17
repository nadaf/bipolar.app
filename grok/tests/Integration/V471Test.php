<?php
/** V4.7.1 (0.4.1-beta): valores fora da escala recusados com 422; linhas vazias de period_markers/daily_markers apagadas. */
final class V471Test extends IntegrationCase
{
    private function rows(string $sql, array $p): int { $st = db()->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); }

    public function testOutOfRangePeriodFieldsAreRejectedWithPtBrMessage(): void
    {
        $c = $this->user('v471bad');
        foreach ([['food_sweets', 9, 'Doces'], ['food_processed', -1, 'Industrializados'], ['stress_level', 4, 'Nível de estresse'], ['food_sweets', 'muito', 'Doces'], ['food_binge', 2, 'Comi fora de hora'], ['food_binge', 'talvez', 'Comi fora de hora']] as [$k, $val, $label]) {
            $r = $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-20', 'period' => 'manha', 'stress_level' => 1, $k => $val]);
            $this->assertSame(422, $r['status'], "$k=" . json_encode($val));
            $this->assertStringContainsString($label, $r['json']['erro']);
            $this->assertStringContainsString('Valor inválido', $r['json']['erro']);
        }
        $g = $c->get('/api/day.php?from=2026-10-20&to=2026-10-20')['json'];
        $this->assertSame([], $g['period_markers'], 'nada gravado quando recusado');
        $this->assertSame([], $g['markers']);
        // limites e vazios continuam válidos
        foreach ([0, 3, '2', null, ''] as $ok) $this->assertSame(200, $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-20', 'period' => 'tarde', 'food_sweets' => $ok, 'stress_level' => 1])['status'], json_encode($ok));
        foreach ([true, false, 0, 1, '0', '1', null, ''] as $ok) $this->assertSame(200, $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-20', 'period' => 'tarde', 'food_binge' => $ok])['status'], json_encode($ok));
    }
    public function testOutOfRangeDayScaleFieldsAreRejected(): void
    {
        $c = $this->user('v471day');
        foreach ([['sleep_quality', 5, 'Qualidade do sono'], ['racing_thoughts', 7, 'Pensamentos acelerados'], ['caffeine_cups', 99, 'Cafeína'], ['alcohol_units', -2, 'Álcool'], ['sun_minutes', 2000, 'sol'], ['spending_amount', 'abc', 'gastei'], ['cycle_phase', 'xyz', 'Ciclo'], ['irritability_day', '3.x', 'Irritabilidade']] as [$k, $val, $label]) {
            $r = $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-21', 'bed_time' => '23:00', $k => $val]);
            $this->assertSame(422, $r['status'], "$k=" . json_encode($val));
            $this->assertStringContainsString($label, $r['json']['erro']);
        }
        $this->assertSame([], $c->get('/api/day.php?from=2026-10-21&to=2026-10-21')['json']['markers'], 'nada gravado');
        $r = $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-21', 'sleep_quality' => 3, 'racing_thoughts' => 0, 'caffeine_cups' => '', 'spending_amount' => null, 'cycle_phase' => '']);
        $this->assertSame(200, $r['status'], $r['body']);
    }
    public function testClearingPeriodDeletesEmptyRows(): void
    {
        $c = $this->user('v471clr');
        $uid = $this->uid('v471clr');
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-22', 'period' => 'manha', 'stress_level' => 2, 'stress_note' => 'x', 'food_sweets' => 1, 'food_binge' => 1]);
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-22', 'period' => 'noite', 'food_processed' => 3]);
        $this->assertSame(2, $this->rows('SELECT COUNT(*) FROM period_markers WHERE user_id = ?', [$uid]));
        $clear = ['stress_level' => null, 'stress_note' => '', 'food_sweets' => null, 'food_processed' => null, 'food_binge' => null];
        $r = $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-22', 'period' => 'manha'] + $clear);
        $this->assertSame(200, $r['status']);
        $g = $c->get('/api/day.php?from=2026-10-22&to=2026-10-22')['json'];
        $this->assertSame(['noite'], array_column($g['period_markers'], 'period'), 'Manhã limpa = linha apagada');
        $this->assertSame([null, null, 3], [$g['markers'][0]['stress_level'], $g['markers'][0]['stress_note'], (int)$g['markers'][0]['food_processed']]);
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-22', 'period' => 'noite'] + $clear);
        $this->assertSame(0, $this->rows('SELECT COUNT(*) FROM period_markers WHERE user_id = ?', [$uid]));
        $this->assertSame(0, $this->rows('SELECT COUNT(*) FROM daily_markers WHERE user_id = ?', [$uid]), 'dia sem nenhum dado = linha apagada');
        // "não" em compulsão (0) é um dado: fica
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-22', 'period' => 'tarde', 'food_binge' => false]);
        $this->assertSame(1, $this->rows('SELECT COUNT(*) FROM period_markers WHERE user_id = ? AND food_binge = 0', [$uid]));
        // dia com outros marcadores (sono) mantém daily_markers mesmo sem estresse/alimentação
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-23', 'bed_time' => '23:00', 'period' => 'manha', 'stress_level' => 1]);
        $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-23', 'period' => 'manha'] + $clear);
        $this->assertSame(1, $this->rows("SELECT COUNT(*) FROM daily_markers WHERE user_id = ? AND entry_date = '2026-10-23' AND bed_time = '23:00'", [$uid]));
        // limpar tudo do dia pelo formulário (todos os campos vazios) apaga a linha
        $all = array_fill_keys(['bed_time', 'wake_time', 'sleep_quality', 'sun_minutes', 'sun_period', 'caffeine_cups', 'alcohol_units', 'exercise_minutes', 'racing_thoughts', 'fast_speech', 'impulsive_spending', 'spending_amount', 'irritability_day', 'cycle_phase'], '') + ['doses' => [], 'signs' => []];
        $this->assertSame(200, $c->json('POST', '/api/day.php', ['entry_date' => '2026-10-23', 'period' => 'manha'] + $all + $clear)['status']);
        $this->assertSame(0, $this->rows("SELECT COUNT(*) FROM daily_markers WHERE user_id = ? AND entry_date = '2026-10-23'", [$uid]));
    }
    public function testOtherUsersRowsUntouchedByPrune(): void
    {
        $a = $this->user('v471a');
        $a->json('POST', '/api/day.php', ['entry_date' => '2026-10-24', 'period' => 'manha', 'stress_level' => 2]);
        $b = $this->user('v471b');
        $b->json('POST', '/api/day.php', ['entry_date' => '2026-10-24', 'period' => 'manha', 'stress_level' => 1]);
        $b->json('POST', '/api/day.php', ['entry_date' => '2026-10-24', 'period' => 'manha', 'stress_level' => null]);
        $this->assertSame(1, $this->rows("SELECT COUNT(*) FROM period_markers WHERE entry_date = '2026-10-24'", []));
        $this->assertSame([['period' => 'manha']], array_map(fn($r) => ['period' => $r['period']], $a->get('/api/day.php?from=2026-10-24&to=2026-10-24')['json']['period_markers']));
    }
    public function testVersionAndCache(): void
    {
        $this->assertSame('0.5.0-beta', trim((string)file_get_contents(TestEnv::ROOT . '/VERSION')));
        $this->assertStringContainsString("const VERSION = 'bp-v15';", (string)file_get_contents(TestEnv::ROOT . '/public_html/sw.js'));
        $m = (string)file_get_contents(TestEnv::ROOT . '/migrations/2026-11-04-v4-7-1-limpeza-vazios.sql');
        $this->assertStringNotContainsStringIgnoringCase('information_schema', $m);
        foreach (preg_split('/\R/', $m) as $l) if (str_starts_with(ltrim($l), '--')) $this->assertStringNotContainsString(';', $l, 'sem ";" em comentário');
    }
}
