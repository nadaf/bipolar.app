<?php

namespace App\Http\Controllers\Web;

use App\Domain\Account\PasswordReset;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class PasswordResetController extends Controller
{
    public const NEUTRAL = 'Se existir uma conta com esse e-mail, enviamos um link para redefinir a senha. Confira sua caixa de entrada e o spam.';

    public function form()
    {
        return view('auth.forgot');
    }

    public function send(Request $request, PasswordReset $reset)
    {
        $v = $request->validate(['email' => ['required', 'email', 'max:190']]);
        try {
            $reset->request($v['email'], $request->getHost());
        } catch (\Throwable $e) {
            report($e); // falha de SMTP nao muda a resposta (neutra)
        }

        return redirect()->route('password.forgot')->with('ok', self::NEUTRAL);
    }

    public function edit(Request $request, PasswordReset $reset, string $token)
    {
        // link assinado conferido aqui (e nao no middleware) para link vencido/adulterado cair na tela em pt-BR
        $valid = $request->hasValidSignature() && $reset->find($token, $request->getHost()) !== null;

        return response()->view('auth.reset', ['token' => $token, 'valid' => $valid], $valid ? 200 : 410);
    }

    public function update(Request $request, PasswordReset $reset)
    {
        $v = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);
        if (! $reset->reset($v['token'], $v['password'], $request->getHost())) {
            return response()->view('auth.reset', ['token' => '', 'valid' => false], 410);
        }

        return redirect()->route('login')->with('ok', 'Senha alterada. Entre com a senha nova.');
    }
}
