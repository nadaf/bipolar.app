<?php

use App\Domain\Mood\Scales;
use App\Domain\Mood\SignalClassifier as S;
use App\Support\FirstRunKey;

it('escala de energia e bipolar -3..+3 centrada em Normal', function () {
    expect(array_keys(Scales::ENERGY))->toBe([-3, -2, -1, 0, 1, 2, 3])
        ->and(Scales::ENERGY[0][0])->toBe('Normal')
        ->and(Scales::ENERGY[-3][0])->toBe('Muito baixa')
        ->and(Scales::ENERGY[3][0])->toBe('Muito alta');
});

it('energia baixa e sinal depressivo e alta e sinal maniaco', function () {
    expect(S::classify(0, -2))->toBe(S::DEPRESSIVE)
        ->and(S::classify(0, -3))->toBe(S::DEPRESSIVE)
        ->and(S::classify(0, 2))->toBe(S::MANIC)
        ->and(S::classify(0, 3))->toBe(S::MANIC)
        ->and(S::classify(0, 0))->toBe(S::NEUTRAL)
        ->and(S::classify(0, 1))->toBe(S::NEUTRAL)
        ->and(S::classify(0, null))->toBe(S::NEUTRAL);
});

it('humor baixo com energia alta e misto', function () {
    expect(S::classify(-2, 3))->toBe(S::MIXED)
        ->and(S::classify(-3, -3))->toBe(S::DEPRESSIVE)
        ->and(S::classify(3, 2))->toBe(S::MANIC);
});

it('FirstRunKey gera APP_KEY so com instalador habilitado', function () {
    $dir = sys_get_temp_dir().'/frk'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/.env", "APP_KEY=\nINSTALLER_TOKEN=\n");
    FirstRunKey::ensure($dir);
    expect(file_get_contents("$dir/.env"))->toContain("APP_KEY=\n");
    file_put_contents("$dir/.env", "APP_KEY=\nINSTALLER_TOKEN=".str_repeat('x', 40)."\n");
    FirstRunKey::ensure($dir);
    expect(file_get_contents("$dir/.env"))->toMatch('/APP_KEY=base64:[A-Za-z0-9+\/=]{44}/');
});
