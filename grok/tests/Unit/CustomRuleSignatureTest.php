<?php
use PHPUnit\Framework\TestCase;

/** V4.3: assinatura de regras para bloquear duplicatas. */
final class CustomRuleSignatureTest extends TestCase
{
    public function testSignatureIgnoresNameOrderAndChannels(): void
    {
        require_once APP_ROOT . '/lib/custom_alerts.php';
        $a = ['name' => 'A', 'logic' => 'and', 'severity' => 1, 'conditions' => [['metric' => 'sleep_hours', 'op' => '<', 'value' => 5, 'mode' => 'day', 'days' => 1, 'min' => 1], ['metric' => 'mood_max', 'op' => '>=', 'value' => 2.0, 'mode' => 'day', 'days' => 1, 'min' => 1]]];
        $b = ['name' => 'B', 'logic' => 'and', 'severity' => 2, 'notify_push' => true, 'conditions' => array_reverse($a['conditions'])];
        $this->assertSame(custom_rule_signature($a), custom_rule_signature($b));
        $c = $b; $c['logic'] = 'or';
        $this->assertNotSame(custom_rule_signature($a), custom_rule_signature($c), 'E × OU');
        $one = ['logic' => 'or', 'conditions' => [$a['conditions'][0]]];
        $this->assertSame(custom_rule_signature(['logic' => 'and'] + $one), custom_rule_signature($one), 'uma condição: E/OU não importa');
        $this->assertSame(['id' => 7] + $a, custom_find_duplicate([['id' => 7] + $a], $b));
        $this->assertNull(custom_find_duplicate([['id' => 7] + $a], $b, 7), 'editar a própria regra');
    }
}
