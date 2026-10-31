<?php

namespace App\Domain\Clinic;

use App\Mail\ClinicInviteMail;
use App\Models\AuditLog;
use App\Models\ClinicInvite;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\HostTenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Convite de clinica por e-mail: admin convida profissional.
 * - Token aleatorio de 256 bits; no banco so o sha256 (vazamento do banco nao permite aceitar).
 * - Vale 7 dias, uso unico, revogavel; novo convite para o mesmo e-mail cancela o anterior.
 * - Preso ao e-mail convidado e ao host da clinica (subdominio, se houver).
 * - Envio pelo Mail do Laravel (SMTP do cPanel no .env; nos testes, driver array/log). Texto neutro.
 */
final class ClinicInvites
{
    public const DAYS = 7;

    public function __construct(private TenantContext $ctx, private HostTenant $host) {}

    /** @return array{0: ClinicInvite|null, 1: string|null} convite e token em claro (null se ja e membro) */
    public function invite(User $admin, int $clinicId, string $email): array
    {
        $email = Str::lower(trim($email));
        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null && Membership::query()->where('tenant_id', $clinicId)->where('user_id', $existing->id)->exists()) {
            return [null, null];
        }
        $token = Str::random(48);
        $inv = DB::transaction(function () use ($admin, $email, $token) {
            ClinicInvite::query()->where('email', $email)->pending()->update(['revoked_at' => now()]);
            $inv = ClinicInvite::create(['email' => $email, 'role' => Membership::PROFESSIONAL, 'token_hash' => hash('sha256', $token),
                'invited_by' => $admin->id, 'expires_at' => now()->addDays(self::DAYS)]);
            AuditLog::create(['actor_id' => $admin->id, 'action' => 'clinic.invite_sent', 'subject_type' => 'clinic_invite', 'subject_id' => $inv->id]);

            return $inv;
        });
        $clinic = Tenant::query()->findOrFail($clinicId);
        Mail::to($email)->send(new ClinicInviteMail($clinic->name, $this->url($clinic, $token)));

        return [$inv, $token];
    }

    /** @return Collection<int, ClinicInvite> */
    public function pending(): Collection
    {
        return ClinicInvite::query()->pending()->orderByDesc('id')->get();
    }

    public function revoke(User $admin, int $id): bool
    {
        $inv = ClinicInvite::query()->pending()->find($id);
        if ($inv === null) {
            return false;
        }
        $inv->forceFill(['revoked_at' => now()])->save();
        AuditLog::create(['actor_id' => $admin->id, 'action' => 'clinic.invite_revoked', 'subject_type' => 'clinic_invite', 'subject_id' => $inv->id]);

        return true;
    }

    /** Link no host da clinica (subdominio) ou no host principal, se a clinica nao tiver subdominio. */
    public function url(Tenant $clinic, string $token): string
    {
        $app = (string) config('app.url');
        $scheme = parse_url($app, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($app, PHP_URL_PORT);
        $host = $clinic->slug ? $clinic->slug.'.'.config('bipolar.base_domain') : parse_url($app, PHP_URL_HOST);

        return $scheme.'://'.$host.($port ? ':'.$port : '').'/convite-clinica/'.$token;
    }

    /** Convite valido para ESTE host. null => 404 (vencido, usado, revogado, inexistente ou host errado). */
    public function find(string $token): ?ClinicInvite
    {
        if (strlen($token) !== 48) {
            return null;
        }
        $inv = ClinicInvite::withoutGlobalScope(TenantScope::class)->where('token_hash', hash('sha256', $token))->pending()->first();
        if ($inv === null) {
            return null;
        }
        $slug = Tenant::query()->whereKey($inv->tenant_id)->value('slug');
        $ok = $slug !== null ? $this->host->tenantId === $inv->tenant_id : $this->host->tenantId === null;

        return $ok ? $inv : null;
    }

    public function clinicName(ClinicInvite $inv): string
    {
        return (string) Tenant::query()->whereKey($inv->tenant_id)->value('name');
    }

    /** Aceite (uso unico, com trava). Liga a conta a clinica como profissional. false => nao foi possivel. */
    public function accept(ClinicInvite $inv, User $user): bool
    {
        if (Str::lower($user->email) !== $inv->email) {
            return false;
        }

        return $this->ctx->run($inv->tenant_id, fn () => DB::transaction(function () use ($inv, $user) {
            $fresh = ClinicInvite::query()->whereKey($inv->id)->pending()->lockForUpdate()->first();
            if ($fresh === null) {
                return false;
            }
            $m = Membership::firstOrCreate(['tenant_id' => $inv->tenant_id, 'user_id' => $user->id], ['role' => $inv->role]);
            if ($m->role !== Membership::PROFESSIONAL) {
                return false;
            }
            $fresh->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
            AuditLog::create(['actor_id' => $user->id, 'action' => 'clinic.invite_accepted', 'subject_type' => 'clinic_invite', 'subject_id' => $fresh->id]);

            return true;
        }));
    }

    /** Conta nova a partir do convite (so profissional da clinica; sem tenant pessoal). */
    public function register(ClinicInvite $inv, string $name, string $password): ?User
    {
        if (User::query()->where('email', $inv->email)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($inv, $name, $password) {
            $u = User::create(['name' => $name, 'email' => $inv->email, 'password' => $password]);
            $u->forceFill(['email_verified_at' => now()])->save(); // o link chegou nesse e-mail

            return $this->accept($inv, $u) ? $u : throw new \RuntimeException('convite indisponivel');
        });
    }
}
