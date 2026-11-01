<?php

namespace App\Http\Controllers\Web;

use App\Domain\Account\AccountService;
use App\Domain\Account\StepUp;
use App\Domain\Day\Blocks;
use App\Http\Controllers\Controller;
use App\Support\Passwords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function show(Request $request, StepUp $stepUp)
    {
        $u = $request->user();
        $patient = $request->attributes->get('membership')?->role === 'patient';

        return view('account.index', ['u' => $u, 'themes' => AccountService::THEMES, 'modules' => Blocks::MODULES,
            'unlocked' => $stepUp->active($request), 'patient' => $patient,
            'zones' => ['America/Sao_Paulo', 'America/Cuiaba', 'America/Manaus', 'America/Belem', 'America/Fortaleza', 'America/Recife',
                'America/Bahia', 'America/Campo_Grande', 'America/Porto_Velho', 'America/Rio_Branco', 'America/Noronha']]);
    }

    public function profile(Request $request, AccountService $svc)
    {
        $v = $request->validate(AccountService::profileRules());
        if ($request->boolean('modulos_form')) {
            $v['modules'] = $v['modules'] ?? [];
        }
        $svc->update($request->user(), $v);

        return redirect()->route('account')->with('ok', 'Conta atualizada.');
    }

    public function pin(Request $request, AccountService $svc)
    {
        $v = $request->validate(['password' => ['required', 'string'], 'pin' => ['nullable', 'digits_between:4,8', 'confirmed']]);
        $this->checkPassword($request, $v['password']);
        $svc->setPin($request->user(), $v['pin'] ?? null);

        return redirect()->route('account')->with('ok', ($v['pin'] ?? null) ? 'PIN definido.' : 'PIN removido.');
    }

    public function email(Request $request, AccountService $svc)
    {
        $u = $request->user();
        $v = $request->validate(['password' => ['required', 'string'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($u->id)]]);
        $this->checkPassword($request, $v['password']);
        $svc->requestEmailChange($u, $v['email']);

        return redirect()->route('account')->with('ok', 'Enviamos um link para o novo e-mail. A troca só vale depois de confirmar (1 hora).');
    }

    public function confirmEmail(Request $request, AccountService $svc, string $token)
    {
        abort_unless($svc->confirmEmailChange($request->user(), $token), 404);

        return redirect()->route('account')->with('ok', 'E-mail trocado.');
    }

    public function export(Request $request, AccountService $svc, StepUp $stepUp)
    {
        $sens = $request->boolean('sensiveis');
        if ($sens && ! $stepUp->active($request)) {
            return redirect()->route('account', ['_' => 'exportar'])->withErrors(['exportar' => 'Para incluir dados privados, desbloqueie primeiro.']);
        }

        return response()->json($svc->export($request->user(), $sens), 200, [
            'Content-Disposition' => 'attachment; filename="bipolar-meus-dados-'.now()->format('Y-m-d').'.json"',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function destroy(Request $request, AccountService $svc)
    {
        $v = $request->validate(['password' => ['required', 'string'], 'palavra' => ['required', 'regex:/^\s*excluir\s*$/i']],
            ['palavra.regex' => 'Digite EXCLUIR em maiúsculas para confirmar.']);
        $this->checkPassword($request, $v['password']);
        $svc->delete($request->user());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('ok', 'Conta excluída. Seus dados foram apagados.');
    }

    private function checkPassword(Request $request, string $password): void
    {
        if (! Passwords::check($password, $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Senha incorreta.']);
        }
    }
}
