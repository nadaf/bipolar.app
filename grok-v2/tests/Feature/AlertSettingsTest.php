<?php

use App\Domain\Alerts\AlertThresholds;
use App\Domain\Mood\SignalClassifier;

it('padrao e -2/+2 e o paciente personaliza', function () {
    $tok = tokenFor(patient());
    $this->withToken($tok)->getJson('/api/v1/settings/alerts')->assertOk()
        ->assertJson(['data' => ['mood_low' => -2, 'mood_high' => 2, 'energy_low' => -2, 'energy_high' => 2]]);
    $this->withToken($tok)->putJson('/api/v1/settings/alerts', ['mood_low' => -1, 'mood_high' => 3, 'energy_low' => -3, 'energy_high' => 1])
        ->assertOk()->assertJsonPath('data.energy_high', 1);
    $this->withToken($tok)->getJson('/api/v1/settings/alerts')->assertJsonPath('data.mood_low', -1);
});

it('valida faixas: baixo -3..-1, alto 1..3', function (array $bad) {
    $ok = ['mood_low' => -2, 'mood_high' => 2, 'energy_low' => -2, 'energy_high' => 2];
    $this->withToken(tokenFor(patient()))->putJson('/api/v1/settings/alerts', array_merge($ok, $bad))->assertStatus(422);
})->with([[['mood_low' => 0]], [['mood_high' => 0]], [['energy_low' => 1]], [['energy_high' => 4]], [['energy_low' => -4]]]);

it('o sinal do registro usa os limiares do proprio paciente', function () {
    $tok = tokenFor(patient());
    $this->withToken($tok)->putJson('/api/v1/entries', entryPayload(['energy' => 1]))->assertJsonPath('data.signal', 'neutro');
    $this->withToken($tok)->putJson('/api/v1/settings/alerts', ['mood_low' => -2, 'mood_high' => 2, 'energy_low' => -1, 'energy_high' => 1]);
    $this->withToken($tok)->getJson('/api/v1/entries')->assertJsonPath('data.0.signal', 'maniaco');
});

it('classificador respeita limiares pessoais (baixo = depressivo, alto = maniaco, opostos = misto)', function () {
    $t = new AlertThresholds(-1, 3, -3, 1);
    expect(SignalClassifier::classify(-1, 0, $t))->toBe('depressivo')
        ->and(SignalClassifier::classify(2, 0, $t))->toBe('neutro')
        ->and(SignalClassifier::classify(0, 1, $t))->toBe('maniaco')
        ->and(SignalClassifier::classify(-1, 1, $t))->toBe('misto')
        ->and(SignalClassifier::classify(0, -2, $t))->toBe('neutro');
});

it('limiares sao isolados por paciente/tenant', function () {
    $ana = patient();
    $this->withToken(tokenFor($ana))->putJson('/api/v1/settings/alerts', ['mood_low' => -1, 'mood_high' => 1, 'energy_low' => -1, 'energy_high' => 1]);
    $this->withToken(tokenFor(patient()))->getJson('/api/v1/settings/alerts')->assertJsonPath('data.mood_low', -2);
});

it('tela de alertas: acessivel e salva via HTMX', function () {
    $p = patient();
    $this->post('/entrar', ['email' => $p->email, 'password' => 'senha-muito-boa-1']);
    $this->get('/alertas')->assertOk()->assertSee('Sinal baixo a partir de')->assertSee('<label for="energy_high"', false);
    $this->withHeader('HX-Request', 'true')->post('/alertas', ['mood_low' => -3, 'mood_high' => 3, 'energy_low' => -2, 'energy_high' => 2])
        ->assertOk()->assertSee('Limiares salvos');
});

it('registro antigo (sem limite de dias) e aceito; periodo futuro nao', function () {
    $tok = tokenFor(patient());
    $this->withToken($tok)->putJson('/api/v1/entries', entryPayload(['entry_date' => now()->subYears(2)->toDateString()]))->assertSuccessful();
    $this->withToken($tok)->putJson('/api/v1/entries', entryPayload(['entry_date' => now('America/Sao_Paulo')->addDay()->toDateString()]))->assertStatus(422);
});
