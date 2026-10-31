<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Account\AccountService;
use App\Domain\Account\StepUp;
use App\Domain\Day\StepUpRequired;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public static function present($u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'timezone' => $u->timezone, 'theme' => $u->theme,
            'modules' => array_values(array_filter(explode(',', (string) $u->modules))), 'pin_set' => $u->pin_hash !== null,
            'pending_email' => $u->pending_email, 'mfa_enabled' => $u->mfa_enabled_at !== null];
    }

    public function update(Request $request, AccountService $svc)
    {
        return self::present($svc->update($request->user(), $request->validate(AccountService::profileRules())));
    }

    public function pin(Request $request, AccountService $svc, StepUp $stepUp)
    {
        $v = $request->validate(['pin' => ['present', 'nullable', 'digits_between:4,8'], 'password' => ['required', 'string']]);
        abort_unless($stepUp->check($request->user(), 'password', $v['password']), 422, 'Senha incorreta.');
        $svc->setPin($request->user(), $v['pin']);

        return response()->noContent();
    }

    public function email(Request $request, AccountService $svc)
    {
        $v = $request->validate(['email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')]]);
        $svc->requestEmailChange($request->user(), $v['email']);

        return response()->json(['message' => 'Enviamos um link de confirmação para o novo e-mail.'], 202);
    }

    public function export(Request $request, AccountService $svc, StepUp $stepUp)
    {
        $v = $request->validate(['format' => ['sometimes', 'in:json'], 'include_sensitive' => ['sometimes', 'boolean']]);
        if (! $stepUp->active($request)) {
            throw new StepUpRequired;
        }

        return response()->json($svc->export($request->user(), (bool) ($v['include_sensitive'] ?? false)))
            ->header('Content-Disposition', 'attachment; filename="bipolar-meus-dados.json"');
    }

    public function destroy(Request $request, AccountService $svc, StepUp $stepUp)
    {
        if (! $stepUp->active($request)) {
            throw new StepUpRequired;
        }
        $svc->delete($request->user());

        return response()->noContent();
    }
}
