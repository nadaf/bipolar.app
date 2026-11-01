<?php

namespace App\Domain\Account;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Passwords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Desbloqueio extra (10 min) para modulos privados, exportacao e exclusao de conta.
 * Web: marca na sessao. API: token curto enviado em X-Step-Up (guardado so como hash no cache).
 */
final class StepUp
{
    public const TTL = 600;

    public function check(User $user, string $method, string $secret): bool
    {
        $ok = match ($method) {
            'password' => Passwords::check($secret, $user->password),
            'pin' => $user->pin_hash !== null && Hash::check($secret, $user->pin_hash),
            default => false,
        };
        AuditLog::create(['actor_id' => $user->id, 'patient_id' => null, 'action' => $ok ? 'stepup.ok' : 'stepup.fail', 'subject_type' => 'user', 'subject_id' => $user->id]);

        return $ok;
    }

    public function markSession(Request $request): void
    {
        $request->session()->put('stepup_until', time() + self::TTL);
    }

    public function issueToken(User $user): string
    {
        $token = Str::random(48);
        Cache::put('stepup:'.hash('sha256', $token), $user->id, self::TTL);

        return $token;
    }

    /** Vale para web (sessao) ou API (cabecalho X-Step-Up). */
    public function active(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }
        if ($request->hasSession() && (int) $request->session()->get('stepup_until', 0) > time()) {
            return true;
        }
        $t = (string) $request->header('X-Step-Up', '');

        return $t !== '' && (int) Cache::get('stepup:'.hash('sha256', $t)) === $user->id;
    }

    public function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget('stepup_until');
        }
    }
}
