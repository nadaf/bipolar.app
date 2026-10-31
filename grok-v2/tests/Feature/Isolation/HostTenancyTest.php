<?php

use App\Models\Membership;
use App\Models\Tenant;
use App\Tenancy\TenantSlug;

const BASE = 'bipolar.app.br';

function onHost(string $slug, string $path): string
{
    return 'http://'.$slug.'.'.BASE.$path;
}

it('valida slug: tamanho, caracteres, hifens e reservados', function (string $s, bool $ok) {
    expect(TenantSlug::problem($s) === null)->toBe($ok);
})->with([
    ['clinica-sol', true], ['ana123', true], ['ab', false], [str_repeat('a', 31), false],
    ['Clinica', false], ['clínica', false], ['a_b', false], ['a.b', false], ['-abc', false], ['abc-', false],
    ['a--b', false], ['www', false], ['api', false], ['blog', false], ['clinica', false], ['demo', false],
    ['admin', false], ['mail', false],
]);

it('subdominio inexistente, reservado ou com ponto => 404', function (string $host) {
    $this->get('http://'.$host.'/entrar')->assertNotFound();
})->with(['naoexiste.'.BASE, 'www2.x.'.BASE, 'admin.'.BASE, 'evil.example.com']);

it('host principal e subdominio existente respondem', function () {
    clinic('clinica-sol');
    $this->get('http://'.BASE.'/entrar')->assertOk();
    $this->get('http://www.'.BASE.'/entrar')->assertOk();
    $this->get(onHost('clinica-sol', '/entrar'))->assertOk();
});

it('login pela API no subdominio exige vinculo com aquela clinica', function () {
    $a = clinic('clinica-a');
    clinic('clinica-b');
    $pro = professional($a);
    $this->postJson(onHost('clinica-b', '/api/v1/auth/login'), ['email' => $pro->email, 'password' => 'senha-muito-boa-1', 'device_name' => 't'])
        ->assertStatus(422);
    $this->postJson(onHost('clinica-a', '/api/v1/auth/login'), ['email' => $pro->email, 'password' => 'senha-muito-boa-1', 'device_name' => 't'])
        ->assertOk();
});

it('token emitido na clinica A nao vale no host da clinica B (mesmo sendo membro das duas)', function () {
    $a = clinic('clinica-a');
    $b = clinic('clinica-b');
    $pro = professional($a);
    Membership::create(['tenant_id' => $b->id, 'user_id' => $pro->id, 'role' => 'professional']);
    $tok = tokenFor($pro, $a->id);
    $this->withToken($tok)->getJson(onHost('clinica-a', '/api/v1/care/patients'))->assertOk();
    $this->withToken($tok)->getJson(onHost('clinica-b', '/api/v1/care/patients'))->assertNotFound();
    $this->withToken($tok)->withHeader('X-Tenant-Id', (string) $b->id)->getJson('/api/v1/care/patients')->assertNotFound();
});

it('token de paciente em subdominio de outra pessoa => 404, nunca dados alheios', function () {
    $ana = patient();
    $bia = patient();
    Tenant::whereKey(tenantOf($bia))->update(['slug' => 'bia']);
    $this->withToken(tokenFor($ana))->getJson(onHost('bia', '/api/v1/entries'))->assertNotFound();
    $this->withToken(tokenFor($bia))->getJson(onHost('bia', '/api/v1/entries'))->assertOk();
});

it('sessao web aberta num host nao vale em outro host', function () {
    $ana = patient();
    $bia = patient();
    Tenant::whereKey(tenantOf($bia))->update(['slug' => 'bia']);
    $this->post('http://'.BASE.'/entrar', ['email' => $ana->email, 'password' => 'senha-muito-boa-1'])->assertRedirect();
    $this->get('http://'.BASE.'/hoje')->assertOk();
    // mesma sessao (o teste reaproveita) levada para outro host => 404
    $this->get(onHost('bia', '/hoje'))->assertNotFound();
});

it('login web em subdominio sem vinculo falha com mensagem generica', function () {
    $ana = patient();
    clinic('clinica-a');
    $this->post(onHost('clinica-a', '/entrar'), ['email' => $ana->email, 'password' => 'senha-muito-boa-1'])
        ->assertSessionHasErrors(['email' => 'E-mail ou senha incorretos.']);
});

it('cookie de sessao e so do host (sem Domain compartilhado)', function () {
    expect(config('session.domain'))->toBeNull();
    $r = $this->get('http://'.BASE.'/entrar');
    foreach ($r->headers->getCookies() as $c) {
        expect($c->getDomain())->toBeNull();
    }
});

it('subdominio pessoal opcional: valida, reserva e nao deixa repetir', function () {
    $ana = patient();
    $bia = patient();
    $this->withToken(tokenFor($ana))->putJson('/api/v1/account/subdomain', ['subdomain' => 'admin'])->assertStatus(422);
    $this->withToken(tokenFor($ana))->putJson('/api/v1/account/subdomain', ['subdomain' => 'ana-m'])
        ->assertOk()->assertJsonPath('data.url', 'https://ana-m.'.BASE);
    $this->withToken(tokenFor($bia))->putJson('/api/v1/account/subdomain', ['subdomain' => 'ana-m'])->assertStatus(422);
    $this->withToken(tokenFor($ana))->putJson('/api/v1/account/subdomain', ['subdomain' => null])->assertOk();
});

it('profissional nao define subdominio da clinica; admin define', function () {
    $c = clinic();
    $this->withToken(tokenFor(professional($c)))->putJson('/api/v1/account/subdomain', ['subdomain' => 'clin-x'])->assertNotFound();
    $this->withToken(tokenFor(clinicAdmin($c)))->putJson('/api/v1/account/subdomain', ['subdomain' => 'clin-x'])->assertOk();
});

it('cadastro em subdominio de clinica nao e aberto', function () {
    clinic('clinica-a');
    $this->postJson(onHost('clinica-a', '/api/v1/auth/register'), ['name' => 'X', 'email' => 'x@ex.test', 'password' => 'senha-muito-boa-1', 'device_name' => 't'])
        ->assertNotFound();
});
