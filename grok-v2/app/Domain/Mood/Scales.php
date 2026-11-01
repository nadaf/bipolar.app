<?php

namespace App\Domain\Mood;

/**
 * Escalas bipolares centradas em 0 (normal/equilibrado).
 * Negativo = sinal depressivo; positivo = sinal de (hipo)mania.
 * Cor NUNCA e o unico indicador: sempre rotulo + simbolo.
 */
final class Scales
{
    public const MOOD = [
        -3 => ['Depressão grave', '▼▼▼'],
        -2 => ['Depressão moderada', '▼▼'],
        -1 => ['Depressão leve / desânimo', '▼'],
        0 => ['Estável', '●'],
        1 => ['Elevado leve', '▲'],
        2 => ['Hipomania', '▲▲'],
        3 => ['Mania / euforia', '▲▲▲'],
    ];

    public const ENERGY = [
        -3 => ['Muito baixa', '▼▼▼'],
        -2 => ['Baixa', '▼▼'],
        -1 => ['Um pouco baixa', '▼'],
        0 => ['Normal', '●'],
        1 => ['Um pouco alta', '▲'],
        2 => ['Alta', '▲▲'],
        3 => ['Muito alta', '▲▲▲'],
    ];

    public const INTENSITY = [0 => 'Nenhuma', 1 => 'Leve', 2 => 'Moderada', 3 => 'Intensa'];

    /**
     * Energia da 0.5 (0..3 com rotulos nenhuma, leve, moderada, intensa) para a escala
     * bipolar do 2.0 (-3..+3). Documentado em docs/MIGRATION-FROM-0.5.md. O valor original fica em
     * mood_entries.energy_legacy. Fora de 0..3 => null (descartado e contado).
     */
    public const ENERGY_FROM_LEGACY = [0 => -2, 1 => 0, 2 => 1, 3 => 2];

    public static function energyFromLegacy(?int $v): ?int
    {
        return $v === null ? null : (self::ENERGY_FROM_LEGACY[$v] ?? null);
    }

    public static function label(array $scale, ?int $v): ?string
    {
        return $v === null ? null : $scale[$v][0];
    }
}
