<?php

use App\Support\EnvVapid;

it('cria chaves VAPID vazias no .env e nunca sobrescreve', function () {
    $f = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($f, "APP_KEY=\nMAIL_FROM_ADDRESS=oi@exemplo.test\nVAPID_PUBLIC_KEY=\nVAPID_PRIVATE_KEY=\nVAPID_SUBJECT=\n");
    expect(EnvVapid::ensure($f))->toBeTrue();
    $a = file_get_contents($f);
    expect($a)->toMatch('/^VAPID_PUBLIC_KEY=\S{60,}$/m')->toMatch('/^VAPID_PRIVATE_KEY=\S{20,}$/m')->toContain('VAPID_SUBJECT=mailto:oi@exemplo.test');
    expect(EnvVapid::ensure($f))->toBeFalse();
    expect(file_get_contents($f))->toBe($a);
    file_put_contents($f, "VAPID_PUBLIC_KEY=minha\nVAPID_PRIVATE_KEY=\n");
    expect(EnvVapid::ensure($f))->toBeFalse();
    expect(file_get_contents($f))->toContain("VAPID_PUBLIC_KEY=minha\nVAPID_PRIVATE_KEY=\n")->toContain('VAPID_SUBJECT=https://bipolar.app.br');
    unlink($f);
});
