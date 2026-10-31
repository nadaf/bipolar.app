<?php

use App\Models\CareGrant;
use Illuminate\Support\Facades\DB;

function unlockToken(string $tok): string
{
    return test()->withToken($tok)->postJson('/api/v1/private-modules/unlock', ['method' => 'password', 'secret' => 'senha-muito-boa-1'])->json('step_up_token');
}

it('paciente convida, profissional aceita, ve relatorio filtrado e paciente ve auditoria', function () {
    $ana = patient();
    $tok = tokenFor($ana);
    $c = clinic();
    $pro = professional($c);
    $this->withToken($tok)->putJson('/api/v1/entries', entryPayload(['mood' => -2, 'note' => 'diario intimo']))->assertCreated();
    $this->withToken($tok)->putJson('/api/v1/days/'.entryPayload()['entry_date'].'/blocks/sono', ['data' => ['horas' => 5]])->assertCreated();
    $this->withToken($tok)->putJson('/api/v1/days/'.entryPayload()['entry_date'].'/blocks/substancias', ['data' => ['alcool' => 4]])->assertCreated();

    $r = $this->withToken($tok)->postJson('/api/v1/care/grants', ['grantee_kind' => 'psiquiatra', 'scope' => ['humor', 'sono']])
        ->assertCreated()->assertJsonPath('grant.status', 'pendente')->assertJsonPath('grant.scope_mask', 3);
    $token = $r->json('invite_token');
    expect(DB::table('care_grants')->value('invite_token_hash'))->toBe(hash('sha256', $token));

    $ptok = tokenFor($pro);
    $this->withToken($ptok)->getJson("/api/v1/care/patients/{$ana->id}/report")->assertNotFound();
    $this->withToken($ptok)->postJson("/api/v1/care/invites/{$token}/accept")->assertOk()->assertJsonPath('data.status', 'ativo');
    $this->withToken($ptok)->postJson("/api/v1/care/invites/{$token}/accept")->assertNotFound(); // uso unico

    $rep = $this->withToken($ptok)->getJson("/api/v1/care/patients/{$ana->id}/report?periodo=7")->assertOk();
    $day = collect($rep->json('data.days'))->first();
    expect($day['periods']['tarde']['note'])->toBeNull()
        ->and(collect($day['blocks'])->pluck('block')->all())->toBe(['sono'])
        ->and($rep->json('data.sleep_avg'))->toEqual(5);

    $gid = $r->json('grant.id');
    $this->withToken($tok)->getJson("/api/v1/care/grants/{$gid}/audit")->assertOk()
        ->assertJsonFragment(['label' => 'Viu o relatório', 'actor_name' => $pro->name])->assertJsonFragment(['label' => 'Convite aceito']);
    $this->withToken($tok)->getJson('/api/v1/care/grants')->assertJsonPath('data.0.grantee_name', $pro->name);

    $this->withToken($tok)->deleteJson("/api/v1/care/grants/{$gid}")->assertNoContent();
    $this->withToken($ptok)->getJson("/api/v1/care/patients/{$ana->id}/report")->assertNotFound();
    $this->withToken($tok)->getJson('/api/v1/care/grants')->assertJsonPath('data.0.status', 'revogado');
});

it('familiar (conta de paciente) tambem pode aceitar e ler', function () {
    $ana = patient();
    $mae = patient();
    $token = $this->withToken(tokenFor($ana))->postJson('/api/v1/care/grants', ['grantee_kind' => 'familiar', 'scope' => ['humor']])->json('invite_token');
    $this->withToken(tokenFor($mae))->postJson("/api/v1/care/invites/{$token}/accept")->assertOk();
    $this->withToken(tokenFor($mae))->getJson('/api/v1/care/patients')->assertJsonPath('data.0.patient_id', $ana->id);
});

it('categorias sensiveis exigem desbloqueio ao convidar e ao ampliar', function () {
    $tok = tokenFor(patient());
    $this->withToken($tok)->postJson('/api/v1/care/grants', ['grantee_kind' => 'psicologo', 'scope' => ['humor', 'pensamentos_morte']])->assertStatus(423);
    $g = $this->withToken($tok)->postJson('/api/v1/care/grants', ['grantee_kind' => 'psicologo', 'scope' => ['humor']])->assertCreated()->json('grant.id');
    $this->withToken($tok)->patchJson("/api/v1/care/grants/{$g}", ['scope' => ['humor', 'substancias']])->assertStatus(423);
    $this->withToken($tok)->withHeader('X-Step-Up', unlockToken($tok))->patchJson("/api/v1/care/grants/{$g}", ['scope' => ['humor', 'substancias']])
        ->assertOk()->assertJsonPath('data.scope_mask', 17);
    $this->withToken($tok)->patchJson("/api/v1/care/grants/{$g}", ['scope' => ['sono']])->assertOk(); // reduzir nao exige
});

it('nao aceita o proprio convite, convite vencido nem token forjado', function () {
    $ana = patient();
    $tok = tokenFor($ana);
    $token = $this->withToken($tok)->postJson('/api/v1/care/grants', ['grantee_kind' => 'outro', 'scope' => ['humor']])->json('invite_token');
    $this->withToken($tok)->postJson("/api/v1/care/invites/{$token}/accept")->assertNotFound();
    $this->withToken(tokenFor(patient()))->postJson('/api/v1/care/invites/'.str_repeat('x', 48).'/accept')->assertNotFound();
    CareGrant::withoutGlobalScopes()->update(['invite_expires_at' => now()->subMinute()]);
    $this->withToken(tokenFor(patient()))->postJson("/api/v1/care/invites/{$token}/accept")->assertNotFound();
});

it('IDOR: paciente nao ve, altera nem revoga concessao de outro', function () {
    $ana = patient();
    $g = $this->withToken(tokenFor($ana))->postJson('/api/v1/care/grants', ['grantee_kind' => 'outro', 'scope' => ['humor']])->json('grant.id');
    $bia = tokenFor(patient());
    $this->withToken($bia)->patchJson("/api/v1/care/grants/{$g}", ['scope' => ['sono']])->assertNotFound();
    $this->withToken($bia)->deleteJson("/api/v1/care/grants/{$g}")->assertNotFound();
    $this->withToken($bia)->getJson("/api/v1/care/grants/{$g}/audit")->assertNotFound();
    // mesma clinica, outro paciente
    $c = clinic();
    $p1 = patient($c->id);
    $p2 = patient($c->id);
    $g1 = $this->withToken(tokenFor($p1))->postJson('/api/v1/care/grants', ['grantee_kind' => 'outro', 'scope' => ['humor']])->json('grant.id');
    $this->withToken(tokenFor($p2))->deleteJson("/api/v1/care/grants/{$g1}")->assertNotFound();
    // campos forjados sao ignorados
    $this->withToken(tokenFor($p2))->postJson('/api/v1/care/grants', ['grantee_kind' => 'outro', 'scope' => ['humor'], 'patient_id' => $p1->id, 'tenant_id' => 999])
        ->assertCreated();
    expect(CareGrant::withoutGlobalScopes()->where('patient_id', $p1->id)->count())->toBe(1);
});
