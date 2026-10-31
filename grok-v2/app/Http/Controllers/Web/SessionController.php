<?php

namespace App\Http\Controllers\Web;

use App\Domain\Account\IssueToken;
use App\Domain\Account\RegisterPatient;
use App\Domain\Account\RegisterSoloProfessional;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tenancy\HostTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function form()
    {
        return view('auth.login');
    }

    /** Sessao amarrada ao tenant do host (ou ao padrao no host principal). Sem vinculo => mesma mensagem. */
    public function login(Request $request, IssueToken $issue)
    {
        $c = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::where('email', $c['email'])->first();
        if ($user === null || ! Hash::check($c['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos.']);
        }
        $m = $issue->tenantFor($user); // lanca a mesma mensagem se nao houver vinculo neste host
        if ($user->mfaOn()) { // segunda etapa: nada de sessao autenticada antes do codigo
            $request->session()->regenerate();
            $request->session()->put(MfaController::PENDING, ['user' => $user->id, 'tenant' => $m->tenant_id, 'at' => time()]);

            return redirect()->route('mfa.challenge');
        }
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $m->tenant_id);

        return redirect()->intended('/');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/entrar');
    }

    public function signupForm(HostTenant $host)
    {
        abort_if($host->tenantId !== null, 404);

        return view('auth.signup');
    }

    /** Cadastro aberto so no host principal (em clinica o ingresso e por convite). */
    public function signup(Request $request, RegisterPatient $patient, RegisterSoloProfessional $solo, IssueToken $issue, HostTenant $host)
    {
        abort_if($host->tenantId !== null, 404);
        $v = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)],
            'account_type' => ['required', 'in:patient,professional'],
        ], ['email.unique' => 'Já existe uma conta com esse e-mail. Tente entrar.']);
        $user = $v['account_type'] === 'professional'
            ? $solo($v['name'], $v['email'], $v['password'], null)
            : $patient($v['name'], $v['email'], $v['password']);
        $m = $issue->tenantFor($user);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $m->tenant_id);

        return redirect('/')->with('ok', 'Conta criada. Bem-vindo(a)!');
    }
}
