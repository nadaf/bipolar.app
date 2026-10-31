<?php

namespace App\Http\Controllers\Web;

use App\Domain\Account\IssueToken;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $m->tenant_id);

        return redirect()->intended('/hoje');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/entrar');
    }
}
