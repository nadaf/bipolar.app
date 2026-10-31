<?php

use App\Domain\Push\PushSender;
use App\Domain\Push\ReminderDispatcher;
use App\Models\PushSubscription;
use App\Tenancy\Exceptions\TenantMismatch;
use Carbon\CarbonImmutable;

/** Envio falso: registra quem receberia e com qual texto. */
class FakePushSender implements PushSender
{
    /** @var list<array{id: int, payload: array<string, string>}> */
    public array $sent = [];

    /** @var list<int> */
    public array $expire = [];

    public function enabled(): bool
    {
        return true;
    }

    public function send(array $subs): array
    {
        foreach ($subs as $s) {
            $this->sent[] = ['id' => $s->id, 'payload' => self::PAYLOAD];
        }

        return array_values(array_intersect($this->expire, array_map(fn ($s) => $s->id, $subs)));
    }
}

function subPayload(string $tag = 'a'): array
{
    // chaves de inscricao ficticias geradas na hora (nada de string com cara de segredo no repositorio)
    $b64 = fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

    return ['endpoint' => "https://push.example.test/{$tag}", 'keys' => ['p256dh' => $b64("\x04".str_repeat("\x11", 64)), 'auth' => $b64(str_repeat("\x22", 16))]];
}

function fakeSender(): FakePushSender
{
    $f = new FakePushSender;
    app()->instance(PushSender::class, $f);

    return $f;
}

/** Grade com lembrete as 21:00 de todo dia, no periodo da noite. */
function nightGrid($p): void
{
    $days = array_map(fn ($w) => ['weekday' => $w, 'slots' => [['time' => '21:00', 'period' => 'noite', 'channel' => 'push']]], range(0, 6));
    test()->actingAs($p)->withToken(tokenFor($p))->putJson('/api/v1/reminders', ['days' => $days])->assertOk();
}

it('paciente ativa e desativa notificacoes do aparelho', function () {
    $p = patient();
    $this->actingAs($p)->postJson('/alertas/push', subPayload())->assertCreated()->assertJsonPath('devices', 1);
    $this->actingAs($p)->postJson('/alertas/push', subPayload())->assertCreated()->assertJsonPath('devices', 1); // idempotente
    $this->actingAs($p)->postJson('/alertas/push/remover', ['endpoint' => subPayload()['endpoint']])->assertOk()->assertJsonPath('devices', 0);
});

it('inscricao exige endpoint https e chaves validas', function () {
    $p = patient();
    $this->actingAs($p)->postJson('/alertas/push', ['endpoint' => 'http://x.test/a', 'keys' => ['p256dh' => 'x', 'auth' => 'y']])->assertUnprocessable();
    $this->actingAs($p)->postJson('/alertas/push', ['endpoint' => 'https://x.test/a', 'keys' => ['p256dh' => '<script>', 'auth' => 'y']])->assertUnprocessable();
});

it('isolamento: inscricao fica no tenant do paciente e nao e vista nem removida por outro', function () {
    $a = patient();
    $b = patient();
    $this->actingAs($a)->postJson('/alertas/push', subPayload('a'))->assertCreated();
    expect(asTenant(tenantOf($a), fn () => PushSubscription::count()))->toBe(1)
        ->and(asTenant(tenantOf($b), fn () => PushSubscription::count()))->toBe(0);
    // b tenta remover o endpoint de a: nada acontece
    $this->actingAs($b)->postJson('/alertas/push/remover', ['endpoint' => subPayload('a')['endpoint']])->assertOk()->assertJsonPath('devices', 0);
    expect(asTenant(tenantOf($a), fn () => PushSubscription::count()))->toBe(1);
    // gravar no tenant errado e bloqueado pelo trait
    $s = asTenant(tenantOf($a), fn () => PushSubscription::first());
    expect(fn () => asTenant(tenantOf($b), fn () => $s->forceFill(['last_slot' => 'x'])->save()))->toThrow(TenantMismatch::class);
});

it('isolamento: mesmo aparelho em pacientes da mesma clinica fica so com o ultimo dono', function () {
    $c = clinic();
    $a = patient($c->id);
    $b = patient($c->id);
    $this->actingAs($a)->postJson('/alertas/push', subPayload('mesmo'))->assertCreated();
    $this->actingAs($b)->postJson('/alertas/push', subPayload('mesmo'))->assertCreated();
    $owners = asTenant($c->id, fn () => PushSubscription::pluck('user_id')->all());
    expect($owners)->toBe([$b->id]);
});

it('profissional e gestor nao tem rotas de push de paciente (404)', function () {
    $c = clinic('clinica-push');
    foreach ([professional($c), clinicAdmin($c)] as $u) {
        $this->actingAs($u)->postJson(onHost('clinica-push', '/alertas/push'), subPayload())->assertNotFound();
    }
});

it('envio: so no horario, texto neutro, uma vez por periodo, remove expiradas', function () {
    $f = fakeSender();
    $p = patient(null, 'America/Cuiaba');
    nightGrid($p);
    $this->actingAs($p)->postJson('/alertas/push', subPayload('1'))->assertCreated();
    $this->actingAs($p)->postJson('/alertas/push', subPayload('2'))->assertCreated();
    $d = app(ReminderDispatcher::class);

    $antes = CarbonImmutable::parse('2026-10-31 20:58', 'America/Cuiaba');
    expect($d->run($antes)['sent'])->toBe(0);

    $hora = CarbonImmutable::parse('2026-10-31 21:02', 'America/Cuiaba');
    expect($d->run($hora)['sent'])->toBe(2)
        ->and($f->sent[0]['payload'])->toBe(['title' => 'Bipolar', 'body' => 'Hora do seu registro', 'url' => '/registrar', 'tag' => 'lembrete']);
    expect($d->run($hora->addMinutes(3))['sent'])->toBe(0); // ja enviado neste periodo

    $f->expire = asTenant(tenantOf($p), fn () => PushSubscription::orderBy('id')->limit(1)->pluck('id')->all());
    $r = $d->run($hora->addDay());
    expect($r['expired'])->toBe(1)->and($r['sent'])->toBe(1)
        ->and(asTenant(tenantOf($p), fn () => PushSubscription::count()))->toBe(1);
});

it('envio desligado sem chaves VAPID; comando roda pelo agendador', function () {
    config(['bipolar.vapid.public' => '', 'bipolar.vapid.private' => '']);
    $this->artisan('bipolar:send-reminders')->expectsOutputToContain('enviados 0')->assertSuccessful();
    $this->artisan('schedule:list')->expectsOutputToContain('bipolar:send-reminders')->assertSuccessful();
});

it('payload fixo nao contem dado de saude', function () {
    $txt = mb_strtolower(json_encode(PushSender::PAYLOAD, JSON_UNESCAPED_UNICODE));
    foreach (['humor', 'energia', 'sono', 'mania', 'depress', 'remédio', 'medica', 'alerta', 'crise'] as $w) {
        expect($txt)->not->toContain($w);
    }
    expect(file_get_contents(public_path('push-sw.js')))->toContain("body: 'Hora do seu registro'");
});

it('alertas mostram ativacao de notificacoes e nao dizem mais que nao envia', function () {
    config(['bipolar.vapid.public' => 'BPUBLICA_TESTE']);
    $this->actingAs(patient())->get('/alertas')->assertOk()
        ->assertSee('x-data="pushAparelho"', false)->assertSee('data-vapid="BPUBLICA_TESTE"', false)
        ->assertSee('Hora do seu registro')->assertDontSee('não envia');
});

it('manifesto tem atalhos com icones e a pagina offline existe', function () {
    $m = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
    expect(array_column($m['shortcuts'], 'url'))->toBe(['/registrar', '/historico', '/relatorios']);
    foreach ($m['shortcuts'] as $s) {
        expect(file_exists(public_path(ltrim($s['icons'][0]['src'], '/'))))->toBeTrue();
    }
    expect(file_get_contents(public_path('offline.html')))->toContain('Tentar de novo')->not->toContain('<script')->not->toContain('style=');
});

it('layout tem indicador de rede acessivel', function () {
    $this->actingAs(patient())->get('/registrar')->assertOk()->assertSee('id="rede"', false)->assertSee('aria-live="polite"', false);
});
