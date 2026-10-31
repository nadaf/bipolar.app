<?php

use App\Domain\Mfa\TwoFactor;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

/** Liga a 2FA direto pelo servico; devolve [segredo, codigos de recuperacao]. */
function enableMfa(User $u): array
{
    $mfa = app(TwoFactor::class);
    $secret = $mfa->begin($u);
    $codes = $mfa->confirm($u->fresh(), app(Google2FA::class)->getCurrentOtp($secret));
    expect($codes)->toHaveCount(10);
    // proximo codigo valido precisa ser de um passo NOVO (anti-reuso): libera o passo atual para os testes
    $u->forceFill(['mfa_last_step' => $u->fresh()->mfa_last_step - 2])->save();

    return [$secret, $codes];
}

function otp(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

it('configura pela tela: senha, QR SVG do servidor (sem servico externo), confirma e mostra 10 codigos uma vez', function () {
    $p = patient();
    $this->actingAs($p)->get('/conta')->assertOk()->assertSee('Ligar verificação em duas etapas');
    $this->actingAs($p)->post('/conta/verificacao/iniciar', ['password' => 'errada'])->assertSessionHasErrors('password');
    $this->actingAs($p)->post('/conta/verificacao/iniciar', ['password' => 'senha-muito-boa-1'])->assertRedirect('/conta/verificacao');
    $html = $this->actingAs($p)->get('/conta/verificacao')->assertOk()->getContent();
    expect($html)->toContain('<svg role="img" aria-label="Código QR')
        ->and($html)->not->toContain('chart.googleapis')->and($html)->not->toContain('api.qrserver')
        ->and($html)->not->toMatch('/<img[^>]+src="https?:/');
    $secret = $p->fresh()->mfa_totp_secret;
    $raw = DB::table('users')->where('id', $p->id)->value('mfa_totp_secret');
    expect($raw)->not->toContain($secret); // criptografado em repouso

    $this->actingAs($p)->post('/conta/verificacao/confirmar', ['code' => '000000'])->assertSessionHasErrors('code');
    $r = $this->actingAs($p)->post('/conta/verificacao/confirmar', ['code' => otp($secret)]);
    $r->assertRedirect('/conta/verificacao')->assertSessionHas('mfa_codes');
    $codes = session('mfa_codes');
    $rawCodes = (string) DB::table('users')->where('id', $p->id)->value('mfa_recovery_codes');
    foreach ($codes as $c) {
        expect($rawCodes)->not->toContain($c);
    }
    expect($p->fresh()->mfaOn())->toBeTrue()
        ->and(asTenant(tenantOf($p), fn () => AuditLog::where('action', 'mfa.enabled')->count()))->toBe(1);
});

it('login com 2FA: senha sozinha nao autentica; codigo certo entra; mesmo codigo nao vale duas vezes', function () {
    $p = patient();
    [$secret] = enableMfa($p);
    $this->post('/entrar', ['email' => $p->email, 'password' => 'senha-muito-boa-1'])->assertRedirect('/entrar/verificacao');
    $this->assertGuest();
    $this->get('/registrar')->assertRedirect('/entrar');
    $this->get('/entrar/verificacao')->assertOk()->assertSee('autocomplete="one-time-code"', false);
    $this->post('/entrar/verificacao', ['code' => '123456'])->assertSessionHasErrors('code');
    $this->assertGuest();
    $code = otp($secret);
    $this->post('/entrar/verificacao', ['code' => $code])->assertRedirect('/registrar'); // volta para onde ia
    $this->assertAuthenticatedAs($p);

    $this->post('/sair');
    $this->post('/entrar', ['email' => $p->email, 'password' => 'senha-muito-boa-1']);
    $this->post('/entrar/verificacao', ['code' => $code])->assertSessionHasErrors('code'); // reuso bloqueado
    $this->assertGuest();
});

it('codigo de recuperacao vale uma unica vez', function () {
    $p = patient();
    [, $codes] = enableMfa($p);
    $this->post('/entrar', ['email' => $p->email, 'password' => 'senha-muito-boa-1']);
    $this->post('/entrar/verificacao', ['code' => strtoupper($codes[0])])->assertRedirect('/');
    $this->assertAuthenticatedAs($p);
    expect(app(TwoFactor::class)->remainingRecoveryCodes($p->fresh()))->toBe(9);
    $this->post('/sair');
    $this->post('/entrar', ['email' => $p->email, 'password' => 'senha-muito-boa-1']);
    $this->post('/entrar/verificacao', ['code' => $codes[0]])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('limita tentativas: 5 erros bloqueiam ate o codigo certo por 10 minutos', function () {
    $p = patient();
    [$secret] = enableMfa($p);
    $this->post('/entrar', ['email' => $p->email, 'password' => 'senha-muito-boa-1']);
    for ($i = 0; $i < 5; $i++) {
        $this->post('/entrar/verificacao', ['code' => '000000']);
    }
    $this->post('/entrar/verificacao', ['code' => otp($secret)])->assertSessionHasErrors('code');
    $this->assertGuest();
    RateLimiter::clear('mfa:'.$p->id);
});

it('desafio expira e nao existe sem senha antes', function () {
    $this->get('/entrar/verificacao')->assertNotFound();
    $this->post('/entrar/verificacao', ['code' => '123456'])->assertRedirect('/entrar');
});

it('API: login devolve mfa_token; segunda etapa devolve token; token adulterado falha', function () {
    $p = patient();
    [$secret] = enableMfa($p);
    $r = $this->postJson('/api/v1/auth/login', ['email' => $p->email, 'password' => 'senha-muito-boa-1', 'device_name' => 't'])
        ->assertOk()->assertJsonPath('mfa_required', true)->assertJsonMissingPath('token');
    $mt = $r->json('mfa_token');
    $this->postJson('/api/v1/auth/mfa/totp', ['mfa_token' => $mt.'x', 'code' => otp($secret)])->assertUnprocessable();
    $this->postJson('/api/v1/auth/mfa/totp', ['mfa_token' => $mt, 'code' => '000000'])->assertUnprocessable();
    $tok = $this->postJson('/api/v1/auth/mfa/totp', ['mfa_token' => $mt, 'code' => otp($secret)])->assertOk()->json('token');
    $this->withToken($tok)->getJson('/api/v1/account/mfa')->assertOk()->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.recovery_codes_left', 10);
});

it('API: configura, confirma e desliga', function () {
    $p = patient();
    $t = tokenFor($p);
    $d = $this->withToken($t)->postJson('/api/v1/account/mfa/setup', ['password' => 'senha-muito-boa-1'])->assertCreated()->json('data');
    expect($d['qr_svg'])->toStartWith('<svg')->and($d['otpauth_uri'])->toStartWith('otpauth://totp/');
    $codes = $this->withToken($t)->postJson('/api/v1/account/mfa/confirm', ['code' => otp($d['secret'])])->assertOk()->json('data.recovery_codes');
    expect($codes)->toHaveCount(10);
    $this->withToken($t)->deleteJson('/api/v1/account/mfa', ['password' => 'senha-muito-boa-1', 'code' => $codes[1]])->assertNoContent();
    expect($p->fresh()->mfaOn())->toBeFalse()->and($p->fresh()->mfa_totp_secret)->toBeNull();
});

it('clinica exige 2FA: profissional sem 2FA so acessa a configuracao; paciente nao e afetado; nao pode desligar', function () {
    $c = clinic('clinica-2fa');
    $admin = clinicAdmin($c);
    $pro = professional($c);
    $host = 'http://clinica-2fa.'.config('bipolar.base_domain');
    $this->actingAs($admin)->withSession(['tenant_id' => $c->id])->post($host.'/clinica/seguranca', ['require_mfa' => '1'])->assertRedirect();
    expect($c->fresh()->require_mfa)->toBeTrue();

    $this->actingAs($pro)->withSession(['tenant_id' => $c->id])->get($host.'/cuidado')->assertRedirect(route('mfa.setup'));
    $this->actingAs($pro)->withSession(['tenant_id' => $c->id])->get($host.'/conta/verificacao')->assertOk()->assertSee('exige');
    $this->withToken(tokenFor($pro, $c->id))->getJson($host.'/api/v1/care/patients')->assertForbidden()->assertJsonPath('mfa_setup_required', true);
    $this->withToken(tokenFor($pro, $c->id))->getJson($host.'/api/v1/account/mfa')->assertOk()->assertJsonPath('data.required_by_clinic', true);

    [$secret] = enableMfa($pro);
    $this->actingAs($pro->fresh())->withSession(['tenant_id' => $c->id])->get($host.'/cuidado')->assertOk();
    $this->actingAs($pro->fresh())->withSession(['tenant_id' => $c->id])
        ->post($host.'/conta/verificacao/desligar', ['password' => 'senha-muito-boa-1', 'code' => otp($secret)])->assertSessionHasErrors('password');
    expect($pro->fresh()->mfaOn())->toBeTrue();
});

it('paciente de clinica que exige 2FA nao e afetado', function () {
    $c = clinic('clinica-pac');
    $c->forceFill(['require_mfa' => true])->save();
    $p = patient($c->id);
    $this->actingAs($p)->withSession(['tenant_id' => $c->id])->get('http://clinica-pac.'.config('bipolar.base_domain').'/registrar')->assertOk();
});

it('so admin da propria clinica muda a exigencia', function () {
    $c = clinic('clinica-adm');
    $pro = professional($c);
    $host = 'http://clinica-adm.'.config('bipolar.base_domain');
    $this->actingAs($pro)->withSession(['tenant_id' => $c->id])->post($host.'/clinica/seguranca', ['require_mfa' => '1'])->assertNotFound();
    expect($c->fresh()->require_mfa)->toBeFalse();
});

it('API: admin muda a exigencia so da propria clinica', function () {
    $c = clinic('clinica-api');
    $other = clinic('outra-api');
    $this->withToken(tokenFor(clinicAdmin($other), $other->id))->putJson('/api/v1/clinic/settings', ['require_mfa' => true])->assertOk();
    expect($c->fresh()->require_mfa)->toBeFalse()->and($other->fresh()->require_mfa)->toBeTrue();
});
