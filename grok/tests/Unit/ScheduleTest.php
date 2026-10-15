<?php
use PHPUnit\Framework\TestCase;

final class ScheduleTest extends TestCase
{
    private function at(string $utc): DateTimeImmutable { return new DateTimeImmutable($utc, new DateTimeZone('UTC')); }

    public function testDailyBefore8LocalIsSameDay(): void
    {
        // 07:00 em Cuiabá (UTC-4) = 11:00 UTC → envio 08:00 local = 12:00 UTC no mesmo dia
        $this->assertSame('2026-10-14 12:00:00', next_send_at(['frequency' => 'diaria'], 'America/Cuiaba', $this->at('2026-10-14 11:00:00')));
    }
    public function testDailyAfter8LocalIsNextDay(): void
    {
        $this->assertSame('2026-10-15 12:00:00', next_send_at(['frequency' => 'diaria'], 'America/Cuiaba', $this->at('2026-10-14 20:00:00')));
    }
    public function testWeeklyPicksNextWeekday(): void
    {
        // quarta 14/10/2026 → próxima segunda (1) = 19/10
        $this->assertSame('2026-10-19 12:00:00', next_send_at(['frequency' => 'semanal', 'weekday' => 1], 'America/Cuiaba', $this->at('2026-10-14 20:00:00')));
        // mesma quarta, antes das 08:00 local, weekday=3 → hoje
        $this->assertSame('2026-10-14 12:00:00', next_send_at(['frequency' => 'semanal', 'weekday' => 3], 'America/Cuiaba', $this->at('2026-10-14 10:00:00')));
        // depois das 08:00 → próxima semana
        $this->assertSame('2026-10-21 12:00:00', next_send_at(['frequency' => 'semanal', 'weekday' => 3], 'America/Cuiaba', $this->at('2026-10-14 13:00:00')));
    }
    public function testMonthlyFirstDayNextMonth(): void
    {
        $this->assertSame('2026-11-01 12:00:00', next_send_at(['frequency' => 'mensal'], 'America/Cuiaba', $this->at('2026-10-31 23:00:00')));
        $this->assertSame('2027-01-01 11:00:00', next_send_at(['frequency' => 'mensal'], 'America/Sao_Paulo', $this->at('2026-12-15 10:00:00')));
    }
    public function testCustomInterval(): void
    {
        $this->assertSame('2026-10-24 12:00:00', next_send_at(['frequency' => 'personalizada', 'interval_days' => 10], 'America/Cuiaba', $this->at('2026-10-14 20:00:00')));
        $this->assertSame('2026-10-15 12:00:00', next_send_at(['frequency' => 'personalizada', 'interval_days' => 0], 'America/Cuiaba', $this->at('2026-10-14 20:00:00')), 'mínimo 1 dia');
    }
    public function testOtherTimezone(): void
    {
        $this->assertSame('2026-10-15 07:00:00', next_send_at(['frequency' => 'diaria'], 'Europe/Lisbon', $this->at('2026-10-14 20:00:00')));
    }
    public function testReportPeriodDays(): void
    {
        $this->assertSame(1, report_period_days(['frequency' => 'diaria']));
        $this->assertSame(7, report_period_days(['frequency' => 'semanal']));
        $this->assertSame(31, report_period_days(['frequency' => 'mensal']));
        $this->assertSame(15, report_period_days(['frequency' => 'personalizada', 'interval_days' => 15]));
    }
}
