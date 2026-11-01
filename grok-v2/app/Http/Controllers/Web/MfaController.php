<?php

namespace App\Http\Controllers\Web;

use App\Domain\Mfa\TwoFactor;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Passwords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Verificacao em duas etapas: desafio no login e configuracao em /conta. */
class MfaController extends Controller
{
    public const PENDING = 'mfa.pending';

    /** Segunda etapa do login (a senha ja foi conferida; ainda NAO ha sessao autenticada). */
    public function challengeForm(Request $request)
    {
        abort_if($this->pending($request) === null, 404);

        return view('auth.mfa');
    }

    public function challenge(Request $request, TwoFactor $mfa)
    {
        $p = $this->pending($request);
        if ($p === null) {
            return redirect()->route('login')->withErrors(['email' => 'Sua tentativa expirou. Entre de novo.']);
        }
        $v = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = User::query()->find($p['user']);
        if ($user === null || ! $user->mfaOn()) {
            $request->session()->forget(self::PENDING);

            return redirect()->route('login');
        }
        if ($mfa->lockedOut($user)) {
            throw ValidationException::withMessages(['code' => 'Muitas tentativas. Espere 10 minutos e tente de novo.']);
        }
        if (! $mfa->verify($user, $v['code'])) {
            throw ValidationException::withMessages(['code' => 'Código incorreto ou vencido. Confira o app autenticador e tente de novo.']);
        }
        $request->session()->forget(self::PENDING);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $p['tenant']);

        return redirect()->intended('/');
    }

    public function show(Request $request, TwoFactor $mfa)
    {
        $u = $request->user();

        return view('account.mfa', ['u' => $u, 'on' => $u->mfaOn(), 'setup' => ! $u->mfaOn() && $u->mfa_totp_secret !== null,
            'qr' => ! $u->mfaOn() && $u->mfa_totp_secret !== null ? $mfa->qrSvg($u) : null,
            'secret' => ! $u->mfaOn() ? $u->mfa_totp_secret : null,
            'left' => $mfa->remainingRecoveryCodes($u), 'required' => $mfa->required($u),
            'codes' => $request->session()->get('mfa_codes')]);
    }

    public function begin(Request $request, TwoFactor $mfa)
    {
        $u = $request->user();
        $this->checkPassword($u, (string) $request->validate(['password' => ['required', 'string']])['password']);
        abort_if($u->mfaOn(), 409);
        $mfa->begin($u);

        return redirect()->route('mfa.setup')->with('ok', 'Passo 2: leia o código QR no app autenticador e digite o número de 6 dígitos.');
    }

    public function confirm(Request $request, TwoFactor $mfa)
    {
        $v = $request->validate(['code' => ['required', 'digits:6']]);
        $codes = $mfa->confirm($request->user(), $v['code']);
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'Código incorreto ou vencido. Confira o relógio do celular e tente de novo.']);
        }

        return redirect()->route('mfa.setup')->with('ok', 'Verificação em duas etapas ligada.')->with('mfa_codes', $codes);
    }

    public function regenerate(Request $request, TwoFactor $mfa)
    {
        $u = $request->user();
        $v = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:20']]);
        $this->checkPassword($u, $v['password']);
        abort_unless($u->mfaOn(), 404);
        $this->checkCode($mfa, $u, $v['code']);

        return redirect()->route('mfa.setup')->with('ok', 'Novos códigos de recuperação gerados. Os antigos não valem mais.')
            ->with('mfa_codes', $mfa->newRecoveryCodes($u));
    }

    public function disable(Request $request, TwoFactor $mfa)
    {
        $u = $request->user();
        $v = $request->validate(['password' => ['required', 'string'], 'code' => ['nullable', 'string', 'max:20']]);
        $this->checkPassword($u, $v['password']);
        if ($mfa->required($u)) {
            throw ValidationException::withMessages(['password' => 'Sua clínica exige a verificação em duas etapas; ela não pode ser desligada.']);
        }
        if ($u->mfaOn()) {
            $this->checkCode($mfa, $u, (string) ($v['code'] ?? ''));
        }
        $mfa->disable($u);

        return redirect()->route('mfa.setup')->with('ok', 'Verificação em duas etapas desligada.');
    }

    /** @return array{user: int, tenant: int, at: int}|null */
    private function pending(Request $request): ?array
    {
        $p = $request->session()->get(self::PENDING);
        if (! is_array($p) || ($p['at'] ?? 0) < time() - 600) {
            return null;
        }

        return $p;
    }

    private function checkPassword(User $u, string $password): void
    {
        if (! Passwords::check($password, $u->password)) {
            throw ValidationException::withMessages(['password' => 'Senha incorreta.']);
        }
    }

    private function checkCode(TwoFactor $mfa, User $u, string $code): void
    {
        if (! $mfa->verify($u, $code)) {
            throw ValidationException::withMessages(['code' => $mfa->lockedOut($u) ? 'Muitas tentativas. Espere 10 minutos.' : 'Código incorreto.']);
        }
    }
}
