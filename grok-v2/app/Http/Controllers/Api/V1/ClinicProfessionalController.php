<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Admin da clinica gerencia os PROPRIOS profissionais (so no tenant resolvido).
 * Isso nao da acesso a dados de pacientes: profissional so le o que o paciente conceder.
 */
class ClinicProfessionalController extends Controller
{
    public function __construct(private TenantContext $ctx) {}

    public function index()
    {
        $rows = Membership::query()->where('tenant_id', $this->clinicId())->where('role', Membership::PROFESSIONAL)
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->orderBy('users.name')->get(['users.id', 'users.name', 'users.email', 'memberships.created_at']);

        return ['data' => $rows];
    }

    public function store(Request $request)
    {
        $v = $request->validate(['email' => ['required', 'email']]);
        $tenantId = $this->clinicId();
        $user = User::where('email', $v['email'])->first();
        if ($user === null) {
            // convite por e-mail fica para a proxima etapa (sem envio automatico nesta versao)
            return response()->json(['message' => 'Peça para a pessoa criar a conta primeiro e tente de novo.'], 422);
        }
        $m = Membership::firstOrCreate(['tenant_id' => $tenantId, 'user_id' => $user->id, 'role' => Membership::PROFESSIONAL]);
        AuditLog::create(['actor_id' => $request->user()->id, 'action' => 'clinic.professional_added', 'subject_type' => 'user', 'subject_id' => $user->id]);

        return response()->json(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]], $m->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, int $userId)
    {
        $m = Membership::query()->where('tenant_id', $this->clinicId())->where('user_id', $userId)
            ->where('role', Membership::PROFESSIONAL)->first();
        abort_if($m === null, 404);
        $m->delete();
        AuditLog::create(['actor_id' => $request->user()->id, 'action' => 'clinic.professional_removed', 'subject_type' => 'user', 'subject_id' => $userId]);

        return response()->noContent();
    }

    private function clinicId(): int
    {
        $id = $this->ctx->id();
        abort_unless(Tenant::query()->whereKey($id)->where('kind', Tenant::CLINIC)->exists(), 404);

        return $id;
    }
}
