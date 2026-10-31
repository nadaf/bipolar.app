<?php

use App\Models\MoodEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('altera perfil, tema e modulos; recusa valores invalidos', function () {
    $tok = tokenFor(patient());
    $this->withToken($tok)->patchJson('/api/v1/account', ['name' => 'Ana', 'timezone' => 'America/Cuiaba', 'theme' => 'escuro'])
        ->assertOk()->assertJsonPath('theme', 'escuro')->assertJsonPath('timezone', 'America/Cuiaba');
    $this->withToken($tok)->patchJson('/api/v1/account', ['theme' => 'roxo'])->assertUnprocessable();
    $this->withToken($tok)->patchJson('/api/v1/account', ['timezone' => 'Lua/Base'])->assertUnprocessable();
    $this->withToken($tok)->patchJson('/api/v1/account', ['modules' => ['hack']])->assertUnprocessable();
});

it('troca de e-mail em duas fases: so muda depois do link', function () {
    Mail::fake();
    $p = patient();
    $this->withToken(tokenFor($p))->postJson('/api/v1/account/email', ['email' => 'novo@ex.test'])->assertStatus(202);
    expect($p->fresh()->email)->not->toBe('novo@ex.test')->and($p->fresh()->pending_email)->toBe('novo@ex.test');
});

it('exportacao e exclusao exigem desbloqueio; exclusao apaga dados e tenant pessoal', function () {
    $p = patient();
    $tok = tokenFor($p);
    $this->withToken($tok)->putJson('/api/v1/entries', entryPayload(['note' => 'meu']))->assertCreated();
    $this->withToken($tok)->postJson('/api/v1/export')->assertStatus(423);
    $su = $this->withToken($tok)->postJson('/api/v1/private-modules/unlock', ['method' => 'password', 'secret' => 'senha-muito-boa-1'])->json('step_up_token');
    $this->withToken($tok)->withHeader('X-Step-Up', $su)->postJson('/api/v1/export')->assertOk()
        ->assertJsonPath('tenants.0.entries.0.note', 'meu')->assertHeader('Content-Disposition');
    $this->withToken($tok)->withHeader('X-Step-Up', '')->deleteJson('/api/v1/account')->assertStatus(423);
    $tid = tenantOf($p);
    $this->withToken($tok)->withHeader('X-Step-Up', $su)->deleteJson('/api/v1/account')->assertNoContent();
    expect(User::find($p->id))->toBeNull()->and(Tenant::find($tid))->toBeNull()
        ->and(MoodEntry::withoutGlobalScopes()->count())->toBe(0);
});

it('lembretes: grade semanal validada e salva', function () {
    $tok = tokenFor(patient());
    $this->withToken($tok)->getJson('/api/v1/reminders')->assertOk()->assertJsonCount(7, 'days');
    $days = array_map(fn ($w) => ['weekday' => $w, 'slots' => $w === 1 ? [['time' => '08:30', 'period' => 'manha', 'channel' => 'push']] : []], range(0, 6));
    $this->withToken($tok)->putJson('/api/v1/reminders', ['days' => $days])->assertOk()->assertJsonPath('days.1.slots.0.time', '08:30');
    $days[1]['slots'][0]['time'] = '25:00';
    $this->withToken($tok)->putJson('/api/v1/reminders', ['days' => $days])->assertUnprocessable();
    $this->withToken($tok)->putJson('/api/v1/reminders', ['days' => array_slice($days, 0, 3)])->assertUnprocessable();
});

it('resumo do relatorio usa os limiares do paciente', function () {
    $tok = tokenFor(patient());
    $this->withToken($tok)->putJson('/api/v1/entries', entryPayload(['mood' => -1, 'energy' => -1]))->assertCreated();
    $q = '?from='.now()->subDays(7)->toDateString().'&to='.now()->toDateString();
    $this->withToken($tok)->getJson('/api/v1/reports/summary'.$q)->assertOk()->assertJsonPath('signals.neutro', 1)->assertJsonMissingPath('days');
    $this->withToken($tok)->putJson('/api/v1/settings/alerts', ['mood_low' => -1, 'mood_high' => 2, 'energy_low' => -1, 'energy_high' => 2])->assertOk();
    $this->withToken($tok)->getJson('/api/v1/reports/summary'.$q)->assertJsonPath('signals.depressivo', 1);
    $this->withToken($tok)->getJson('/api/v1/reports/summary')->assertUnprocessable();
});
