<?php

namespace App\Domain\Mfa;

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Verificacao em duas etapas por TOTP (RFC 6238, apps como Google Authenticator, Aegis, 1Password).
 * - Segredo criptografado no banco (cast encrypted); QR gerado no servidor como SVG (nada sai para terceiros).
 * - Codigo TOTP nao pode ser reutilizado (mfa_last_step); janela de +-1 passo (30 s).
 * - 10 codigos de recuperacao de uso unico, guardados so como HMAC (e o conjunto ainda criptografado).
 * - Limite: 5 tentativas erradas por usuario a cada 10 minutos (alem do throttle da rota).
 */
final class TwoFactor
{
    public const ISSUER = 'Bipolar';

    public const MAX_ATTEMPTS = 5;

    public const DECAY = 600;

    public function __construct(private Google2FA $g2fa, private TenantContext $ctx) {}

    /** Inicia (ou reinicia) a configuracao: segredo novo, ainda desligado. */
    public function begin(User $u): string
    {
        $secret = $this->g2fa->generateSecretKey(32);
        $u->forceFill(['mfa_totp_secret' => $secret, 'mfa_enabled_at' => null, 'mfa_recovery_codes' => null, 'mfa_last_step' => null])->save();

        return $secret;
    }

    public function otpauthUri(User $u): string
    {
        return $this->g2fa->getQRCodeUrl(self::ISSUER, $u->email, (string) $u->mfa_totp_secret);
    }

    /** QR em SVG puro (sem script, sem estilo inline), seguro para embutir sob a CSP. */
    public function qrSvg(User $u): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($this->otpauthUri($u));
        $svg = (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);

        return str_replace('<svg ', '<svg role="img" aria-label="Código QR para o app autenticador" class="qr" ', $svg);
    }

    /** Confirma o primeiro codigo e liga. @return list<string>|null codigos de recuperacao (mostrar uma vez) */
    public function confirm(User $u, string $code): ?array
    {
        if ($u->mfa_totp_secret === null || $u->mfa_enabled_at !== null || ! $this->checkTotp($u, $code)) {
            return null;
        }
        $codes = $this->newRecoveryCodes($u);
        $u->forceFill(['mfa_enabled_at' => now()])->save();
        $this->audit($u, 'mfa.enabled');

        return $codes;
    }

    /** @return list<string> */
    public function newRecoveryCodes(User $u): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = strtolower(Str::random(5).'-'.Str::random(5));
        }
        $u->forceFill(['mfa_recovery_codes' => array_map(fn ($c) => $this->hmac($c), $codes)])->save();

        return $codes;
    }

    public function remainingRecoveryCodes(User $u): int
    {
        return count($u->mfa_recovery_codes ?? []);
    }

    public function disable(User $u): void
    {
        $u->forceFill(['mfa_totp_secret' => null, 'mfa_enabled_at' => null, 'mfa_recovery_codes' => null, 'mfa_last_step' => null])->save();
        $this->audit($u, 'mfa.disabled');
    }

    /** Codigo do app OU codigo de recuperacao (que e consumido). Respeita o limite de tentativas. */
    public function verify(User $u, string $code): bool
    {
        $key = 'mfa:'.$u->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return false;
        }
        $code = trim($code);
        $ok = preg_match('/^\d{6}$/', str_replace(' ', '', $code)) === 1
            ? $this->checkTotp($u, str_replace(' ', '', $code))
            : $this->useRecoveryCode($u, $code);
        if ($ok) {
            RateLimiter::clear($key);

            return true;
        }
        RateLimiter::hit($key, self::DECAY);

        return false;
    }

    public function lockedOut(User $u): bool
    {
        return RateLimiter::tooManyAttempts('mfa:'.$u->id, self::MAX_ATTEMPTS);
    }

    /** Alguma clinica onde a pessoa e profissional/admin exige 2FA? (no tenant indicado, ou em qualquer um) */
    public function required(User $u, ?int $tenantId = null): bool
    {
        $q = Membership::query()->where('user_id', $u->id)->whereIn('role', [Membership::PROFESSIONAL, Membership::ADMIN]);
        if ($tenantId !== null) {
            $q->where('tenant_id', $tenantId);
        }

        return Tenant::query()->whereIn('id', $q->pluck('tenant_id'))->where('require_mfa', true)->exists();
    }

    private function checkTotp(User $u, string $code): bool
    {
        if ($u->mfa_totp_secret === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }
        $step = $this->g2fa->verifyKeyNewer((string) $u->mfa_totp_secret, $code, $u->mfa_last_step ?? 0, 1);
        if (! is_int($step)) {
            return false;
        }
        $u->forceFill(['mfa_last_step' => $step])->save();

        return true;
    }

    private function useRecoveryCode(User $u, string $code): bool
    {
        $h = $this->hmac(strtolower($code));
        $left = $u->mfa_recovery_codes ?? [];
        $i = array_search($h, $left, true);
        if ($i === false || $u->mfa_enabled_at === null) {
            return false;
        }
        unset($left[$i]);
        $u->forceFill(['mfa_recovery_codes' => array_values($left)])->save();
        $this->audit($u, 'mfa.recovery_used');

        return true;
    }

    private function hmac(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function audit(User $u, string $action): void
    {
        $tid = $this->ctx->has() ? $this->ctx->id() : Membership::defaultFor($u->id)?->tenant_id;
        if ($tid === null) {
            return;
        }
        $this->ctx->run((int) $tid, fn () => AuditLog::create(['actor_id' => $u->id, 'action' => $action, 'subject_type' => 'user', 'subject_id' => $u->id]));
    }
}
