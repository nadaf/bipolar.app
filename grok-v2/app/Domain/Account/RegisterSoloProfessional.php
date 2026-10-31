<?php

namespace App\Domain\Account;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Profissional solo: tenant proprio (kind=solo) com um unico profissional, que tambem o administra. */
final class RegisterSoloProfessional
{
    public function __invoke(string $name, string $email, string $password, ?string $slug = null): User
    {
        return DB::transaction(function () use ($name, $email, $password, $slug) {
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $t = Tenant::create(['kind' => Tenant::SOLO, 'name' => $name, 'slug' => $slug]);
            Membership::create(['tenant_id' => $t->id, 'user_id' => $user->id, 'role' => Membership::PROFESSIONAL]);

            return $user;
        });
    }
}
