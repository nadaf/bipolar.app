<?php

namespace App\Domain\Care;

use App\Domain\Reports\ReportBuilder;
use App\Models\AuditLog;
use App\Models\CareGrant;
use App\Models\MoodEntry;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Support\Collection;

/**
 * UNICO ponto que cruza tenants (EXCECAO documentada em SECURITY-MULTITENANT.md):
 * o profissional/familiar so alcanca dados de pacientes que emitiram concessao ATIVA para ELE
 * (grantee_user_id), na categoria pedida. Leitura e feita DENTRO do tenant do paciente e auditada.
 */
final class GrantDirectory
{
    public function __construct(private TenantContext $ctx) {}

    /** @return Collection<int, CareGrant> */
    public function activeFor(User $grantee): Collection
    {
        return CareGrant::withoutGlobalScope(TenantScope::class)
            ->where('grantee_user_id', $grantee->id)->active()->orderBy('id')->get();
    }

    public function find(User $grantee, int $patientId, int $bit): ?CareGrant
    {
        return $this->activeFor($grantee)->first(fn (CareGrant $g) => $g->patient_id === $patientId && $g->allows($bit));
    }

    /** @return Collection<int, MoodEntry>|null null => sem concessao (controller responde 404) */
    public function moodEntries(User $grantee, int $patientId): ?Collection
    {
        $g = $this->find($grantee, $patientId, CareScope::MOOD);
        if ($g === null) {
            return null;
        }

        return $this->ctx->run($g->tenant_id, function () use ($g, $grantee) {
            $q = MoodEntry::query()->where('patient_id', $g->patient_id)
                ->orderByDesc('entry_date')->orderByDesc('id')->limit(200);
            if ($g->data_from !== null) {
                $q->where('entry_date', '>=', $g->data_from);
            }
            $rows = $q->get();
            if (! $g->allows(CareScope::THOUGHTS)) {
                $rows->each(fn (MoodEntry $e) => $e->setAttribute('note', null));
            }
            AuditLog::create(['actor_id' => $grantee->id, 'patient_id' => $g->patient_id, 'action' => 'care.read_mood',
                'subject_type' => 'care_grant', 'subject_id' => $g->id, 'scope_mask' => CareScope::MOOD]);

            return $rows;
        });
    }

    /**
     * Aceite de convite (o convite mora no tenant do paciente; busca pelo HASH do token).
     * Convite vencido, usado, revogado ou do proprio paciente => null (404).
     */
    public function accept(User $grantee, string $token): ?CareGrant
    {
        $g = CareGrant::withoutGlobalScope(TenantScope::class)->where('invite_token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('invite_expires_at', '>', now())->first();
        if ($g === null || $g->patient_id === $grantee->id) {
            return null;
        }

        return $this->ctx->run($g->tenant_id, function () use ($g, $grantee) {
            $g->forceFill(['grantee_user_id' => $grantee->id, 'accepted_at' => now(), 'invite_token_hash' => null])->save();
            AuditLog::create(['actor_id' => $grantee->id, 'patient_id' => $g->patient_id, 'action' => 'care.invite_accepted',
                'subject_type' => 'care_grant', 'subject_id' => $g->id, 'scope_mask' => $g->scope_mask]);

            return $g;
        });
    }

    /**
     * Relatorio do paciente filtrado pelas categorias concedidas (somente leitura, auditado).
     *
     * @return array<string, mixed>|null
     */
    public function report(User $grantee, int $patientId, string $from, string $to): ?array
    {
        $g = $this->find($grantee, $patientId, CareScope::MOOD);
        if ($g === null) {
            return null;
        }
        $patient = User::query()->find($patientId);
        if ($patient === null) {
            return null;
        }
        if ($g->data_from !== null && $from < $g->data_from) {
            $from = $g->data_from;
        }

        return $this->ctx->run($g->tenant_id, function () use ($g, $grantee, $patient, $from, $to) {
            $r = app(ReportBuilder::class)->build($patient, $from, $to, $g->scope_mask);
            AuditLog::create(['actor_id' => $grantee->id, 'patient_id' => $g->patient_id, 'action' => 'care.read_report',
                'subject_type' => 'care_grant', 'subject_id' => $g->id, 'scope_mask' => $g->scope_mask]);

            return $r + ['grant' => $g, 'patient_name' => $patient->name];
        });
    }
}
