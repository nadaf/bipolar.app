<?php

namespace App\Domain\Account;

use App\Domain\Day\Blocks;
use App\Models\AlertSetting;
use App\Models\AuditLog;
use App\Models\CareGrant;
use App\Models\DayBlock;
use App\Models\Membership;
use App\Models\MoodEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** Conta do proprio usuario: perfil, modulos, PIN, troca de e-mail em 2 fases, exportacao e exclusao. */
final class AccountService
{
    public const THEMES = ['sistema' => 'Seguir o aparelho', 'claro' => 'Claro', 'escuro' => 'Escuro'];

    public function __construct(private TenantContext $ctx) {}

    /** @return array<string, list<mixed>> */
    public static function profileRules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:150'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'theme' => ['sometimes', 'in:'.implode(',', array_keys(self::THEMES))],
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', 'in:'.implode(',', array_keys(Blocks::MODULES))],
        ];
    }

    /** @param array<string, mixed> $v */
    public function update(User $u, array $v): User
    {
        if (array_key_exists('modules', $v)) {
            $v['modules'] = implode(',', array_values(array_unique((array) $v['modules'])));
        }
        $u->fill(array_intersect_key($v, array_flip(['name', 'timezone', 'theme', 'modules'])))->save();
        $this->audit($u, 'account.updated');

        return $u;
    }

    public function setPin(User $u, ?string $pin): void
    {
        $u->forceFill(['pin_hash' => $pin === null ? null : Hash::make($pin)])->save();
        $this->audit($u, $pin === null ? 'account.pin_removed' : 'account.pin_set');
    }

    /** Fase 1: guarda o novo e-mail pendente e manda link de confirmacao PARA O NOVO endereco (mailer local/log). */
    public function requestEmailChange(User $u, string $newEmail): string
    {
        $token = Str::random(48);
        $u->forceFill(['pending_email' => $newEmail, 'email_change_hash' => hash('sha256', $token), 'email_change_expires_at' => now()->addHour()])->save();
        $url = url('/conta/email/confirmar/'.$token);
        Mail::raw("Para confirmar o novo e-mail da sua conta no Bipolar, abra: $url\nO link vale por 1 hora. Se não foi você, ignore.",
            fn ($m) => $m->to($newEmail)->subject('Confirme seu novo e-mail'));
        $this->audit($u, 'account.email_change_requested');

        return $token;
    }

    /** Fase 2: confirma pelo token (so o dono logado). */
    public function confirmEmailChange(User $u, string $token): bool
    {
        $ok = $u->email_change_hash !== null && hash_equals((string) $u->email_change_hash, hash('sha256', $token))
            && $u->email_change_expires_at?->isFuture() && $u->pending_email !== null
            && ! User::query()->where('email', $u->pending_email)->whereKeyNot($u->id)->exists();
        if (! $ok) {
            return false;
        }
        $u->forceFill(['email' => $u->pending_email, 'pending_email' => null, 'email_change_hash' => null, 'email_change_expires_at' => null])->save();
        $this->audit($u, 'account.email_changed');

        return true;
    }

    /**
     * Exporta TUDO que e do usuario como paciente (todas as suas clinicas/tenants). Inclui sensiveis so se pedido.
     *
     * @return array<string, mixed>
     */
    public function export(User $u, bool $includeSensitive): array
    {
        $out = ['generated_at' => now()->toIso8601String(), 'account' => ['name' => $u->name, 'email' => $u->email, 'timezone' => $u->timezone],
            'include_sensitive' => $includeSensitive, 'tenants' => []];
        $tenants = Membership::query()->where('user_id', $u->id)->where('role', Membership::PATIENT)->pluck('tenant_id');
        foreach ($tenants as $tid) {
            $out['tenants'][] = $this->ctx->run((int) $tid, function () use ($u, $includeSensitive, $tid) {
                $entries = MoodEntry::query()->where('patient_id', $u->id)->orderBy('entry_date')->get()
                    ->map(fn (MoodEntry $e) => ['date' => $e->entry_date->format('Y-m-d'), 'period' => $e->period->value, 'mood' => $e->mood,
                        'energy' => $e->energy, 'anxiety' => $e->anxiety, 'irritability' => $e->irritability, 'sleep_hours' => $e->sleep_hours,
                        'medication_taken' => $e->medication_taken, 'note' => $e->note])->all();
                $blocks = DayBlock::query()->where('patient_id', $u->id)->orderBy('entry_date')->get()
                    ->filter(fn (DayBlock $b) => $includeSensitive || ! Blocks::stepUp($b->block))
                    ->map(fn (DayBlock $b) => ['date' => $b->entry_date->format('Y-m-d'), 'period' => $b->period, 'block' => $b->block, 'data' => $b->data])->values()->all();
                AuditLog::create(['actor_id' => $u->id, 'patient_id' => $u->id, 'action' => 'account.exported', 'subject_type' => 'user', 'subject_id' => $u->id]);

                return ['tenant_id' => (int) $tid, 'entries' => $entries, 'blocks' => $blocks,
                    'alerts' => AlertSetting::query()->where('patient_id', $u->id)->first()?->only(['mood_low', 'mood_high', 'energy_low', 'energy_high', 'reminders'])];
            });
        }

        return $out;
    }

    /**
     * Exclusao definitiva (LGPD art. 18): dados de paciente caem em cascata pelas FKs de membership;
     * tenants pessoais sem outros membros sao apagados.
     */
    public function delete(User $u): void
    {
        DB::transaction(function () use ($u) {
            $personal = Membership::query()->where('user_id', $u->id)->pluck('tenant_id');
            $u->tokens()->delete();
            CareGrant::withoutGlobalScopes()->where('grantee_user_id', $u->id)->update(['revoked_at' => now()]);
            $u->delete();
            foreach ($personal as $tid) {
                $t = Tenant::query()->find($tid);
                if ($t !== null && $t->kind === Tenant::PERSONAL && ! Membership::query()->where('tenant_id', $tid)->exists()) {
                    $t->delete();
                }
            }
        });
    }

    private function audit(User $u, string $action): void
    {
        if ($this->ctx->has()) {
            AuditLog::create(['actor_id' => $u->id, 'patient_id' => null, 'action' => $action, 'subject_type' => 'user', 'subject_id' => $u->id]);
        }
    }
}
