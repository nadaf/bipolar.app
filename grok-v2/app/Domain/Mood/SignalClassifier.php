<?php

namespace App\Domain\Mood;

use App\Domain\Alerts\AlertThresholds;

/**
 * Classifica humor e energia como sinal (nao diagnostico), usando os limiares PESSOAIS
 * do paciente (padrao -2/+2). Baixo => depressivo; alto => maniaco; opostos => misto.
 * Usado por relatorios e alertas (nunca notifica terceiros sozinho).
 */
final class SignalClassifier
{
    public const DEPRESSIVE = 'depressivo';

    public const MANIC = 'maniaco';

    public const MIXED = 'misto';

    public const NEUTRAL = 'neutro';

    public static function axis(?int $v, int $low = AlertThresholds::DEFAULT_LOW, int $high = AlertThresholds::DEFAULT_HIGH): string
    {
        if ($v === null) {
            return self::NEUTRAL;
        }
        if ($v <= $low) {
            return self::DEPRESSIVE;
        }

        return $v >= $high ? self::MANIC : self::NEUTRAL;
    }

    public static function classify(int $mood, ?int $energy, ?AlertThresholds $t = null): string
    {
        $t ??= new AlertThresholds;
        $signals = array_unique(array_filter(
            [self::axis($mood, $t->moodLow, $t->moodHigh), self::axis($energy, $t->energyLow, $t->energyHigh)],
            fn ($s) => $s !== self::NEUTRAL,
        ));
        if (count($signals) === 2) {
            return self::MIXED;
        }

        return $signals === [] ? self::NEUTRAL : reset($signals);
    }
}
