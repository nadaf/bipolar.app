<?php
/** Calcula o próximo envio (UTC) de um destinatário, às 08:00 no fuso do usuário. */
/** @param array<string,mixed> $r */
function next_send_at(array $r, string $tz, ?DateTimeImmutable $after = null): string {
    $z = new DateTimeZone($tz);
    $now = ($after ?: new DateTimeImmutable('now'))->setTimezone($z);
    $base = $now->setTime(8, 0);
    switch ($r['frequency']) {
        case 'diaria': $n = $base <= $now ? $base->modify('+1 day') : $base; break;
        case 'semanal':
            $wd = (int)($r['weekday'] ?? 1);
            $n = $base;
            for ($i = 0; $i < 8; $i++) { if ((int)$n->format('w') === $wd && $n > $now) break; $n = $n->modify('+1 day'); }
            break;
        case 'mensal': $n = $now->modify('first day of next month')->setTime(8, 0); break;
        default: $n = $base->modify('+' . max(1, (int)($r['interval_days'] ?? 7)) . ' days');
    }
    return $n->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
/** @param array<string,mixed> $r */
function report_period_days(array $r): int {
    switch ($r['frequency']) {
        case 'diaria': return 1;
        case 'semanal': return 7;
        case 'mensal': return 31;
        default: return max(1, (int)$r['interval_days']);
    }
}
