<?php

namespace App\Domain\Care;

use App\Models\AuditLog;
use App\Models\CareGrant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Lado do PACIENTE (no proprio tenant): convites, alteracao, revogacao e auditoria. */
final class CareGrantService
{
    public const KINDS = ['psicologo' => 'Psicólogo(a)', 'psiquiatra' => 'Psiquiatra', 'familiar' => 'Familiar', 'outro' => 'Outra pessoa de confiança'];

    /** nome da API => bit */
    public const SCOPE_NAMES = [
        'humor' => CareScope::MOOD, 'sono' => CareScope::SLEEP, 'estresse_alimentacao' => CareScope::STRESS_FOOD,
        'pensamentos' => CareScope::THOUGHTS, 'substancias' => CareScope::SUBSTANCES, 'sexualidade' => CareScope::SEXUALITY,
        'pensamentos_morte' => CareScope::DEATH, 'religiosidade' => CareScope::RELIGION,
    ];

    public const SCOPE_LABELS = [
        'humor' => 'Humor, energia, ansiedade, medicação e meu dia', 'sono' => 'Sono', 'estresse_alimentacao' => 'Estresse e alimentação',
        'pensamentos' => 'Pensamentos e anotações', 'substancias' => 'Substâncias', 'sexualidade' => 'Sexualidade',
        'pensamentos_morte' => 'Pensamentos de morte', 'religiosidade' => 'Religiosidade',
    ];

    public const INVITE_DAYS = 7;

    /** @return array<string, list<mixed>> */
    public static function rules(bool $create): array
    {
        return [
            'grantee_kind' => [$create ? 'required' : 'sometimes', 'in:'.implode(',', array_keys(self::KINDS))],
            'scope' => [$create ? 'required' : 'sometimes', 'array', 'min:1'],
            'scope.*' => ['string', 'in:'.implode(',', array_keys(self::SCOPE_NAMES))],
            'data_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }

    /** @param list<string> $names */
    public static function mask(array $names): int
    {
        return array_reduce($names, fn (int $m, string $n) => $m | (self::SCOPE_NAMES[$n] ?? 0), 0);
    }

    /** @return list<string> */
    public static function names(int $mask): array
    {
        return array_keys(array_filter(self::SCOPE_NAMES, fn (int $b) => ($mask & $b) === $b));
    }

    public static function needsStepUp(int $mask): bool
    {
        return ($mask & CareScope::SENSITIVE) !== 0;
    }

    /** @return Collection<int, CareGrant> */
    public function list(User $patient): Collection
    {
        return CareGrant::query()->where('patient_id', $patient->id)->orderByDesc('id')->get();
    }

    public function find(User $patient, int $id): ?CareGrant
    {
        return CareGrant::query()->where('patient_id', $patient->id)->whereKey($id)->first();
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array{0: CareGrant, 1: string} concessao + token de convite (mostrado UMA vez)
     */
    public function invite(User $patient, array $v): array
    {
        $token = Str::random(48);
        $g = new CareGrant(['patient_id' => $patient->id, 'grantee_kind' => $v['grantee_kind'], 'scope_mask' => self::mask($v['scope']),
            'data_from' => $v['data_from'] ?? null, 'expires_at' => isset($v['expires_at']) ? Carbon::parse($v['expires_at']) : null]);
        $g->forceFill(['invite_token_hash' => hash('sha256', $token), 'invite_expires_at' => now()->addDays(self::INVITE_DAYS)])->save();
        $this->audit($patient, $g, 'care.invite_created');

        return [$g, $token];
    }

    /** @param array<string, mixed> $v */
    public function update(User $patient, CareGrant $g, array $v): CareGrant
    {
        if (isset($v['scope'])) {
            $g->scope_mask = self::mask($v['scope']);
        }
        if (array_key_exists('expires_at', $v)) {
            $g->expires_at = $v['expires_at'] !== null ? Carbon::parse($v['expires_at']) : null;
        }
        if (array_key_exists('data_from', $v)) {
            $g->data_from = $v['data_from'];
        }
        if (isset($v['grantee_kind'])) {
            $g->setAttribute('grantee_kind', $v['grantee_kind']);
        }
        $g->save();
        $this->audit($patient, $g, 'care.grant_updated');

        return $g;
    }

    public function revoke(User $patient, CareGrant $g): void
    {
        $g->forceFill(['revoked_at' => now(), 'invite_token_hash' => null])->save();
        $this->audit($patient, $g, 'care.grant_revoked');
    }

    /** @return Collection<int, AuditLog> */
    public function audit_log(User $patient, CareGrant $g): Collection
    {
        return AuditLog::query()->where('patient_id', $patient->id)->where('subject_type', 'care_grant')
            ->where('subject_id', $g->id)->orderByDesc('id')->limit(200)->get();
    }

    public static function status(CareGrant $g): string
    {
        return match (true) {
            $g->getAttribute('revoked_at') !== null => 'revogado',
            $g->expires_at !== null && $g->expires_at->isPast() => 'expirado',
            $g->getAttribute('accepted_at') === null => 'pendente',
            default => 'ativo',
        };
    }

    public const STATUS_LABELS = ['pendente' => 'Aguardando aceite', 'ativo' => 'Ativo', 'revogado' => 'Revogado', 'expirado' => 'Expirado'];

    public const ACTION_LABELS = [
        'care.invite_created' => 'Convite criado', 'care.invite_accepted' => 'Convite aceito', 'care.grant_updated' => 'Permissões alteradas',
        'care.grant_revoked' => 'Acesso revogado', 'care.read_mood' => 'Viu os registros de humor', 'care.read_report' => 'Viu o relatório',
    ];

    /** @return array<string, mixed> */
    public static function toApi(CareGrant $g, ?string $granteeName = null): array
    {
        return ['id' => $g->id, 'grantee_kind' => $g->getAttribute('grantee_kind'), 'grantee_name' => $granteeName,
            'scope_mask' => $g->scope_mask, 'scope' => self::names($g->scope_mask), 'status' => self::status($g), 'read_only' => true,
            'data_from' => $g->data_from, 'expires_at' => $g->expires_at?->toIso8601String()];
    }

    private function audit(User $patient, CareGrant $g, string $action): void
    {
        AuditLog::create(['actor_id' => $patient->id, 'patient_id' => $patient->id, 'action' => $action,
            'subject_type' => 'care_grant', 'subject_id' => $g->id, 'scope_mask' => $g->scope_mask]);
    }
}
