<?php

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\User;

it('profissional solo: cadastro cria tenant proprio com um profissional', function () {
    $r = $this->postJson('/api/v1/auth/register', ['name' => 'Dr. Solo', 'email' => 'solo@ex.test', 'password' => 'senha-muito-boa-1',
        'device_name' => 't', 'account_type' => 'professional', 'subdomain' => 'dr-solo'])->assertCreated();
    $this->withToken($r->json('token'))->getJson('/api/v1/care/patients')->assertOk()->assertJsonPath('data', []);
    $this->withToken($r->json('token'))->getJson('/api/v1/entries')->assertNotFound(); // nao e paciente
    $this->get('http://dr-solo.bipolar.app.br/entrar')->assertOk();
});

it('admin da clinica adiciona, lista e remove profissionais', function () {
    $c = clinic();
    $tok = tokenFor(clinicAdmin($c));
    $pro = User::create(['name' => 'Dra. Nova', 'email' => 'nova@pro.test', 'password' => 'senha-muito-boa-1']);
    $this->withToken($tok)->postJson('/api/v1/clinic/professionals', ['email' => 'nova@pro.test'])->assertCreated();
    $this->withToken($tok)->getJson('/api/v1/clinic/professionals')->assertOk()->assertJsonPath('data.0.email', 'nova@pro.test');
    $this->withToken($tok)->deleteJson('/api/v1/clinic/professionals/'.$pro->id)->assertNoContent();
    expect(Membership::where('user_id', $pro->id)->exists())->toBeFalse();
    $this->withToken($tok)->postJson('/api/v1/clinic/professionals', ['email' => 'naoexiste@pro.test'])->assertStatus(422);
});

it('admin nao remove profissional de OUTRA clinica (404) e profissional nao administra', function () {
    $a = clinic();
    $b = clinic();
    $proB = professional($b);
    $this->withToken(tokenFor(clinicAdmin($a)))->deleteJson('/api/v1/clinic/professionals/'.$proB->id)->assertNotFound();
    expect(Membership::where('user_id', $proB->id)->exists())->toBeTrue();
    $this->withToken(tokenFor(professional($a)))->getJson('/api/v1/clinic/professionals')->assertNotFound();
    $this->withToken(tokenFor(patient()))->getJson('/api/v1/clinic/professionals')->assertNotFound();
});

it('profissional so ve pacientes que concederam acesso a ele', function () {
    $c = clinic();
    $pro = professional($c);
    $colega = professional($c);
    $ana = patient();
    $bia = patient();
    $this->withToken(tokenFor($ana))->putJson('/api/v1/entries', entryPayload(['mood' => -2, 'note' => 'privado']))->assertSuccessful();
    grantTo($ana, $pro);
    grantTo($bia, $colega);

    $tok = tokenFor($pro);
    $this->withToken($tok)->getJson('/api/v1/care/patients')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.patient_id', $ana->id);
    $this->withToken($tok)->getJson("/api/v1/care/patients/{$ana->id}/entries")->assertOk()
        ->assertJsonPath('data.0.mood', -2)->assertJsonPath('data.0.note', null); // notas nao concedidas
    $this->withToken($tok)->getJson("/api/v1/care/patients/{$bia->id}/entries")->assertNotFound();
    // admin da clinica NAO le dados clinicos
    $this->withToken(tokenFor(clinicAdmin($c)))->getJson("/api/v1/care/patients/{$ana->id}/entries")->assertNotFound();
});

it('concessao revogada, expirada, sem aceite ou sem a categoria => 404', function (array $o, int $mask) {
    $pro = professional(clinic());
    $ana = patient();
    grantTo($ana, $pro, $mask, $o);
    $this->withToken(tokenFor($pro))->getJson("/api/v1/care/patients/{$ana->id}/entries")->assertNotFound();
})->with([
    'revogada' => [['revoked_at' => now()], 7],
    'expirada' => [['expires_at' => now()->subMinute()], 7],
    'sem aceite' => [['accepted_at' => null], 7],
    'sem humor' => [[], 2],
]);

it('leitura do profissional fica auditada no tenant do paciente', function () {
    $pro = professional(clinic());
    $ana = patient();
    grantTo($ana, $pro);
    $this->withToken(tokenFor($pro))->getJson("/api/v1/care/patients/{$ana->id}/entries")->assertOk();
    asTenant(tenantOf($ana), fn () => expect(AuditLog::where('action', 'care.read_mood')->where('actor_id', $pro->id)->count())->toBe(1));
});
