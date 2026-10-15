<?php
use PHPUnit\Framework\TestCase;

/** V3: módulo Substâncias (validação, favoritos, indicador, resumo de humor). */
final class SubstancesUnitTest extends TestCase
{
    public function testValidateSubstance(): void
    {
        [$err, $v] = validate_substance(['entry_date' => '2026-10-10', 'substance' => 'cannabis', 'form' => 'vaporizada', 'amount' => '1,5', 'period' => 'noite', 'reason' => 'dormir', 'mood_effect' => 1, 'above_usual' => 1, 'other_name' => 'x']);
        $this->assertNull($err);
        $this->assertSame('vaporizada', $v['form']);
        $this->assertNull($v['amount'], '“1,5” não é numérico no JSON — o app envia número');
        $this->assertNull($v['other_name'], 'nome livre só para “Outra”');
        $this->assertSame('dormir', $v['reason']);
        [, $v] = validate_substance(['entry_date' => '2026-10-10', 'substance' => 'alcool', 'form' => 'fumada', 'amount' => 3, 'period' => 'madrugada', 'mood_effect' => 5]);
        $this->assertNull($v['form'], 'forma de outra substância é ignorada');
        $this->assertSame(3.0, $v['amount']);
        $this->assertNull($v['period']);
        $this->assertNull($v['mood_effect']);
        $this->assertSame('Escolha a substância.', validate_substance(['entry_date' => '2026-10-10', 'substance' => 'heroina'])[0]);
        $this->assertStringContainsString('Qual?', (string)validate_substance(['entry_date' => '2026-10-10', 'substance' => 'outra', 'other_name' => ' '])[0]);
        $this->assertSame('kratom', validate_substance(['entry_date' => '2026-10-10', 'substance' => 'outra', 'other_name' => 'kratom'])[1]['other_name']);
    }

    public function testFavoritesAndDefaults(): void
    {
        $this->assertFalse(user_prefs([])['modules']['substancias'], 'desligado por padrão');
        $this->assertTrue(user_prefs([])['subst_blur']);
        $f = normalize_subst_favorites(array_merge(
            [['substance' => 'cannabis', 'form' => 'fumada', 'amount' => 1], ['substance' => 'cannabis', 'form' => 'fumada', 'amount' => 2], ['substance' => 'xx']],
            array_map(function ($i) { return ['substance' => 'outra', 'other_name' => "s$i"]; }, range(1, 10))
        ));
        $this->assertCount(6, $f, 'até 6, sem duplicados');
        $this->assertSame(['substance' => 'cannabis', 'form' => 'fumada', 'amount' => 1.0, 'other_name' => null], $f[0]);
    }

    public function testIndicatorSignals(): void
    {
        $base = [];
        for ($i = 10; $i >= 1; $i--) $base[] = ['entry_date' => date('Y-m-d', (int)strtotime("2026-10-10 -$i days")), 'bed_time' => '23:00', 'wake_time' => '07:00'];
        $short = array_merge($base, [['entry_date' => '2026-10-10', 'bed_time' => '01:30', 'wake_time' => '06:30']]);
        $normal = array_merge($base, [['entry_date' => '2026-10-10', 'bed_time' => '23:00', 'wake_time' => '07:00']]);
        $cann = [['entry_date' => '2026-10-10', 'substance' => 'cannabis', 'above_usual' => 1]];
        $a = mania_indicators([], $short, [], 0, $cann)['2026-10-10'];
        $this->assertContains('cannabis acima do habitual com sono reduzido', $a['reasons']);
        $b = mania_indicators([], $normal, [], 0, $cann)['2026-10-10'];
        $this->assertNotContains('cannabis acima do habitual com sono reduzido', $b['reasons'], 'sem sono reduzido, só “acima do habitual”');
        $this->assertSame(1, $b['score']);
        $c = mania_indicators([], $normal, [], 0, [['entry_date' => '2026-10-10', 'substance' => 'cannabis', 'above_usual' => 0]])['2026-10-10'];
        $this->assertSame(0, $c['score'], 'uso habitual de cannabis não pontua');
        $d = mania_indicators([], $normal, [], 0, [['entry_date' => '2026-10-10', 'substance' => 'psicodelicos', 'above_usual' => null], ['entry_date' => '2026-10-10', 'substance' => 'cocaina', 'above_usual' => null]])['2026-10-10'];
        $this->assertSame(2, $d['score'], 'estimulante/psicodélico soma 2 uma vez por dia');
        $this->assertSame(0, mania_indicators([], $normal, [], 0, [['entry_date' => '2026-10-10', 'substance' => 'alcool', 'above_usual' => 0]])['2026-10-10']['score']);
    }

    public function testMoodSummary(): void
    {
        $e = [];
        foreach (['2026-10-01' => 2, '2026-10-02' => 0, '2026-10-03' => 1, '2026-10-04' => -1] as $d => $m) $e[] = ['entry_date' => $d, 'mood' => $m];
        $s = [['entry_date' => '2026-10-01', 'substance' => 'cannabis', 'amount' => 1, 'above_usual' => 1, 'mood_effect' => 1],
              ['entry_date' => '2026-10-03', 'substance' => 'cannabis', 'amount' => 2, 'above_usual' => 0, 'mood_effect' => null]];
        $r = substance_mood_summary($s, $e)[0];
        $this->assertSame('cannabis', $r['substance']);
        $this->assertSame(2, $r['days']);
        $this->assertSame(3.0, $r['amount']);
        $this->assertSame(1.5, $r['mood_with']);
        $this->assertSame(-0.5, $r['mood_without']);
        $this->assertSame(-0.5, $r['mood_next']);
        $this->assertSame(1, $r['above_days']);
        $this->assertSame(1.0, $r['effect_avg']);
    }
}
