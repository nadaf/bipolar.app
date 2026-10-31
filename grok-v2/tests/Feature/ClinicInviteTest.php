<?php

use App\Mail\ClinicInviteMail;
use App\Models\AuditLog;
use App\Models\ClinicInvite;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

function inviteHost(Tenant $c): string
{
    return 'http://'.$c->slug.'.'.config('bipolar.base_domain');
}

/** Admin convida pela tela; devolve o token capturado do e-mail (nada e enviado de verdade: Mail::fake). */
function sendInvite($test, Tenant $c, User $admin, string $email): string
{
    $test->actingAs($admin)->withSession(['tenant_id' => $c->id])->post(inviteHost($c).'/clinica/profissionais', ['email' => $email])->assertRedirect();
    $url = null;
    Mail::assertSent(ClinicInviteMail::class, function (ClinicInviteMail $m) use ($email, &$url) {
        if ($m->hasTo(strtolower($email))) {
            $url = $m->url;
        }

        return $m->hasTo(strtolower($email));
    });
    app('auth')->forgetGuards();

    return substr((string) $url, strrpos((string) $url, '/') + 1);
}

beforeEach(fn () => Mail::fake());

it('admin convida: e-mail neutro com link do host da clinica; banco guarda so o hash; auditado', function () {
    $c = clinic('clinica-conv');
    $admin = clinicAdmin($c);
    $token = sendInvite($this, $c, $admin, 'Nova@Pro.test');
    Mail::assertSent(ClinicInviteMail::class, function (ClinicInviteMail $m) use ($c) {
        $text = view('mail.clinic-invite', ['clinic' => $m->clinic, 'url' => $m->url])->render();
        expect($m->envelope()->subject)->toBe('Convite para a equipe no Bipolar')
            ->and($m->url)->toContain('://'.$c->slug.'.'.config('bipolar.base_domain').'/convite-clinica/')
            ->and($text)->not->toMatch('/humor|paciente|diagn|bipolaridade|crise/i');

        return true;
    });
    expect(strlen($token))->toBe(48)
        ->and(DB::table('clinic_invites')->where('token_hash', hash('sha256', $token))->value('email'))->toBe('nova@pro.test')
        ->and(DB::table('clinic_invites')->where('token_hash', $token)->exists())->toBeFalse()
        ->and(asTenant($c->id, fn () => AuditLog::where('action', 'clinic.invite_sent')->count()))->toBe(1);
    $this->actingAs($admin)->withSession(['tenant_id' => $c->id])->get(inviteHost($c).'/clinica')->assertOk()->assertSee('nova@pro.test')->assertSee('Cancelar convite');
});

it('pessoa sem conta aceita criando a conta; entra ja na clinica como profissional; link e de uso unico', function () {
    $c = clinic('clinica-novo');
    $token = sendInvite($this, $c, clinicAdmin($c), 'novo@pro.test');
    $this->get(inviteHost($c).'/convite-clinica/'.$token)->assertOk()->assertSee('Crie sua conta')->assertSee($c->name);
    $this->post(inviteHost($c).'/convite-clinica/'.$token, ['name' => 'Dra. Nova', 'password' => 'curta', 'password_confirmation' => 'curta'])->assertSessionHasErrors('password');
    $this->post(inviteHost($c).'/convite-clinica/'.$token, ['name' => 'Dra. Nova', 'password' => 'senha-muito-boa-1', 'password_confirmation' => 'senha-muito-boa-1'])->assertRedirect('/');
    $u = User::where('email', 'novo@pro.test')->firstOrFail();
    $this->assertAuthenticatedAs($u);
    expect(Membership::where('user_id', $u->id)->pluck('role', 'tenant_id')->all())->toBe([$c->id => 'professional']);
    $this->post('/sair');
    $this->get(inviteHost($c).'/convite-clinica/'.$token)->assertNotFound();
});

it('conta existente aceita com a senha (e o codigo 2FA, se ligada)', function () {
    $c = clinic('clinica-exist');
    $u = User::create(['name' => 'Dr. Já', 'email' => 'ja@pro.test', 'password' => 'senha-muito-boa-1']);
    $token = sendInvite($this, $c, clinicAdmin($c), 'ja@pro.test');
    $this->get(inviteHost($c).'/convite-clinica/'.$token)->assertOk()->assertSee('Você já tem conta');
    $this->post(inviteHost($c).'/convite-clinica/'.$token, ['password' => 'errada'])->assertSessionHasErrors('password');
    expect(Membership::where('user_id', $u->id)->exists())->toBeFalse();
    $this->post(inviteHost($c).'/convite-clinica/'.$token, ['password' => 'senha-muito-boa-1'])->assertRedirect('/');
    expect(Membership::where('user_id', $u->id)->where('tenant_id', $c->id)->value('role'))->toBe('professional');
});

it('convite vencido, cancelado, substituido ou no host errado da 404', function () {
    $c = clinic('clinica-v');
    $d = clinic('clinica-d');
    $admin = clinicAdmin($c);
    $t1 = sendInvite($this, $c, $admin, 'a@pro.test');
    $this->get('http://'.config('bipolar.base_domain').'/convite-clinica/'.$t1)->assertNotFound();
    $this->get(inviteHost($d).'/convite-clinica/'.$t1)->assertNotFound();
    $t2 = sendInvite($this, $c, $admin, 'a@pro.test'); // novo convite cancela o anterior
    $this->get(inviteHost($c).'/convite-clinica/'.$t1)->assertNotFound();
    $this->get(inviteHost($c).'/convite-clinica/'.$t2)->assertOk();
    asTenant($c->id, fn () => ClinicInvite::query()->update(['expires_at' => now()->subMinute()]));
    $this->get(inviteHost($c).'/convite-clinica/'.$t2)->assertNotFound();
    $t3 = sendInvite($this, $c, $admin, 'b@pro.test');
    $id = asTenant($c->id, fn () => ClinicInvite::where('email', 'b@pro.test')->value('id'));
    $this->actingAs($admin)->withSession(['tenant_id' => $c->id])->post(inviteHost($c).'/clinica/convites/'.$id.'/cancelar')->assertRedirect();
    $this->app['auth']->forgetGuards();
    $this->get(inviteHost($c).'/convite-clinica/'.$t3)->assertNotFound();
});

it('isolamento: admin de outra clinica nao ve nem cancela convites; profissional nao convida', function () {
    $a = clinic('clinica-a1');
    $b = clinic('clinica-b1');
    sendInvite($this, $a, clinicAdmin($a), 'x@pro.test');
    $id = asTenant($a->id, fn () => ClinicInvite::value('id'));
    $adminB = clinicAdmin($b);
    $tb = tokenFor($adminB, $b->id);
    $this->withToken($tb)->getJson(inviteHost($b).'/api/v1/clinic/invites')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($tb)->deleteJson(inviteHost($b).'/api/v1/clinic/invites/'.$id)->assertNotFound();
    expect(asTenant($a->id, fn () => ClinicInvite::pending()->count()))->toBe(1);
    $this->app['auth']->forgetGuards();
    $this->withToken(tokenFor(professional($a), $a->id))->postJson(inviteHost($a).'/api/v1/clinic/invites', ['email' => 'y@pro.test'])->assertNotFound();
});

it('API: convite, aceite com outro e-mail falha, cadastro pelo token devolve token da clinica', function () {
    $c = clinic('clinica-api2');
    $admin = clinicAdmin($c);
    $this->withToken(tokenFor($admin, $c->id))->postJson('/api/v1/clinic/invites', ['email' => 'api@pro.test'])->assertCreated();
    $token = null;
    Mail::assertSent(ClinicInviteMail::class, function ($m) use (&$token) {
        $token = substr($m->url, -48);

        return true;
    });
    $this->app['auth']->forgetGuards();
    $h = inviteHost($c);
    $this->getJson($h.'/api/v1/clinic-invites/'.$token)->assertOk()->assertJsonPath('data.has_account', false);
    $intruso = patient();
    $this->withToken(tokenFor($intruso))->postJson($h.'/api/v1/clinic-invites/'.$token.'/accept')->assertNotFound();
    $this->app['auth']->forgetGuards();
    $t = $this->postJson($h.'/api/v1/clinic-invites/'.$token.'/register', ['name' => 'Dr. API', 'password' => 'senha-muito-boa-1', 'device_name' => 'cel'])
        ->assertCreated()->json('token');
    $this->app['auth']->forgetGuards();
    $this->withToken($t)->getJson($h.'/api/v1/care/patients')->assertOk();
    $this->postJson($h.'/api/v1/clinic-invites/'.$token.'/register', ['name' => 'X', 'password' => 'senha-muito-boa-1', 'device_name' => 'cel'])->assertNotFound();
});

it('membro atual nao recebe convite', function () {
    $c = clinic('clinica-m');
    $admin = clinicAdmin($c);
    $pro = professional($c);
    $this->actingAs($admin)->withSession(['tenant_id' => $c->id])->post(inviteHost($c).'/clinica/profissionais', ['email' => $pro->email])
        ->assertSessionHas('ok', 'Essa pessoa já faz parte da clínica.');
    Mail::assertNothingSent();
});
