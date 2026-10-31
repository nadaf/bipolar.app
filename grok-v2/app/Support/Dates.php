<?php

namespace App\Support;

use App\Domain\Mood\Period;
use Carbon\CarbonImmutable;

/** Datas em pt-BR para a interface (sem depender de locale do servidor). */
final class Dates
{
    public const WEEK = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];

    public const MONTHS = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    public static function today(string $tz): CarbonImmutable
    {
        return CarbonImmutable::now($tz ?: 'America/Sao_Paulo')->startOfDay();
    }

    /** "de hoje (31/10)", "de ontem (30/10)", "de sexta (24/10)" */
    public static function relative(string $date, string $tz): string
    {
        $d = CarbonImmutable::parse($date);
        $diff = (int) self::today($tz)->diffInDays($d->startOfDay(), false);
        $dm = $d->format('d/m');

        return match (true) {
            $diff === 0 => "de hoje ($dm)",
            $diff === -1 => "de ontem ($dm)",
            $diff > -7 && $diff < 0 => 'de '.self::WEEK[$d->dayOfWeek]." ($dm)",
            default => "de $dm/".$d->format('Y'),
        };
    }

    public static function long(string $date): string
    {
        $d = CarbonImmutable::parse($date);

        return self::WEEK[$d->dayOfWeek].', '.$d->day.' de '.self::MONTHS[$d->month].' de '.$d->year;
    }

    public static function lockLabel(string $date, Period $p, string $tz): string
    {
        return $p->label().' '.self::relative($date, $tz);
    }

    public static function valid(?string $d): bool
    {
        return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1
            && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    }
}
