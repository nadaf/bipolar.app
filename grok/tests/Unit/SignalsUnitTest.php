<?php
use PHPUnit\Framework\TestCase;

/** Motor de sinais (V4) com cenários sintéticos: início de mania, depressão, misto, ciclagem rápida, lacunas etc. */
final class SignalsUnitTest extends TestCase
{
    private const TODAY = '2026-10-14';

    /** Linha de base estável: 35 dias, humor 0, deita 23:30 e acorda 07:00 (7,5 h), 2 cafés, 1 nota de mídia a cada 3 dias. */
    private function base(int $days = 35, int $skipLast = 0): array
    {
        $e = []; $m = []; $media = [];
        for ($i = $days; $i >= 1 + $skipLast; $i--) {
            $d = sig_add(self::TODAY, -$i);
            foreach (['manha', 'tarde', 'noite'] as $p) $e[] = ['entry_date' => $d, 'period' => $p, 'mood' => 0, 'sleep_hours' => null, 'energy' => 1, 'irritability' => 0, 'medication_taken' => 1];
            $m[$d] = ['entry_date' => $d, 'bed_time' => $i % 2 ? '23:30' : '23:00', 'wake_time' => '07:00', 'caffeine_cups' => 2, 'racing_thoughts' => 0, 'fast_speech' => 0, 'impulsive_spending' => 0, 'irritability_day' => 0, 'stress_level' => 1, 'sun_minutes' => 40];
            if ($i % 3 === 0) $media[$d] = 1;
        }
        return [$e, $m, $media];
    }
    /** @param list<array<string,mixed>> $e */
    private function setDay(array &$e, array &$m, string $d, array $moods, array $mk = [], array $entryExtra = []): void
    {
        $e = array_values(array_filter($e, function (array $x) use ($d): bool { return $x['entry_date'] !== $d; }));
        foreach ($moods as $p => $v) $e[] = $entryExtra + ['entry_date' => $d, 'period' => $p, 'mood' => $v, 'sleep_hours' => null, 'energy' => 1, 'irritability' => 0, 'medication_taken' => 1];
        $m[$d] = $mk + ($m[$d] ?? ['entry_date' => $d, 'bed_time' => '23:30', 'wake_time' => '07:00']) + ['entry_date' => $d];
    }
    private function rules(array $alerts): array { return array_column($alerts, 'severity', 'rule'); }
    private function ev(array $e, array $m, array $media = [], array $tune = [], array $sex = [], array $sub = []): array
    {
        return signals_evaluate(signals_daily($e, array_values($m), $sex, $sub, $media), self::TODAY, $tune);
    }

    public function testStableBaselineHasNoAlerts(): void
    {
        [$e, $m, $media] = $this->base();
        $this->setDay($e, $m, self::TODAY, ['manha' => 0, 'tarde' => 0]);
        $this->assertSame([], $this->ev($e, $m, $media));
    }
    public function testRobustStatistics(): void
    {
        $this->assertSame(3.0, signals_median([5.0, 1.0, 3.0]));
        $this->assertSame(2.5, signals_median([1.0, 2.0, 3.0, 4.0]));
        $D = signals_daily([], [['entry_date' => '2026-10-01', 'bed_time' => '23:00', 'wake_time' => '07:00'], ['entry_date' => '2026-10-02', 'bed_time' => '23:00', 'wake_time' => '06:00'], ['entry_date' => '2026-10-03', 'bed_time' => '22:00', 'wake_time' => '07:00']]);
        $b = signals_baseline($D, 'sleep', '2026-10-05', 3);
        $this->assertSame(8.0, $b['med']);
        $this->assertEqualsWithDelta(1.4826, $b['mad'], 1e-9, 'MAD × 1,4826');
        $this->assertNull(signals_baseline($D, 'sleep', '2026-10-05', 7), 'poucos dados = sem linha de base');
        $this->assertSame(0.0, signals_z(8.0, $b, 0.5));
        $this->assertEqualsWithDelta(4.0, signals_mssd([0, 2, 0, 2, 0]) ?? 0, 1e-9, "MSSD de oscilação ±2");
        $this->assertNull(signals_mssd([1, 2]));
    }
    public function testManiaOnset(): void
    {
        [$e, $m, $media] = $this->base();
        foreach ([-2, -1, 0] as $k) {
            $d = sig_add(self::TODAY, $k);
            $this->setDay($e, $m, $d, ['manha' => 2, 'tarde' => 2, 'noite' => 1], ['bed_time' => '02:30', 'wake_time' => '06:30', 'racing_thoughts' => 2, 'fast_speech' => 2, 'impulsive_spending' => 2, 'caffeine_cups' => 6], ['energy' => 3]);
        }
        $media[self::TODAY] = 6;
        $a = $this->ev($e, $m, $media);
        $r = $this->rules($a);
        $this->assertArrayHasKey('mania', $r);
        $this->assertGreaterThanOrEqual(2, $r['mania']);
        $mania = array_values(array_filter($a, function (array $x): bool { return $x['rule'] === 'mania'; }))[0];
        $this->assertStringContainsString('dormiu 4,0 h', $mania['explanation'], 'explica em pt-BR com o dado');
        $this->assertStringContainsString('seu habitual', $mania['explanation']);
        $this->assertStringContainsString('não é diagnóstico', $mania['explanation']);
        $this->assertNotEmpty(array_filter($mania['evidence'], function (string $s): bool { return strpos($s, 'cafeína') !== false; }), 'cafeína acima do habitual');
        $this->assertNotEmpty(array_filter($mania['evidence'], function (string $s): bool { return strpos($s, 'notas em áudio') !== false; }), 'frequência de mídia');
        $this->assertArrayNotHasKey('depressao', $r);
    }
    public function testSingleShortNightIsNotMania(): void
    {
        [$e, $m, $media] = $this->base();
        $this->setDay($e, $m, self::TODAY, ['manha' => 0], ['bed_time' => '02:00', 'wake_time' => '07:00']);
        $this->assertArrayNotHasKey('mania', $this->rules($this->ev($e, $m, $media)));
    }
    public function testTuningMakesRuleLessSensitive(): void
    {
        [$e, $m, $media] = $this->base();
        foreach ([-1, 0] as $k) $this->setDay($e, $m, sig_add(self::TODAY, $k), ['manha' => 2, 'tarde' => 1], ['bed_time' => '01:30', 'wake_time' => '06:30']);
        $this->assertArrayHasKey('mania', $this->rules($this->ev($e, $m, $media)));
        $this->assertArrayNotHasKey('mania', $this->rules($this->ev($e, $m, $media, ['mania' => -2])), '"não faz sentido" duas vezes sobe o limiar');
        $this->assertSame(-1, alert_feedback_tune(0, false));
        $this->assertSame(-2, alert_feedback_tune(-2, false));
        $this->assertSame(1, alert_feedback_tune(1, true));
    }
    public function testDepression(): void
    {
        [$e, $m, $media] = $this->base();
        for ($k = -6; $k <= 0; $k++) $this->setDay($e, $m, sig_add(self::TODAY, $k), ['manha' => -2, 'tarde' => -1, 'noite' => -2], ['bed_time' => '21:00', 'wake_time' => '10:00', 'sun_minutes' => 0], ['energy' => 0]);
        $a = $this->ev($e, $m, $media);
        $r = $this->rules($a);
        $this->assertSame(2, $r['depressao']);
        $dep = array_values(array_filter($a, function (array $x): bool { return $x['rule'] === 'depressao'; }))[0];
        $this->assertStringContainsString('7 dos últimos 7 dias', $dep['explanation']);
        $this->assertStringContainsString('sol', $dep['explanation']);
        $this->assertFalse($dep['crisis']);
        // −3 sempre eleva para nível 3 e mostra recursos de crise
        $this->setDay($e, $m, self::TODAY, ['manha' => -3]);
        $dep = array_values(array_filter($this->ev($e, $m, $media), function (array $x): bool { return $x['rule'] === 'depressao'; }))[0];
        $this->assertSame(3, $dep['severity']);
        $this->assertTrue($dep['crisis']);
        $this->assertStringContainsString('CVV 188', $dep['explanation']);
    }
    public function testMixedState(): void
    {
        [$e, $m, $media] = $this->base();
        foreach ([-1, 0] as $k) $this->setDay($e, $m, sig_add(self::TODAY, $k), ['manha' => -2, 'tarde' => -1], ['racing_thoughts' => 3, 'irritability_day' => 3], ['irritability' => 3]);
        $r = $this->rules($this->ev($e, $m, $media));
        $this->assertArrayHasKey('misto', $r);
        $this->assertSame(2, $r['misto']);
    }
    public function testWithinDaySwingWithIrritabilityIsMixed(): void
    {
        [$e, $m, $media] = $this->base();
        $this->setDay($e, $m, self::TODAY, ['manha' => 2, 'tarde' => -1, 'noite' => -1], ['irritability_day' => 2]);
        $this->assertArrayHasKey('misto', $this->rules($this->ev($e, $m, $media)));
    }
    public function testRapidCycling(): void
    {
        [$e, $m, $media] = $this->base();
        for ($k = -13; $k <= 0; $k++) {
            $v = intdiv($k + 13, 2) % 2 === 0 ? 2 : -2;
            $this->setDay($e, $m, sig_add(self::TODAY, $k), ['manha' => $v, 'tarde' => $v, 'noite' => (int)($v / 2)]);
        }
        $a = $this->ev($e, $m, $media);
        $c = array_values(array_filter($a, function (array $x): bool { return $x['rule'] === 'ciclagem'; }));
        $this->assertCount(1, $c);
        $this->assertSame(2, $c[0]['severity'], 'viradas + MSSD');
        $this->assertStringContainsString('viradas', $c[0]['explanation']);
        $this->assertStringContainsString('MSSD', $c[0]['explanation']);
    }
    public function testLoggingGap(): void
    {
        [$e, $m, $media] = $this->base(35, 4); // nada nos últimos 4 dias (nem hoje)
        $r = $this->rules($this->ev($e, $m, $media));
        $this->assertSame(1, $r['lacuna']);
        [$e, $m, $media] = $this->base(35, 8);
        $this->assertSame(2, $this->rules($this->ev($e, $m, $media))['lacuna']);
        // quem nunca registrou com regularidade não recebe alerta de lacuna
        $sparse = array_values(array_filter($e, function (array $x): bool { return (int)substr($x['entry_date'], 8, 2) % 4 === 0; }));
        $this->assertArrayNotHasKey('lacuna', $this->rules($this->ev($sparse, [], [])));
    }
    public function testMedicationAdherence(): void
    {
        [$e, $m, $media] = $this->base();
        $this->setDay($e, $m, sig_add(self::TODAY, -1), ['manha' => 0], ['doses_json' => '{"m1":0,"m2":1}']);
        $this->setDay($e, $m, self::TODAY, ['manha' => 0], ['doses_json' => '{"m1":0,"m2":0}']);
        $a = array_values(array_filter($this->ev($e, $m, $media), function (array $x): bool { return $x['rule'] === 'adesao'; }));
        $this->assertSame(1, $a[0]['severity']);
        $this->assertStringContainsString('3 doses marcadas', $a[0]['explanation']);
    }
    public function testSubstancesAboveUsualAndStimulants(): void
    {
        [$e, $m, $media] = $this->base();
        $sub = [];
        for ($i = 20; $i >= 1; $i--) $sub[] = ['entry_date' => sig_add(self::TODAY, -$i), 'substance' => 'cannabis', 'amount' => 1, 'above_usual' => 0];
        $sub[] = ['entry_date' => self::TODAY, 'substance' => 'cannabis', 'amount' => 5, 'above_usual' => 1];
        $a = array_values(array_filter($this->ev($e, $m, $media, [], [], $sub), function (array $x): bool { return $x['rule'] === 'substancias'; }));
        $this->assertStringContainsString('cannabis bem acima do seu habitual', $a[0]['explanation']);
        $sub[] = ['entry_date' => self::TODAY, 'substance' => 'cocaina', 'amount' => 1, 'above_usual' => 0];
        $this->setDay($e, $m, self::TODAY, ['manha' => 1], ['bed_time' => '03:00', 'wake_time' => '06:00']);
        $all = $this->ev($e, $m, $media, [], [], $sub);
        $s = array_values(array_filter($all, function (array $x): bool { return $x['rule'] === 'substancias'; }))[0];
        $this->assertSame(2, $s['severity'], 'estimulante + sono reduzido');
        $this->assertStringContainsString('estimulante', $s['explanation']);
    }
    public function testPersonalSignsAndContextAnnotations(): void
    {
        [$e, $m, $media] = $this->base();
        $this->setDay($e, $m, self::TODAY, ['manha' => 2, 'tarde' => 2], ['signs_json' => '[0,1,2]', 'bed_time' => '02:00', 'wake_time' => '06:00', 'cycle_phase' => 'pre_menstrual', 'stress_level' => 3]);
        $a = $this->ev($e, $m, $media);
        $r = $this->rules($a);
        $this->assertSame(2, $r['pessoal']);
        $mania = array_values(array_filter($a, function (array $x): bool { return $x['rule'] === 'mania'; }))[0];
        $this->assertStringContainsString('Contexto:', $mania['explanation']);
        $this->assertStringContainsString('pré-menstrual', $mania['explanation']);
        $this->assertStringContainsString('estresse intenso', $mania['explanation']);
    }
    public function testSexualityFeedsManiaOnlyWhenProvided(): void
    {
        [$e, $m, $media] = $this->base();
        foreach ([-1, 0] as $k) $this->setDay($e, $m, sig_add(self::TODAY, $k), ['manha' => 1, 'tarde' => 1], ['bed_time' => '01:30', 'wake_time' => '06:30']);
        $sex = [['entry_date' => self::TODAY, 'libido' => 2, 'activities' => 'nova_parceria,sem_protecao']];
        $with = $this->ev($e, $m, $media, [], $sex);
        $mania = array_values(array_filter($with, function (array $x): bool { return $x['rule'] === 'mania'; }))[0];
        $this->assertStringContainsString('sexual', $mania['explanation']);
        $without = array_values(array_filter($this->ev($e, $m, $media), function (array $x): bool { return $x['rule'] === 'mania'; }));
        $this->assertTrue(!$without || strpos($without[0]['explanation'], 'sexual') === false);
    }

    public function testIndicatorAlertaAlwaysHasMatchingAlert(): void
    {
        $x = fn (string $lvl, array $r) => ['score' => 5, 'level' => $lvl, 'reasons' => $r, 'sleep' => 4.0, 'triad' => false];
        $ind = ['2026-10-13' => $x('atencao', ['dormiu pouco']), '2026-10-14' => $x('alerta', ['dormiu pouco', 'energia intensa'])];
        $out = signals_merge_indicator([], $ind, '2026-10-14');
        $this->assertCount(1, $out);
        $this->assertSame('mania', $out[0]['rule']);
        $this->assertSame(2, $out[0]['severity']);
        // motor já gerou (hipo)mania: não duplica
        $this->assertCount(1, signals_merge_indicator($out, $ind, '2026-10-14'));
        // indicador tranquilo: nada
        $this->assertSame([], signals_merge_indicator([], ['2026-10-14' => $x('ok', [])], '2026-10-14'));
    }
}
