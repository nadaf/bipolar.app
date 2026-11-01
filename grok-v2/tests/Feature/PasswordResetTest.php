<?php

use App\Mail\PasswordResetMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

function resetUrlFrom(): string
{
    $url = null;
    Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $m) use (&$url) {
        $url = $m->url;

        return true;
    });

    return (string) $url;
}

beforeEach(fn () => Mail::fake());

it('/entrar tem o link Esqueci minha senha e o logo', function () {
    $this->get('/entrar')->assertOk()->assertSee('Esqueci minha senha')->assertSee('src="/favicon.svg"', false);
    $this->get('/esqueci-senha')->assertOk()->assertSee('Enviar link');
});

it('resposta neutra: mesma mensagem com e sem conta, e so envia para conta existente', function () {
    $u = patient();
    $a = $this->post('/esqueci-senha', ['email' => 'ninguem@exemplo.test']);
    $a->assertRedirect('/esqueci-senha');
    $msgA = session('ok');
    Mail::assertNothingSent();
    $this->post('/esqueci-senha', ['email' => $u->email])->assertRedirect('/esqueci-senha');
    expect(session('ok'))->toBe($msgA);
    Mail::assertSent(PasswordResetMail::class, fn ($m) => $m->hasTo($u->email));
    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'ninguem2@exemplo.test'])->assertStatus(202)->assertJson(['message' => $msgA]);
});

it('so o hash do token fica no banco; link assinado, uso unico, troca a senha e encerra tokens', function () {
    $u = patient();
    tokenFor($u);
    $this->post('/esqueci-senha', ['email' => $u->email]);
    $url = resetUrlFrom();
    expect($url)->toContain('signature=')->toContain('expires=');
    preg_match('#/redefinir-senha/([A-Za-z0-9]{64})#', $url, $m);
    $token = $m[1];
    $row = DB::table('password_resets')->first();
    expect($row->token_hash)->toBe(hash('sha256', $token))->and(DB::table('password_resets')->where('token_hash', $token)->exists())->toBeFalse();
    // sem assinatura => 403
    $this->get('/redefinir-senha/'.$token)->assertForbidden();
    $this->get($url)->assertOk()->assertSee('Criar senha nova');
    $this->post('/redefinir-senha', ['token' => $token, 'password' => 'senha-muito-boa-1', 'password_confirmation' => 'senha-muito-boa-1'])
        ->assertRedirect('/entrar');
    expect(Hash::check('senha-muito-boa-1', $u->fresh()->password))->toBeTrue()
        ->and($u->tokens()->count())->toBe(0);
    // uso unico
    $this->post('/redefinir-senha', ['token' => $token, 'password' => 'senha-muito-boa-1', 'password_confirmation' => 'senha-muito-boa-1'])->assertStatus(410);
    $this->get($url)->assertStatus(410);
});

it('expira em 60 minutos', function () {
    $u = patient();
    $this->postJson('/api/v1/auth/password/forgot', ['email' => $u->email])->assertStatus(202);
    preg_match('#/redefinir-senha/([A-Za-z0-9]{64})#', resetUrlFrom(), $m);
    $this->travel(61)->minutes();
    $this->postJson('/api/v1/auth/password/reset', ['token' => $m[1], 'password' => 'senha-muito-boa-1', 'password_confirmation' => 'senha-muito-boa-1'])->assertStatus(410);
});

it('API troca a senha com token valido', function () {
    $u = patient();
    $this->postJson('/api/v1/auth/password/forgot', ['email' => $u->email]);
    preg_match('#/redefinir-senha/([A-Za-z0-9]{64})#', resetUrlFrom(), $m);
    $this->postJson('/api/v1/auth/password/reset', ['token' => $m[1], 'password' => 'senha-muito-boa-1', 'password_confirmation' => 'senha-muito-boa-1'])->assertOk();
    expect(Hash::check('senha-muito-boa-1', $u->fresh()->password))->toBeTrue();
});

it('amarrado ao host: token pedido no endereco principal nao vale num subdominio de clinica', function () {
    $u = patient();
    $c = clinic('clinica-pr');
    $this->post('/esqueci-senha', ['email' => $u->email]);
    preg_match('#/redefinir-senha/([A-Za-z0-9]{64})#', resetUrlFrom(), $m);
    $this->postJson('http://clinica-pr.'.config('bipolar.base_domain').'/api/v1/auth/password/reset',
        ['token' => $m[1], 'password' => 'senha-muito-boa-1', 'password_confirmation' => 'senha-muito-boa-1'])->assertStatus(410);
    // e no subdominio da clinica, quem nao e da clinica nao recebe e-mail
    Mail::fake();
    $this->post('http://clinica-pr.'.config('bipolar.base_domain').'/esqueci-senha', ['email' => $u->email]);
    Mail::assertNothingSent();
});

it('limite de pedidos por e-mail', function () {
    $u = patient();
    foreach (range(1, 3) as $i) {
        $this->post('/esqueci-senha', ['email' => $u->email])->assertRedirect();
    }
    $this->post('/esqueci-senha', ['email' => $u->email])->assertStatus(429);
});

it('texto do e-mail e neutro', function () {
    $html = (new PasswordResetMail('https://x.test/redefinir-senha/abc'))->render();
    expect($html)->toContain('redefinir a senha')->not->toContain('humor')->not->toContain('bipolar');
});
