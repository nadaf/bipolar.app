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

    public static function label(array $scale, ?int $v): ?string
    {
        return $v === null ? null : $scale[$v][0];
    }
}
