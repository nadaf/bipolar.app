<?php

use App\Models\MoodEntry;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('registra, cria token e salva humor por periodo', function () {
    $r = $this->postJson('/api/v1/auth/register', ['name' => 'Ana', 'email' => 'ana@ex.test', 'password' => 'senha-muito-boa-1', 'device_name' => 'teste']);
    $r->assertCreated()->assertJsonStructure(['token']);

    $tok = $r->json('token');
    $this->withToken($tok)->putJson('/api/v1/entries', entryPayload(['mood' => -1, 'energy' => -3, 'note' => 'cansada']))
        ->assertCreated()
        ->assertJsonPath('data.energy', -3)
        ->assertJsonPath('data.energy_label', 'Muito baixa')
        ->assertJsonPath('data.signal', 'depressivo')
        ->assertJsonPath('data.period_label', 'Tarde')
        ->assertJsonPath('data.version', 1);
});

it('atualiza o mesmo periodo (upsert) e incrementa versao; versao antiga da 409', function () {
    $p = patient();
    Sanctum::actingAs($p);
    $this->putJson('/api/v1/entries', entryPayload(['mood' => 0]))->assertCreated();
    $this->putJson('/api/v1/entries', entryPayload(['mood' => 2, 'energy' => 3, 'version' => 1]))
        ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.signal', 'maniaco');
    $this->putJson('/api/v1/entries', entryPayload(['mood' => 1, 'version' => 1]))->assertStatus(409);
    expect(asTenant(tenantOf($p), fn () => MoodEntry::count()))->toBe(1);
});

it('valida escalas: energia e humor so de -3 a +3', function (array $bad) {
    Sanctum::actingAs(patient());
    $this->putJson('/api/v1/entries', entryPayload($bad))->assertUnprocessable();
})->with([[['energy' => 4]], [['energy' => -4]], [['mood' => 5]], [['period' => 'madrugada']], [['anxiety' => -1]]]);

it('trava de periodo: recusa periodo futuro', function () {
    Sanctum::actingAs(patient());
    $amanha = now('America/Sao_Paulo')->addDay()->toDateString();
    $this->putJson('/api/v1/entries', entryPayload(['entry_date' => $amanha, 'period' => 'manha']))
        ->assertUnprocessable()->assertJsonValidationErrors('period');
});

it('anotacao fica criptografada no banco', function () {
    $p = patient();
    Sanctum::actingAs($p);
    $this->putJson('/api/v1/entries', entryPayload(['note' => 'segredo-xyz']))->assertCreated();
    $raw = DB::table('mood_entries')->value('note');
    expect($raw)->not->toContain('segredo-xyz');
});

it('nao aceita tenant_id/patient_id vindos do cliente', function () {
    $a = patient();
    $b = patient();
    Sanctum::actingAs($a);
    $this->putJson('/api/v1/entries', entryPayload(['tenant_id' => tenantOf($b), 'patient_id' => $b->id]))->assertCreated();
    expect(asTenant(tenantOf($b), fn () => MoodEntry::count()))->toBe(0)
        ->and(asTenant(tenantOf($a), fn () => MoodEntry::where('patient_id', $a->id)->count()))->toBe(1);
});

it('exige autenticacao', function () {
    $this->getJson('/api/v1/entries')->assertUnauthorized();
});
