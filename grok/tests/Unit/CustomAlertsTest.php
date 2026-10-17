<?php
use PHPUnit\Framework\TestCase;

/** V4.2: motor dos alertas personalizados (validação, máscara privada, modos dia/contagem/sequência, E/OU, prévia e intervalo). */
final class CustomAlertsTest extends TestCase
{
    private const TODAY = '2026-10-14';

    public static function setUpBeforeClass(): void
    {
        require_once TestEnv::ROOT . '/app/lib/custom_alerts.php';
    }

    /** 20 dias: humor 0 e sono 7,5 h; nos dias listados, humor e horário de deitar personalizados. */
    private function data(array $moods = [], array $bed = [], bool $logToday = true, array $subst = []): array
    {
        $e = []; $m = [];
        for ($i = 19; $i >= ($logToday ? 0 : 1); $i--) {
            $d = sig_add(self::TODAY, -$i);
            $v = $moods[$d] ?? 0;
            foreach (['manha', 'tarde', 'noite'] as $p) $e[] = ['entry_date' => $d, 'period' => $p, 'mood' => $v, 'sleep_hours' => null, 'energy' => 1, 'irritability' => 0, 'anxiety' => 0, 'medication_taken' => 1];
            $m[] = ['entry_date' => $d, 'bed_time' => $bed[$d] ?? '23:30', 'wake_time' => '07:00', 'caffeine_cups' => null, 'racing_thoughts' => 0, 'fast_speech' => 0, 'impulsive_spending' => 0, 'irritability_day' => 0, 'stress_level' => 1];
        }
        return custom_daily($e, $m, [], $subst, []);
    }
    private function rule(array $conds, string $logic = 'and', int $cool = 3): array
    {
        [$err, $r] = custom_rule_validate(['name' => 'Teste', 'logic' => $logic, 'conditions' => $conds, 'cooldown_days' => $cool], ['sexualidade' => true, 'substancias' => true]);
        $this->assertNull($err, (string)$err);
        return $r;
    }

    public function testValidationMessagesInPortuguese(): void
    {
        $this->assertSame('Dê um nome para o alerta.', custom_rule_validate(['name' => ' ', 'conditions' => []])[0]);
        $this->assertSame('Adicione pelo menos uma condição.', custom_rule_validate(['name' => 'x', 'conditions' => []])[0]);
        $this->assertStringContainsString('escolha o que medir', (string)custom_rule_validate(['name' => 'x', 'conditions' => [['metric' => 'nada', 'op' => '>=', 'value' => 1]]])[0]);
        $this->assertStringContainsString('entre', (string)custom_rule_validate(['name' => 'x', 'conditions' => [['metric' => 'mood_max', 'op' => '>=', 'value' => 9]]])[0]);
        $this->assertStringContainsString('ative o módulo Sexualidade', (string)custom_rule_validate(['name' => 'x', 'conditions' => [['metric' => 'libido', 'op' => '>=', 'value' => 1]]])[0]);
        $this->assertStringContainsString('ative o módulo Substâncias', (string)custom_rule_validate(['name' => 'x', 'conditions' => [['metric' => 'sub_cafe', 'op' => '>=', 'value' => 1]]])[0]);
        $this->assertStringContainsString('de 2 a 30 dias', (string)custom_rule_validate(['name' => 'x', 'conditions' => [['metric' => 'sleep', 'op' => '<', 'value' => 5, 'mode' => 'count', 'days' => 40, 'min' => 2]]])[0]);
        [$err, $r] = custom_rule_validate(['name' => 'Vírgula', 'conditions' => [['metric' => 'sleep', 'op' => '<', 'value' => '5,5']], 'cooldown_days' => 99, 'severity' => 7]);
        $this->assertNull($err);
        $this->assertSame(5.5, $r['conditions'][0]['value']);
        $this->assertSame(14, $r['cooldown_days']);
        $this->assertSame(1, $r['severity']);
        $this->assertFalse($r['notify_push']);
    }

    public function testPrivateMaskAndTemplates(): void
    {
        $this->assertSame(0, custom_private_mask([['metric' => 'sleep']]));
        $this->assertSame(CUSTOM_PRIV_SEX | CUSTOM_PRIV_SUBST, custom_private_mask([['metric' => 'libido'], ['metric' => 'sub_alcool']]));
        $this->assertArrayHasKey('sub_cafe', custom_metrics());
        foreach (custom_templates() as $k => $t) {
            [$err, $r] = custom_rule_validate($t + ['template' => $k], ['sexualidade' => true, 'substancias' => true, 'pensamentos_morte' => true]);
            $this->assertNull($err, "modelo $k: $err");
            $this->assertSame($k, $r['template']);
        }
        $this->assertSame(CUSTOM_PRIV_SEX, custom_rule_validate(custom_templates()['humor_alto_desejo'], ['sexualidade' => true])[1]['is_private']);
    }

    public function testDescriptionIsReadablePtBr(): void
    {
        $r = $this->rule([['metric' => 'sleep', 'op' => '<', 'value' => 5, 'mode' => 'count', 'days' => 7, 'min' => 3], ['metric' => 'mood_max', 'op' => '>=', 'value' => 2]]);
        $this->assertSame('horas de sono < 5 h em pelo menos 3 dias dos últimos 7 E humor mais alto do dia ≥ +2', custom_describe_rule($r));
        $this->assertSame('não registrou o humor no dia', custom_describe_condition(['metric' => 'logged', 'op' => '=', 'value' => 0, 'mode' => 'day', 'days' => 1, 'min' => 1]));
    }

    public function testDayCountAndStreakModes(): void
    {
        $t = self::TODAY;
        $D = $this->data([sig_add($t, -4) => 2, sig_add($t, -2) => 2, sig_add($t, -1) => 2, $t => 2]);
        $day = $this->rule([['metric' => 'mood_max', 'op' => '>=', 'value' => 2]]);
        $this->assertTrue(custom_rule_on($D, $day, $t)[0]);
        $this->assertFalse(custom_rule_on($D, $day, sig_add($t, -3))[0]);
        $count = $this->rule([['metric' => 'mood_max', 'op' => '>=', 'value' => 2, 'mode' => 'count', 'days' => 5, 'min' => 4]]);
        $this->assertTrue(custom_rule_on($D, $count, $t)[0], '4 dos últimos 5');
        $this->assertFalse(custom_rule_on($D, $count, sig_add($t, -1))[0]);
        $streak = $this->rule([['metric' => 'mood_max', 'op' => '>=', 'value' => 2, 'mode' => 'streak', 'days' => 3]]);
        $this->assertTrue(custom_rule_on($D, $streak, $t)[0]);
        $streak4 = $this->rule([['metric' => 'mood_max', 'op' => '>=', 'value' => 2, 'mode' => 'streak', 'days' => 4]]);
        $this->assertFalse(custom_rule_on($D, $streak4, $t)[0], 'dia −3 quebra a sequência');
        [, $ev] = custom_rule_on($D, $streak, $t);
        $this->assertStringContainsString('por 3 dias seguidos', $ev[0]);
    }

    public function testAndOrLogicWithSleep(): void
    {
        $t = self::TODAY;
        $D = $this->data([$t => 2], [$t => '03:00']); // dormiu 4 h hoje
        $sleep = ['metric' => 'sleep', 'op' => '<', 'value' => 5];
        $low = ['metric' => 'mood_min', 'op' => '<=', 'value' => -2];
        $this->assertFalse(custom_rule_on($D, $this->rule([$sleep, $low]), $t)[0]);
        [$ok, $ev] = custom_rule_on($D, $this->rule([$sleep, $low], 'or'), $t);
        $this->assertTrue($ok);
        $this->assertCount(1, $ev);
        $this->assertTrue(custom_rule_on($D, $this->rule([$sleep, ['metric' => 'mood_max', 'op' => '>=', 'value' => 2]]), $t)[0]);
    }

    public function testLoggedTodayIsUnknownUntilSomethingIsSaved(): void
    {
        $t = self::TODAY;
        $D = $this->data([], [], false);
        $this->assertNull(custom_value($D, 'logged', $t, $t), 'o dia de hoje ainda não terminou');
        $this->assertSame(0.0, custom_value($D, 'logged', $t));
        $this->assertSame(1.0, custom_value($D, 'logged', sig_add($t, -1), $t));
        $this->assertSame(0.0, custom_value($D, 'logged', sig_add($t, -30), $t));
        $r = $this->rule([['metric' => 'logged', 'op' => '=', 'value' => 0]]);
        $this->assertFalse(custom_rule_on($D, $r, $t, $t)[0]);
    }

    public function testPreviewRespectsCooldownAndCanFire(): void
    {
        $t = self::TODAY;
        $moods = [];
        for ($i = 0; $i <= 5; $i++) $moods[sig_add($t, -$i)] = -2;
        $D = $this->data($moods);
        $r = $this->rule([['metric' => 'mood_min', 'op' => '<=', 'value' => -2]], 'and', 3);
        $p = custom_preview($D, $r, sig_add($t, -19), $t);
        $this->assertCount(6, $p['matched']);
        $this->assertSame([sig_add($t, -5), sig_add($t, -2)], $p['fired']);
        $this->assertTrue(custom_can_fire([], $t, 3));
        $this->assertFalse(custom_can_fire([$t], $t, 3), 'um por dia');
        $this->assertFalse(custom_can_fire([sig_add($t, -2)], $t, 3));
        $this->assertTrue(custom_can_fire([sig_add($t, -3)], $t, 3));
        $this->assertFalse(custom_can_fire([sig_add($t, 1)], $t, 3));
    }

    public function testSubstanceQuantitiesAndCafeFeedCaffeine(): void
    {
        $t = self::TODAY;
        $D = $this->data([], [], true, [['entry_date' => $t, 'substance' => 'cafe', 'amount' => 3], ['entry_date' => $t, 'substance' => 'cafe', 'amount' => null]]);
        $this->assertSame(4.0, custom_value($D, 'sub_cafe', $t));
        $this->assertSame(4.0, custom_value($D, 'caffeine', $t));
        $this->assertSame(2.0, custom_value($D, 'subst_uses', $t));
        $this->assertSame(0.0, custom_value($D, 'sub_alcool', $t));
        $this->assertSame('R$ 12,50', custom_fmt_value('spend_amount', 12.5));
        $this->assertSame('intenso (3)', custom_fmt_value('racing', 3));
    }
}
