<?php
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    private function ok(array $in): array { [$err, $v] = validate_entry($in); $this->assertNull($err); return $v; }
    private function err(array $in): string { [$err] = validate_entry($in); $this->assertNotNull($err); return (string)$err; }
    private array $base = ['entry_date' => '2026-10-14', 'period' => 'tarde', 'mood' => 1];

    public function testValidMinimal(): void
    {
        $v = $this->ok($this->base);
        $this->assertSame(1, $v['mood']);
        $this->assertNull($v['sleep_hours']);
        $this->assertNull($v['medication_taken']);
        $this->assertNull($v['note']);
    }
    public function testRejects(): void
    {
        $this->err(['period' => 'madrugada'] + $this->base);
        $this->err(['period' => ['manha']] + $this->base);
        $this->err(['entry_date' => '2026-02-30'] + $this->base);
        $this->err(['entry_date' => "2026-10-14' OR 1=1 --"] + $this->base);
        $this->err(['mood' => 4] + $this->base);
        $this->err(['mood' => -4] + $this->base);
        $this->err(['mood' => 1.5] + $this->base);
        $this->err(['mood' => true] + $this->base);
        $this->err(['mood' => 'abc'] + $this->base);
        $this->err(['mood' => null] + $this->base);
    }
    public function testNormalization(): void
    {
        $v = $this->ok(['sleep_hours' => '30', 'irritability' => 5, 'anxiety' => '2', 'energy' => -1, 'medication_taken' => '0',
            'tags' => ' a , ,b,' . str_repeat('x,', 40), 'note' => '  oi  ', 'client_uuid' => 'not-a-uuid'] + $this->base);
        $this->assertSame(24.0, $v['sleep_hours']);
        $this->assertNull($v['irritability']);
        $this->assertSame(2, $v['anxiety']);
        $this->assertNull($v['energy']);
        $this->assertSame(0, $v['medication_taken']);
        $this->assertSame(20, count(explode(', ', (string)$v['tags'])));
        $this->assertStringStartsWith('a, b, x', (string)$v['tags']);
        $this->assertSame('oi', $v['note']);
        $this->assertNull($v['client_uuid']);
        $this->assertSame(5000, mb_strlen((string)$this->ok(['note' => str_repeat('é', 6000)] + $this->base)['note']));
        $this->assertSame('3f2504e0-4f89-41d3-9a0c-0305e82c3301', $this->ok(['client_uuid' => '3F2504E0-4F89-41D3-9A0C-0305E82C3301'] + $this->base)['client_uuid']);
    }
    public function testReminderValidation(): void
    {
        [$e, $v] = validate_reminder(['time_local' => '21:00', 'days' => [6, 1, '1', 9, 'x'], 'channel' => 'ambos']);
        $this->assertNull($e);
        $this->assertSame('1,6', $v['days']);
        $this->assertSame('ambos', $v['channel']);
        $this->assertNotNull(validate_reminder(['time_local' => '24:00', 'days' => [1]])[0]);
        $this->assertNotNull(validate_reminder(['time_local' => '08:00', 'days' => []])[0]);
        $this->assertSame('email', validate_reminder(['time_local' => '08:00', 'days' => [1], 'channel' => 'sms'])[1]['channel'], 'padrão é e-mail');
        $this->assertSame('email', validate_reminder(['time_local' => '08:00', 'days' => [1]], false)[1]['channel']);
        foreach (['push', 'ambos'] as $ch) $this->assertNotNull(validate_reminder(['time_local' => '08:00', 'days' => [1], 'channel' => $ch], false)[0], "sem VAPID, '$ch' é recusado");
    }
}
