<?php

namespace App\Domain\Clinic;

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Admin da clinica gerencia os PROPRIOS profissionais (so no tenant resolvido).
 * Nao da acesso a dados de pacientes: profissional so le o que o paciente conceder.
 */
final class ClinicProfessionals
{
    public function __construct(private TenantContext $ctx) {}

    public function clinicId(): ?int
    {
        $id = $this->ctx->id();

        return Tenant::query()->whereKey($id)->where('kind', Tenant::CLINIC)->exists() ? $id : null;
    }

    /** @return Collection<int, \stdClass> */
    public function list(int $clinicId): Collection
    {
        return DB::table('memberships')->where('memberships.tenant_id', $clinicId)->where('memberships.role', Membership::PROFESSIONAL)
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->orderBy('users.name')->get(['users.id', 'users.name', 'users.email', 'memberships.created_at']);
    }

    /** @return array{0: User|null, 1: bool} usuario e se foi criado agora */
    public function add(User $actor, int $clinicId, string $email): array
    {
        $user = User::where('email', $email)->first();
        if ($user === null) {
            return [null, false];
        }
        $m = Membership::firstOrCreate(['tenant_id' => $clinicId, 'user_id' => $user->id], ['role' => Membership::PROFESSIONAL]);
        if ($m->role !== Membership::PROFESSIONAL) {
            return [null, false];
        }
        if ($m->wasRecentlyCreated) {
            AuditLog::create(['actor_id' => $actor->id, 'action' => 'clinic.professional_added', 'subject_type' => 'user', 'subject_id' => $user->id]);
        }

        return [$user, $m->wasRecentlyCreated];
    }

    public function remove(User $actor, int $clinicId, int $userId): bool
    {
        $m = Membership::query()->where('tenant_id', $clinicId)->where('user_id', $userId)->where('role', Membership::PROFESSIONAL)->first();
        if ($m === null) {
            return false;
        }
        $m->delete();
        AuditLog::create(['actor_id' => $actor->id, 'action' => 'clinic.professional_removed', 'subject_type' => 'user', 'subject_id' => $userId]);

        return true;
    }
}
