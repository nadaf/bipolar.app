<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Account\PasswordReset;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\PasswordResetController as Web;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** API de recuperacao de senha (mesmas regras da web). O link do e-mail abre a tela web. */
class PasswordResetController extends Controller
{
    public function forgot(Request $request, PasswordReset $reset): JsonResponse
    {
        $v = $request->validate(['email' => ['required', 'email', 'max:190']]);
        try {
            $reset->request($v['email'], $request->getHost());
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['message' => Web::NEUTRAL], 202);
    }

    public function reset(Request $request, PasswordReset $reset): JsonResponse
    {
        $v = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);
        if (! $reset->reset($v['token'], $v['password'], $request->getHost())) {
            return response()->json(['message' => 'Link expirado ou já usado. Peça um novo.'], 410);
        }

        return response()->json(['message' => 'Senha alterada.']);
    }
}
