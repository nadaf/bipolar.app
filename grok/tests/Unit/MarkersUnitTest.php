<?php
use PHPUnit\Framework\TestCase;

/** Marcadores diários, sexualidade e indicador de (hipo)mania (funções puras). */
final class MarkersUnitTest extends TestCase
{
    public function testSleepHoursCrossMidnight(): void
    {
        $this->assertSame(7.5, sleep_hours_from_times('23:30', '07:00'));
        $this->assertSame(3.0, sleep_hours_from_times('02:00', '05:00'));
        $this->assertNull(sleep_hours_from_times(null, '07:00'));
    }

    public function testValidateDayKeepsOnlySentFieldsAndClamps(): void
    {
        [$err, $v] = validate_day(['entry_date' => '2026-10-10', 'bed_time' => '23:15', 'racing_thoughts' => 9, 'sun_period' => 'meio_dia', 'doses' => ['m1ab' => 1, 'x y' => 1, 'm2' => '0'], 'signs' => [0, 3, 3, 99]]);
        $this->assertNull($err);
        $this->assertSame('23:15', $v['bed_time']);
        $this->assertNull($v['racing_thoughts'], 'fora da escala vira nulo');
        $this->assertSame('{"m1ab":1,"m2":0}', $v['doses_json']);
        $this->assertSame('[0,3]', $v['signs_json']);
        $this->assertArrayNotHasKey('wake_time', $v, 'campo não enviado não é tocado');
        $this->assertSame('Informe uma data válida.', validate_day(['entry_date' => '2026-02-30'])[0]);
        $this->assertNull(validate_day(['entry_date' => '2026-10-10', 'bed_time' => '25:00'])[1]['bed_time']);
    }

    public function testValidateSexuality(): void
    {
        [, $v] = validate_sexuality(['entry_date' => '2026-10-10', 'libido' => 3, 'activities' => ['nenhuma', 'online', 'hack', 'nova_parceria'], 'online_minutes' => 90, 'different_from_usual' => '1', 'note' => '  ']);
        $this->assertNull($v['libido'], 'escala −2..+2');
        $this->assertSame('online,nova_parceria', $v['activities'], '“sem atividade” sai quando há outras; códigos inválidos ignorados');
        $this->assertSame(90, $v['online_minutes']);
        $this->assertSame(1, $v['different_from_usual']);
        $this->assertNull($v['note']);
        [, $v] = validate_sexuality(['entry_date' => '2026-10-10', 'activities' => ['masturbacao'], 'online_minutes' => 90]);
        $this->assertNull($v['online_minutes'], 'tempo online só com a atividade online');
    }

    public function testPrefsDefaultsAndNormalization(): void
    {
        $p = user_prefs([]);
        $this->assertFalse($p['modules']['sexualidade'], 'Sexualidade desligado por padrão');
        $this->assertTrue($p['modules']['sono']);
        $this->assertTrue($p['sex_blur']);
        $n = normalize_prefs(['modules' => ['sexualidade' => true], 'signs' => array_fill(0, 15, 'x'), 'meds' => [['name' => 'Lítio', 'time' => '21:00', 'dose' => '300 mg'], ['name' => 'Ruim', 'time' => '9h'], ['name' => 'Quetiapina', 'time' => '08:00', 'id' => 'abc']]]);
        $this->assertTrue($n['modules']['sexualidade']);
        $this->assertFalse($n['modules']['sono'], 'módulo não marcado = desligado');
        $this->assertCount(10, $n['signs']);
        $this->assertCount(2, $n['meds']);
        $this->assertSame('Quetiapina', $n['meds'][0]['name'], 'ordenado por horário');
        $this->assertSame('abc', $n['meds'][0]['id'], 'id existente preservado');
    }

    /** @return list<array<string,mixed>> */
    private function baselineNights(string $until, int $n): array
    {
        $m = [];
        for ($i = $n; $i >= 1; $i--) $m[] = ['entry_date' => date('Y-m-d', (int)strtotime("$until -$i days")), 'bed_time' => '23:00', 'wake_time' => '07:00'];
        return $m;
    }

    public function testTriadAndAlertLevel(): void
    {
        $markers = $this->baselineNights('2026-10-10', 10);
        $markers[] = ['entry_date' => '2026-10-10', 'bed_time' => '02:30', 'wake_time' => '06:00', 'racing_thoughts' => 2];
        $entries = [['entry_date' => '2026-10-10', 'mood' => 2, 'sleep_hours' => null, 'energy' => 3, 'irritability' => null]];
        $sex = [['entry_date' => '2026-10-10', 'libido' => 2, 'activities' => 'nova_parceria,sem_protecao', 'different_from_usual' => 1]];
        $ind = mania_indicators($entries, $markers, $sex);
        $d = $ind['2026-10-10'];
        $this->assertTrue($d['triad']);
        $this->assertSame('alerta', $d['level']);
        $this->assertSame(3.5, $d['sleep']);
        $this->assertContains('dormiu bem menos que o habitual', $d['reasons']);
        $this->assertSame('ok', $ind['2026-10-05']['level'], 'noites habituais sem sinais');
        $msg = early_warning($ind, '2026-10-10');
        $this->assertNotNull($msg);
        $this->assertStringContainsString('não é um diagnóstico', (string)$msg);
        // sem o módulo de sexualidade, o mesmo dia ainda pontua, mas sem tríade
        $ind2 = mania_indicators($entries, $markers, []);
        $this->assertFalse($ind2['2026-10-10']['triad']);
        $this->assertNotContains('desejo sexual muito acima do habitual', $ind2['2026-10-10']['reasons']);
    }

    public function testPersonalBaselineAvoidsFalseAlarmForShortSleepers(): void
    {
        $m = [];
        for ($i = 10; $i >= 1; $i--) $m[] = ['entry_date' => date('Y-m-d', (int)strtotime("2026-10-10 -$i days")), 'bed_time' => '01:00', 'wake_time' => '06:30'];
        $m[] = ['entry_date' => '2026-10-10', 'bed_time' => '01:00', 'wake_time' => '06:30'];
        $ind = mania_indicators([], $m, []);
        $this->assertSame(0, $ind['2026-10-10']['score'], '5,5 h é o normal desta pessoa');
        $this->assertNull(early_warning($ind, '2026-10-10'));
    }

    public function testDepressionDoesNotTriggerManiaWarning(): void
    {
        $e = [];
        foreach (['2026-10-08', '2026-10-09', '2026-10-10'] as $d) $e[] = ['entry_date' => $d, 'mood' => -3, 'sleep_hours' => 11, 'energy' => 0, 'irritability' => 1];
        $ind = mania_indicators($e, [], [['entry_date' => '2026-10-10', 'libido' => -2, 'activities' => 'nenhuma', 'different_from_usual' => 1]]);
        foreach ($ind as $x) $this->assertSame('ok', $x['level']);
        $this->assertNull(early_warning($ind, '2026-10-10'));
    }
}
