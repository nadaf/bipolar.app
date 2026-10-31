<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Rotas de diario: so o proprio paciente no tenant resolvido. */
final class EnsurePatient
{
    public function handle(Request $request, Closure $next): Response
    {
        $m = $request->attributes->get('membership');
        abort_unless($m instanceof Membership && $m->role === Membership::PATIENT, 404);

        return $next($request);
    }
}
