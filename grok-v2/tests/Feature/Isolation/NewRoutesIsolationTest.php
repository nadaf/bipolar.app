<?php

/** 2.0 front-end: toda rota nova respeita tenant/paciente; fora do alcance => 404, nunca dados alheios. */
it('blocos de outro paciente nunca aparecem (mesmo tenant de clinica ou tenants diferentes)', function () {
    $c = clinic();
    $a = patient($c->id);
    $b = patient($c->id);
    $x = patient();
    $d = entryPayload()['entry_date'];
    $this->withToken(tokenFor($a))->putJson("/api/v1/days/$d/blocks/anotacao", ['data' => ['texto' => 'segredo']])->assertCreated();
    foreach ([$b, $x] as $other) {
        $r = $this->withToken(tokenFor($other))->getJson("/api/v1/days/$d")->assertOk();
        expect($r->getContent())->not->toContain('segredo');
    }
    // campos forjados no corpo sao ignorados: grava no proprio paciente
    $this->withToken(tokenFor($b))->putJson("/api/v1/days/$d/blocks/anotacao", ['patient_id' => $a->id, 'tenant_id' => tenantOf($x), 'data' => ['texto' => 'meu']])
        ->assertCreated()->assertJsonPath('data.version', 1);
});

it('rotas de paciente: profissional/admin sem papel de paciente => 404', function () {
    $c = clinic();
    foreach ([professional($c), clinicAdmin($c)] as $u) {
        $t = tokenFor($u);
        $this->withToken($t)->getJson('/api/v1/days/'.entryPayload()['entry_date'])->assertNotFound();
        $this->withToken($t)->getJson('/api/v1/reminders')->assertNotFound();
        $this->withToken($t)->getJson('/api/v1/care/grants')->assertNotFound();
        $this->withToken($t)->postJson('/api/v1/export')->assertNotFound();
    }
});

it('X-Tenant-Id alheio nas rotas novas => 404', function () {
    $a = patient();
    $x = patient();
    $this->withToken(tokenFor($a))->withHeader('X-Tenant-Id', (string) tenantOf($x))->getJson('/api/v1/care/grants')->assertNotFound();
    $this->withToken(tokenFor($a))->withHeader('X-Tenant-Id', (string) tenantOf($x))->getJson('/api/v1/days/'.entryPayload()['entry_date'])->assertNotFound();
});

it('relatorio de rede de apoio: sem concessao, concessao expirada ou sem humor => 404', function () {
    $ana = patient();
    $pro = professional(clinic());
    $t = tokenFor($pro);
    $this->withToken($t)->getJson("/api/v1/care/patients/{$ana->id}/report")->assertNotFound();
    grantTo($ana, $pro, 2); // so sono
    $this->withToken($t)->getJson("/api/v1/care/patients/{$ana->id}/report")->assertNotFound();
    $ana2 = patient();
    grantTo($ana2, $pro, 7, ['expires_at' => now()->subDay()]);
    $this->withToken($t)->getJson("/api/v1/care/patients/{$ana2->id}/report")->assertNotFound();
});
