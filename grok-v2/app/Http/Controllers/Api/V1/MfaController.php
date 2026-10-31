<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mfa\TwoFactor;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Configuracao da verificacao em duas etapas (mesmas regras da tela /conta/verificacao). */
class MfaController extends Controller
{
    public function show(Request $request, TwoFactor $mfa)
    {
        $u = $request->user();

        return ['data' => ['enabled' => $u->mfaOn(), 'enabled_at' => $u->mfa_enabled_at?->toIso8601String(),
            'recovery_codes_left' => $mfa->remainingRecoveryCodes($u), 'required_by_clinic' => $mfa->required($u)]];
    }

    public function setup(Request $request, TwoFactor $mfa)
    {
        $u = $request->user();
        $this->password($u, $request->validate(['password' => ['required', 'string']])['password']);
        abort_if($u->mfaOn(), 409, 'Já está ligada.');
        $secret = $mfa->begin($u);

        return response()->json(['data' => ['secret' => $secret, 'otpauth_uri' => $mfa->otpauthUri($u), 'qr_svg' => $mfa->qrSvg($u)]], 201);
    }

    public function confirm(Request $request, TwoFactor $mfa)
    {
        $codes = $mfa->confirm($request->user(), $request->validate(['code' => ['required', 'digits:6']])['code']);
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'Código incorreto ou vencido.']);
        }

        return ['data' => ['recovery_codes' => $codes]];
    }

    public function recoveryCodes(Request $request, TwoFactor $mfa)
    {
        $u = $request->user();
        $v = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:20']]);
        $this->password($u, $v['password']);
        abort_unless($u->mfaOn(), 404);
        $this->code($mfa, $u, $v['code']);

        return ['data' => ['recovery_codes' => $mfa->newRecoveryCodes($u)]];
    }

    public function destroy(Request $request, TwoFactor $mfa)
    {
        $u = $request->user();
        $v = $request->validate(['password' => ['required', 'string'], 'code' => ['nullable', 'string', 'max:20']]);
        $this->password($u, $v['password']);
        abort_if($mfa->required($u), 403, 'Sua clínica exige a verificação em duas etapas.');
        if ($u->mfaOn()) {
            $this->code($mfa, $u, (string) ($v['code'] ?? ''));
        }
        $mfa->disable($u);

        return response()->noContent();
    }

    private function password(User $u, string $p): void
    {
        if (! Hash::check($p, $u->password)) {
            throw ValidationException::withMessages(['password' => 'Senha incorreta.']);
        }
    }

    private function code(TwoFactor $mfa, User $u, string $code): void
    {
        if (! $mfa->verify($u, $code)) {
            throw ValidationException::withMessages(['code' => 'Código incorreto.']);
        }
    }
}
