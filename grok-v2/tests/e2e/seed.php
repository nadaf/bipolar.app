<?php

/**
 * Dados de demonstracao para os testes de navegador (NUNCA rodar em producao).
 * Uso: php tests/e2e/seed.php  (com DB_* apontando para o banco de teste ja migrado)
 */

use App\Domain\Account\RegisterPatient;
use App\Domain\Day\DayBlockService;
use App\Domain\Mood\MoodEntryService;
use App\Models\CareGrant;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

abort_if(app()->environment('production'), 500, 'nao rode em producao');

$pw = 'senha-demo-1234';
$ana = app(RegisterPatient::class)('Ana Souza', 'ana@demo.test', $pw);
$ana->forceFill(['timezone' => 'America/Sao_Paulo', 'modules' => 'substancias,religiosidade,sexualidade,morte'])->save();
$tid = Membership::query()->where('user_id', $ana->id)->value('tenant_id');

$today = CarbonImmutable::now('America/Sao_Paulo')->startOfDay();
$moods = [0, -1, -2, -2, -1, 0, 1, 1, 2, 1, 0, 0, -1, 0, 1, 2, 3, 2, 1, 0, 0, -1, -1, 0, 1, 0, 0, -2, -1, 0];
app(TenantContext::class)->run($tid, function () use ($ana, $today, $moods) {
    $svc = app(MoodEntryService::class);
    $blocks = app(DayBlockService::class);
    foreach ($moods as $i => $m) {
        $d = $today->subDays(29 - $i);
        if ($i === 29) {
            $svc->upsert($ana, ['entry_date' => $d->toDateString(), 'period' => 'manha', 'mood' => $m, 'energy' => 0, 'anxiety' => 1, 'irritability' => 0], null);
            $blocks->save($ana, $d->toDateString(), 'manha', 'sono', ['horas' => 7.5, 'qualidade' => '2'], null, false);

            continue;
        }
        foreach (['manha' => 0, 'tarde' => 1, 'noite' => -1] as $p => $off) {
            if (($i + strlen($p)) % 5 === 0) {
                continue;
            }
            $svc->upsert($ana, ['entry_date' => $d->toDateString(), 'period' => $p, 'mood' => max(-3, min(3, $m + ($p === 'noite' ? $off : 0))),
                'energy' => max(-3, min(3, $m + $off)), 'anxiety' => abs($m) > 1 ? 2 : 1, 'irritability' => $m > 1 ? 2 : 0], null);
        }
        $blocks->save($ana, $d->toDateString(), 'manha', 'sono', ['horas' => 6 + ($i % 4) * 0.5], null, false);
    }
});

$clinic = Tenant::create(['kind' => Tenant::CLINIC, 'name' => 'Clínica Demo', 'slug' => 'consultorio-demo']);
$admin = User::create(['name' => 'Carla Admin', 'email' => 'admin@demo.test', 'password' => $pw]);
Membership::create(['tenant_id' => $clinic->id, 'user_id' => $admin->id, 'role' => Membership::ADMIN]);
$pro = User::create(['name' => 'Dra. Beatriz Lima', 'email' => 'pro@demo.test', 'password' => $pw]);
Membership::create(['tenant_id' => $clinic->id, 'user_id' => $pro->id, 'role' => Membership::PROFESSIONAL]);

app(TenantContext::class)->run($tid, function () use ($ana, $pro) {
    $g = new CareGrant(['patient_id' => $ana->id, 'grantee_user_id' => $pro->id, 'grantee_kind' => 'psicologo', 'scope_mask' => 7]);
    $g->forceFill(['accepted_at' => now()])->save();
    $p = new CareGrant(['patient_id' => $ana->id, 'grantee_kind' => 'familiar', 'scope_mask' => 1]);
    $p->forceFill(['invite_token_hash' => hash('sha256', 'demo-token'), 'invite_expires_at' => now()->addDays(7)])->save();
});

echo "ok\n";
