<?php

use App\Models\CareGrant;
use App\Models\MoodEntry;

it('historico e relatorio nao mostram dados de outro paciente', function () {
    $v = patient();
    asTenant(tenantOf($v), fn () => MoodEntry::create(['patient_id' => $v->id] + entryPayload(['mood' => 3])));
    $intruso = patient();
    $d = entryPayload()['entry_date'];
    $this->actingAs($intruso)->get('/historico?mes='.substr($d, 0, 7))->assertOk()->assertDontSee('Mania / euforia');
    $this->actingAs($intruso)->get('/historico/'.$d)->assertOk()->assertDontSee('Mania / euforia');
    $this->actingAs($intruso)->get('/relatorios')->assertOk()->assertDontSee('Mania / euforia');
});

it('concessao de outro paciente => 404 em ver, alterar e revogar', function () {
    $dono = patient();
    $this->actingAs($dono)->post('/compartilhar', ['grantee_kind' => 'psicologo', 'scope' => ['humor']]);
    $g = asTenant(tenantOf($dono), fn () => CareGrant::first());
    $x = patient();
    $this->actingAs($x)->get('/compartilhar/'.$g->id)->assertNotFound();
    $this->actingAs($x)->post('/compartilhar/'.$g->id, ['scope' => ['humor', 'sono']])->assertNotFound();
    $this->actingAs($x)->post('/compartilhar/'.$g->id.'/revogar')->assertNotFound();
    expect(asTenant(tenantOf($dono), fn () => CareGrant::first()->revoked_at))->toBeNull();
});

it('quem cuida sem concessao (ou revogada) => 404', function () {
    $p = patient();
    $c = clinic('cl-iso');
    $pro = professional($c);
    $this->actingAs($pro)->get(onHost('cl-iso', '/cuidado/'.$p->id))->assertNotFound();
    grantTo($p, $pro, 7, ['revoked_at' => now()]);
    $this->actingAs($pro)->get(onHost('cl-iso', '/cuidado/'.$p->id))->assertNotFound();
});

it('telas de paciente sao 404 para profissional e clinica e 404 para paciente', function () {
    $c = clinic('cl-iso2');
    $this->actingAs(professional($c))->get(onHost('cl-iso2', '/registrar'))->assertNotFound();
    $this->actingAs(professional($c))->get(onHost('cl-iso2', '/clinica'))->assertNotFound();
    $this->actingAs(patient())->get('/clinica')->assertNotFound();
});

it('desbloqueio de um usuario nao vale para outro', function () {
    $a = patient();
    $a->forceFill(['modules' => 'morte'])->save();
    $this->actingAs($a)->post('/desbloquear', ['method' => 'password', 'secret' => 'senha-muito-boa-1']);
    $this->flushSession();
    $b = patient();
    $b->forceFill(['modules' => 'morte'])->save();
    $this->actingAs($b)->post('/registrar/bloco/morte', ['entry_date' => entryPayload()['entry_date'], 'period' => 'tarde', 'data' => ['intensidade' => '1']])
        ->assertStatus(423);
});
