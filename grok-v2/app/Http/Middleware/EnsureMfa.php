<?php

namespace App\Http\Middleware;

use App\Domain\Mfa\TwoFactor;
use App\Models\Membership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clinica com 2FA obrigatoria: profissional/admin sem 2FA so acessa a configuracao dela (e sair).
 * Web: redireciona para /conta/verificacao. API: 403 com mfa_setup_required.
 */
final class EnsureMfa
{
    public function __construct(private TwoFactor $mfa) {}

    public function handle(Request $request, Closure $next): Response
    {
        $m = $request->attributes->get('membership');
        $u = $request->user();
        if ($m instanceof Membership && $m->role !== Membership::PATIENT && $u !== null && ! $u->mfaOn()
            && $this->mfa->required($u, $m->tenant_id)
            && ! $request->routeIs('mfa.*', 'api.v1.account.mfa.*', 'logout', 'api.v1.auth.logout')) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Sua clínica exige verificação em duas etapas. Ative em account/mfa.', 'mfa_setup_required' => true], 403);
            }

            return redirect()->route('mfa.setup')->with('ok', 'Sua clínica exige verificação em duas etapas. Ative para continuar.');
        }

        return $next($request);
    }
}
