<?php

namespace App\Domain\Account;

use App\Models\Membership;
use App\Models\User;
use App\Tenancy\HostTenant;
use Illuminate\Validation\ValidationException;

/**
 * Emite token Sanctum AMARRADO a um tenant: o do host (subdominio) ou o pedido no login,
 * desde que o usuario tenha vinculo. Sem vinculo => mesma mensagem de senha errada.
 */
final class IssueToken
{
    public function __construct(private HostTenant $host) {}

    public function tenantFor(User $user, ?int $requested = null): Membership
    {
        $wanted = $this->host->tenantId ?? $requested;
        if ($this->host->tenantId !== null && $requested !== null && $requested !== $this->host->tenantId) {
            $wanted = -1;
        }
        $m = Membership::defaultFor($user->id, $wanted);
        if ($m === null) {
            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos.']);
        }

        return $m;
    }

    public function __invoke(User $user, string $device, ?int $requested = null): string
    {
        $m = $this->tenantFor($user, $requested);
        $t = $user->createToken($device, [$m->role]);
        $t->accessToken->forceFill(['tenant_id' => $m->tenant_id])->save();

        return $t->plainTextToken;
    }
}
