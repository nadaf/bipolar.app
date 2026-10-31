<?php

namespace App\Http\Controllers\Web;

use App\Domain\Clinic\ClinicInvites;
use App\Domain\Mfa\TwoFactor;
use App\Http\Controllers\Controller;
use App\Models\ClinicInvite;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Aceite do convite de clinica (no host da clinica). Conta existente: senha (+ codigo 2FA). Nova: nome + senha. */
class ClinicInviteController extends Controller
{
    public function show(Request $request, ClinicInvites $svc, string $token)
    {
        $inv = $this->invite($svc, $token);
        $existing = User::query()->where('email', $inv->email)->first();

        return view('clinic.invite', ['inv' => $inv, 'clinic' => $svc->clinicName($inv), 'token' => $token,
            'hasAccount' => $existing !== null, 'needsCode' => $existing?->mfaOn() ?? false]);
    }

    public function accept(Request $request, ClinicInvites $svc, TwoFactor $mfa, string $token)
    {
        $inv = $this->invite($svc, $token);
        $user = User::query()->where('email', $inv->email)->first();
        if ($user === null) {
            $v = $request->validate(['name' => ['required', 'string', 'min:2', 'max:150'], 'password' => ['required', 'confirmed', Password::min(10)]]);
            $user = $svc->register($inv, $v['name'], $v['password']);
            abort_if($user === null, 404);
        } else {
            $v = $request->validate(['password' => ['required', 'string'], 'code' => ['nullable', 'string', 'max:20']]);
            if (! Hash::check($v['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => 'Senha incorreta.']);
            }
            if ($user->mfaOn() && ! $mfa->verify($user, (string) ($v['code'] ?? ''))) {
                throw ValidationException::withMessages(['code' => 'Código incorreto ou vencido.']);
            }
            abort_unless($svc->accept($inv, $user), 404);
        }
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $inv->tenant_id);

        return redirect('/')->with('ok', 'Convite aceito. Você agora faz parte da equipe de '.$svc->clinicName($inv).'.');
    }

    private function invite(ClinicInvites $svc, string $token): ClinicInvite
    {
        $inv = $svc->find($token);
        abort_if($inv === null, 404);

        return $inv;
    }
}
