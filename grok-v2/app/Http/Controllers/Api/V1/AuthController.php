<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Account\IssueToken;
use App\Domain\Account\RegisterPatient;
use App\Domain\Account\RegisterSoloProfessional;
use App\Domain\Mfa\TwoFactor;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\ValidSlug;
use App\Support\Passwords;
use App\Tenancy\HostTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, RegisterPatient $patient, RegisterSoloProfessional $solo, IssueToken $issue, HostTenant $host)
    {
        // cadastro aberto so no host principal; em subdominio de clinica o ingresso e por convite
        abort_if($host->tenantId !== null, 404);
        $v = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', Password::min(10)],
            'device_name' => ['required', 'string', 'max:100'],
            'account_type' => ['sometimes', 'in:patient,professional'],
            'subdomain' => ['sometimes', 'nullable', 'string', new ValidSlug],
        ]);
        $slug = isset($v['subdomain']) ? strtolower($v['subdomain']) : null;
        $user = ($v['account_type'] ?? 'patient') === 'professional'
            ? $solo($v['name'], $v['email'], $v['password'], $slug)
            : $patient($v['name'], $v['email'], $v['password'], null, $slug);

        return response()->json(['token' => $issue($user, $v['device_name'])], 201);
    }

    public function login(Request $request, IssueToken $issue)
    {
        $v = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'], 'tenant_id' => ['sometimes', 'integer']]);
        $user = User::where('email', $v['email'])->first();
        if (! $user || ! Passwords::check($v['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos.']);
        }
        Passwords::upgrade($user, $v['password']);
        if ($user->mfaOn()) {
            $issue->tenantFor($user, isset($v['tenant_id']) ? (int) $v['tenant_id'] : null); // mesma mensagem se sem vinculo

            return response()->json(['mfa_required' => true, 'mfa_token' => Crypt::encryptString(json_encode([
                'u' => $user->id, 'd' => $v['device_name'], 't' => $v['tenant_id'] ?? null, 'exp' => time() + 300,
            ], JSON_THROW_ON_ERROR))]);
        }

        return response()->json(['token' => $issue($user, $v['device_name'], isset($v['tenant_id']) ? (int) $v['tenant_id'] : null)]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return AccountController::present($request->user());
    }

    /** Segunda etapa: mfa_token (5 min, criptografado) + codigo do app ou de recuperacao => token. */
    public function mfa(Request $request, IssueToken $issue, TwoFactor $mfa)
    {
        $v = $request->validate(['mfa_token' => ['required', 'string', 'max:2000'], 'code' => ['required', 'string', 'max:20']]);
        try {
            $p = json_decode(Crypt::decryptString($v['mfa_token']), true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $p = null;
        }
        $user = is_array($p) && ($p['exp'] ?? 0) >= time() ? User::query()->find($p['u'] ?? 0) : null;
        if ($user === null || ! $user->mfaOn()) {
            throw ValidationException::withMessages(['mfa_token' => 'Tentativa expirada. Entre de novo.']);
        }
        if (! $mfa->verify($user, $v['code'])) {
            throw ValidationException::withMessages(['code' => $mfa->lockedOut($user) ? 'Muitas tentativas. Espere 10 minutos.' : 'Código incorreto ou vencido.']);
        }

        return response()->json(['token' => $issue($user, (string) $p['d'], isset($p['t']) ? (int) $p['t'] : null)]);
    }
}
