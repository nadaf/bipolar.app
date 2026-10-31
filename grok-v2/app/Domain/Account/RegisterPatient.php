<?php

namespace App\Domain\Account;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Paciente independente: usuario + tenant pessoal + vinculo de paciente.
 * Com $clinicTenantId, o paciente entra na clinica (fluxo de convite da clinica).
 */
final class RegisterPatient
{
    public function __invoke(string $name, string $email, string $password, ?int $clinicTenantId = null, ?string $slug = null): User
    {
        return DB::transaction(function () use ($name, $email, $password, $clinicTenantId, $slug) {
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $tenantId = $clinicTenantId ?? Tenant::create(['kind' => Tenant::PERSONAL, 'name' => 'Pessoal', 'slug' => $slug])->id;
            Membership::create(['tenant_id' => $tenantId, 'user_id' => $user->id, 'role' => Membership::PATIENT]);

            return $user;
        });
    }
}
