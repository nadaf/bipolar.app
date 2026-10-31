<?php

// Correcoes da revisao de usabilidade de 31/10/2026 (docs/testes-humanos/revisao-usabilidade-2026-10-31.md).

it('titulo do relatorio do paciente nao traz o nome dele (A5)', function () {
    $c = clinic('clinica-titulo');
    $pro = professional($c);
    $pat = patient();
    grantTo($pat, $pro);
    $html = $this->actingAs($pro)->get(onHost('clinica-titulo', '/cuidado/'.$pat->id))->assertOk()->getContent();
    preg_match('#<title>(.*?)</title>#s', $html, $m);
    expect($m[1])->toBe('Relatório — Bipolar')->and($m[1])->not->toContain($pat->name);
});

it('titulo do dia do historico e neutro (A5)', function () {
    $p = patient();
    $this->actingAs($p)->get('/historico/'.now()->subDay()->toDateString())
        ->assertOk()->assertSee('<title>Dia do histórico — Bipolar</title>', false);
});

it('familiar ve orientacao de como ajudar; profissional nao (A6)', function () {
    $c = clinic('clinica-ajuda');
    $pro = professional($c);
    $pat = patient();
    grantTo($pat, $pro);
    $this->actingAs($pro)->get(onHost('clinica-ajuda', '/cuidado/'.$pat->id))->assertOk()->assertDontSee('Como ajudar');

    $pat2 = patient();
    $fam = professional($c);
    grantTo($pat2, $fam, 1, ['grantee_kind' => 'familiar']);
    $this->actingAs($fam)->get(onHost('clinica-ajuda', '/cuidado/'.$pat2->id))->assertOk()
        ->assertSee('Como ajudar')->assertSee('tel:188', false)->assertSee('tel:192', false);
});

it('remover profissional pede confirmacao em dialogo acessivel (A2) e a clinica explica o convite do paciente (A8)', function () {
    $c = clinic('clinica-dlg');
    professional($c);
    $this->actingAs(clinicAdmin($c))->get(onHost('clinica-dlg', '/clinica'))->assertOk()
        ->assertSee('x-data="confirmarEnvio"', false)->assertSee('<dialog', false)->assertSee('Cancelar')
        ->assertSee('E os pacientes?')->assertSee('Compartilhar');
});

it('grade de lembretes tem dias nas linhas e aviso honesto de que ainda nao envia (A1, A7)', function () {
    $html = $this->actingAs(patient())->get('/alertas')->assertOk()->getContent();
    expect($html)->toContain('<th scope="row" class="text-sm text-left pe-2">Segunda')
        ->and($html)->toContain('ainda <strong>não envia</strong>')
        ->and($html)->not->toContain('Beta:');
});

it('conta tem instalacao do PWA com instrucoes para iOS e Android (A4) e o layout tem aviso de versao nova (A3)', function () {
    $this->actingAs(patient())->get('/conta')->assertOk()
        ->assertSee('Instalar o app')->assertSee('data-pwa-instalar', false)->assertSee('Adicionar à Tela de Início')
        ->assertSee('id="sw-nova-versao"', false)->assertSee('data-sw-atualizar', false);
});

it('campos de data declaram pt-BR (A9)', function () {
    $this->actingAs(patient())->get('/relatorios')->assertOk()->assertSee('type="date" lang="pt-BR"', false);
});
