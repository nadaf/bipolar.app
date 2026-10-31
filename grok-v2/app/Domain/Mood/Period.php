<?php

namespace App\Domain\Mood;

enum Period: string
{
    case Manha = 'manha';
    case Tarde = 'tarde';
    case Noite = 'noite';

    public function label(): string
    {
        return match ($this) {
            self::Manha => 'Manhã',
            self::Tarde => 'Tarde',
            self::Noite => 'Noite',
        };
    }

    /** Hora local em que o periodo comeca (manha 00h, tarde 12h, noite 18h). */
    public function startsAtHour(): int
    {
        return match ($this) {
            self::Manha => 0,
            self::Tarde => 12,
            self::Noite => 18,
        };
    }

    public static function forHour(int $h): self
    {
        return $h >= 18 ? self::Noite : ($h >= 12 ? self::Tarde : self::Manha);
    }
}
