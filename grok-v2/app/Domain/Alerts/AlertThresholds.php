<?php

namespace App\Domain\Alerts;

/**
 * Limiares pessoais. Baixo (-3..-1) => sinal depressivo; alto (1..3) => sinal maniaco.
 * Padrao sensato: -2 / +2. Escolhidos pelo paciente em Ajustes > Alertas.
 */
final class AlertThresholds
{
    public const DEFAULT_LOW = -2;

    public const DEFAULT_HIGH = 2;

    public function __construct(
        public readonly int $moodLow = self::DEFAULT_LOW,
        public readonly int $moodHigh = self::DEFAULT_HIGH,
        public readonly int $energyLow = self::DEFAULT_LOW,
        public readonly int $energyHigh = self::DEFAULT_HIGH,
    ) {}

    /** @return array{mood_low:int,mood_high:int,energy_low:int,energy_high:int} */
    public function toArray(): array
    {
        return ['mood_low' => $this->moodLow, 'mood_high' => $this->moodHigh,
            'energy_low' => $this->energyLow, 'energy_high' => $this->energyHigh];
    }
}
