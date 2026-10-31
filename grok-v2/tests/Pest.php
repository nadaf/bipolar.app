<?php

use App\Domain\Account\RegisterPatient;
use App\Models\CareGrant;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Arch');
// tests/Saas so existe no CI privado (pacote opcional)
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Saas');

function patient(?int $tenantId = null, string $tz = 'America/Sao_Paulo'): User
{
    $u = app(RegisterPatient::class)('Paciente '.uniqid(), uniqid().'@ex.test', 'senha-muito-boa-1', $tenantId);
    $u->forceFill(['timezone' => $tz])->save();

    return $u;
}

function clinic(?string $slug = null): Tenant
{
    return Tenant::create(['kind' => Tenant::CLINIC, 'name' => 'Clínica '.uniqid(), 'slug' => $slug]);
}

function clinicAdmin(Tenant $clinic): User
{
    $u = User::create(['name' => 'Admin '.uniqid(), 'email' => uniqid().'@adm.test', 'password' => 'senha-muito-boa-1']);
    Membership::create(['tenant_id' => $clinic->id, 'user_id' => $u->id, 'role' => Membership::ADMIN]);

    return $u;
}

/** Concessao ativa emitida pelo paciente para $grantee (dentro do tenant do paciente). */
function grantTo(User $patient, User $grantee, int $mask = 7, array $o = []): CareGrant
{
    return asTenant(tenantOf($patient), function () use ($patient, $grantee, $mask, $o) {
        $g = new CareGrant(['patient_id' => $patient->id, 'grantee_user_id' => $grantee->id, 'grantee_kind' => 'psicologo', 'scope_mask' => $mask]);
        $g->forceFill(array_merge(['accepted_at' => now()], $o))->save();

        return $g;
    });
}

/** Token amarrado ao tenant (como o login emite). */
function tokenFor(User $u, ?int $tenantId = null): string
{
    $m = Membership::defaultFor($u->id, $tenantId);
    $t = $u->createToken('teste', [$m->role]);
    $t->accessToken->forceFill(['tenant_id' => $m->tenant_id])->save();

    return $t->plainTextToken;
}

function professional(Tenant $clinic): User
{
    $u = User::create(['name' => 'Dra. '.uniqid(), 'email' => uniqid().'@pro.test', 'password' => 'senha-muito-boa-1']);
    Membership::create(['tenant_id' => $clinic->id, 'user_id' => $u->id, 'role' => Membership::PROFESSIONAL]);

    return $u;
}

function tenantOf(User $u): int
{
    return (int) Membership::where('user_id', $u->id)->where('role', Membership::PATIENT)->value('tenant_id');
}

function asTenant(int $tenantId, callable $fn): mixed
{
    return app(TenantContext::class)->run($tenantId, $fn);
}

function entryPayload(array $o = []): array
{
    return array_merge(['entry_date' => now('America/Sao_Paulo')->subDay()->toDateString(), 'period' => 'tarde', 'mood' => 0, 'energy' => 0], $o);
}
