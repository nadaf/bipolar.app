<?php

use App\Http\Middleware\SecurityHeaders;
use App\Models\DayBlock;
use App\Models\MoodEntry;

it('Registrar: periodo travado, cartoes acessiveis e CSP estrita', function () {
    $r = $this->actingAs(patient())->get('/registrar');
    $r->assertOk()
        ->assertSee('Registrando:')->assertSee('de hoje (')
        ->assertSee('Muito baixa')->assertSee('Normal')->assertSee('Muito alta')
        ->assertSee('<fieldset', false)->assertSee('<legend', false)
        ->assertSee('Salvar e continuar')->assertSee('Salvar tudo')
        ->assertSee('Sono')->assertSee('Medicação')->assertSee('Meu dia')
        ->assertDontSee('Pensamentos de morte') // modulo desligado por padrao
        ->assertSee('CVV 188')
        ->assertHeader('Content-Security-Policy', SecurityHeaders::CSP);
});

it('/hoje redireciona para /registrar', function () {
    $this->actingAs(patient())->get('/hoje')->assertRedirect('/registrar');
});

it('cartao de energia salva via HTMX e devolve fragmento com nova versao', function () {
    $p = patient();
    $d = entryPayload()['entry_date'];
    $this->actingAs($p)->post('/registrar/humor/energia', ['entry_date' => $d, 'period' => 'tarde', 'energy' => -2], ['HX-Request' => 'true'])
        ->assertOk()->assertSee('Energia: Baixa')->assertSee('hx-swap-oob', false)->assertSee('id="v-humor"', false);
    expect(asTenant(tenantOf($p), fn () => MoodEntry::first()->energy))->toBe(-2);
});

it('cartao sem escolha => 422 com mensagem', function () {
    $this->actingAs(patient())->post('/registrar/humor/humor', ['entry_date' => entryPayload()['entry_date'], 'period' => 'tarde'])
        ->assertStatus(422)->assertSee('Escolha uma opção');
});

it('versao velha => 409 conflito', function () {
    $p = patient();
    $d = entryPayload()['entry_date'];
    $this->actingAs($p)->post('/registrar/humor/humor', ['entry_date' => $d, 'period' => 'tarde', 'mood' => 1])->assertOk();
    $this->actingAs($p)->post('/registrar/humor/humor', ['entry_date' => $d, 'period' => 'tarde', 'mood' => 2])->assertOk(); // sem versao = sobrescreve
    $this->actingAs($p)->post('/registrar/humor/humor', ['entry_date' => $d, 'period' => 'tarde', 'mood' => 2, 'version' => 1])
        ->assertStatus(409)->assertSee('outro aparelho');
});

it('bloco de sono salva e mostra resumo', function () {
    $p = patient();
    $this->actingAs($p)->post('/registrar/bloco/sono', ['entry_date' => entryPayload()['entry_date'], 'period' => 'manha', 'data' => ['horas' => '7.5']])
        ->assertOk()->assertSee('id="s-sono"', false);
    expect(asTenant(tenantOf($p), fn () => DayBlock::first()->data['horas']))->toEqual(7.5);
});

it('bloco privado sem desbloqueio => 423 e nada salvo', function () {
    $p = patient();
    $p->forceFill(['modules' => 'sexualidade,morte'])->save();
    $this->actingAs($p)->get('/registrar')->assertOk()->assertSee('Pensamentos de morte')->assertSee('Desbloquear')->assertSee('tel:188', false);
    $this->actingAs($p)->post('/registrar/bloco/morte', ['entry_date' => entryPayload()['entry_date'], 'period' => 'tarde', 'data' => ['intensidade' => '1']])
        ->assertStatus(423);
    expect(asTenant(tenantOf($p), fn () => DayBlock::count()))->toBe(0);
});

it('desbloqueio com senha libera o bloco privado e volta so para caminho local', function () {
    $p = patient();
    $p->forceFill(['modules' => 'morte'])->save();
    $this->actingAs($p)->post('/desbloquear', ['method' => 'password', 'secret' => 'errada'])->assertSessionHasErrors('secret');
    $this->actingAs($p)->post('/desbloquear', ['method' => 'password', 'secret' => 'senha-muito-boa-1', 'voltar' => 'https://evil.test/x'])->assertRedirect('/');
    $this->actingAs($p)->post('/registrar/bloco/morte', ['entry_date' => entryPayload()['entry_date'], 'period' => 'tarde', 'data' => ['intensidade' => '2']])
        ->assertOk()->assertSee('CVV 188');
});

it('visitante e redirecionado para entrar', function () {
    $this->get('/registrar')->assertRedirect('/entrar');
});

it('login web leva para a pagina inicial do papel', function () {
    $p = patient();
    $this->post('/entrar', ['email' => $p->email, 'password' => 'senha-muito-boa-1'])->assertRedirect('/');
    $this->get('/')->assertRedirect(route('diary.today'));
});

it('criar conta pela web', function () {
    $this->post('/criar-conta', ['name' => 'Nova Pessoa', 'email' => 'nova@x.test', 'password' => 'senha-muito-boa-1',
        'password_confirmation' => 'senha-muito-boa-1', 'account_type' => 'patient'])->assertRedirect('/');
    $this->get('/registrar')->assertOk();
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
    // uso unico: o mesmo codigo nao abre de novo (nem em /atualizar); codigo novo abre
    $this->get('/atualizar')->assertNotFound();
    $this->post('/instalar', ['token' => str_repeat('a', 40)])->assertNotFound();
    config(['bipolar.installer_token' => str_repeat('b', 40)]);
    $this->get('/atualizar')->assertOk()->assertSee('action="/atualizar"', false);
    $this->post('/atualizar', ['token' => str_repeat('b', 40)])->assertOk();
    @unlink(storage_path('app/installer.lock'));
});
