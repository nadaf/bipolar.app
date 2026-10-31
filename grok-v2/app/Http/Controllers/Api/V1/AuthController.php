<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Account\IssueToken;
use App\Domain\Account\RegisterPatient;
use App\Domain\Account\RegisterSoloProfessional;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\ValidSlug;
use App\Tenancy\HostTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
        if (! $user || ! Hash::check($v['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos.']);
        }
        if ($user->mfa_enabled_at !== null) {
            return response()->json(['mfa_required' => true, 'message' => 'Verificação em duas etapas ainda não disponível nesta versão.'], 501);
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
        $u = $request->user();

        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'timezone' => $u->timezone, 'mfa_enabled' => $u->mfa_enabled_at !== null];
    }

    public function mfaStub()
    {
        return response()->json(['message' => 'TOTP será implementado na próxima etapa.'], 501);
    }
}
