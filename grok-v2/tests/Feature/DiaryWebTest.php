<?php

use App\Http\Middleware\SecurityHeaders;
use App\Models\MoodEntry;

it('mostra a tela com escala de energia acessivel e CSP estrita', function () {
    $r = $this->actingAs(patient())->get('/hoje');
    $r->assertOk()
        ->assertSee('Registrando:')
        ->assertSee('Muito baixa')->assertSee('Normal')->assertSee('Muito alta')
        ->assertSee('<fieldset', false)->assertSee('<legend', false)
        ->assertSee('CVV 188')
        ->assertHeader('Content-Security-Policy', SecurityHeaders::CSP)
        ->assertHeader('Cache-Control');
    expect($r->getContent())->not->toMatch('/<script(?![^>]*\bsrc=)[^>]*>/i')
        ->and($r->getContent())->not->toContain(' style=')
        ->and($r->getContent())->not->toMatch('/\son[a-z]+=/i');
});

it('salva via HTMX e devolve fragmento com nova versao', function () {
    $p = patient();
    $this->actingAs($p)->post('/hoje/registro', entryPayload(['mood' => -2, 'energy' => -2]), ['HX-Request' => 'true'])
        ->assertOk()->assertSee('Registro salvo')->assertSee('energia Baixa')->assertSee('hx-swap-oob', false);
    expect(asTenant(tenantOf($p), fn () => MoodEntry::first()->energy))->toBe(-2);
});

it('visitante e redirecionado para entrar', function () {
    $this->get('/hoje')->assertRedirect('/entrar');
});

it('login web funciona', function () {
    $p = patient();
    $this->post('/entrar', ['email' => $p->email, 'password' => 'senha-muito-boa-1'])->assertRedirect('/hoje');
});

it('instalador fica desligado sem INSTALLER_TOKEN', function () {
    $this->get('/instalar')->assertNotFound();
    $this->post('/instalar', ['token' => 'x'])->assertNotFound();
});

it('instalador com token: recusa token errado e roda migrations com o certo', function () {
    config(['bipolar.installer_token' => str_repeat('a', 40)]);
    $this->post('/instalar', ['token' => 'errado'])->assertForbidden();
    $this->post('/instalar', ['token' => str_repeat('a', 40)])->assertOk()->assertSee('Pronto!');
    expect(file_exists(storage_path('app/installer.lock')))->toBeTrue();
    @unlink(storage_path('app/installer.lock'));
});
