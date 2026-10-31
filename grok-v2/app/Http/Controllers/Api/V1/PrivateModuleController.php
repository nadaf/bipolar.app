<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Account\StepUp;
use App\Domain\Day\Blocks;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PrivateModuleController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();

        return ['data' => array_map(fn ($m) => ['module' => $m, 'label' => Blocks::MODULES[$m],
            'enabled' => Blocks::enabledFor($m, $u->modules), 'step_up' => Blocks::stepUp($m)], array_keys(Blocks::MODULES))];
    }

    public function unlock(Request $request, StepUp $stepUp)
    {
        $v = $request->validate(['method' => ['required', 'in:pin,password,totp,webauthn'], 'secret' => ['required', 'string', 'max:200']]);
        if (! in_array($v['method'], ['pin', 'password'], true)) {
            throw ValidationException::withMessages(['method' => 'Método ainda não disponível. Use PIN ou senha.']);
        }
        if (! $stepUp->check($request->user(), $v['method'], $v['secret'])) {
            throw ValidationException::withMessages(['secret' => 'PIN ou senha incorretos.']);
        }

        return ['step_up_token' => $stepUp->issueToken($request->user()), 'expires_at' => now()->addSeconds(StepUp::TTL)->toIso8601String()];
    }
}
