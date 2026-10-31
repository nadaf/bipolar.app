<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** role:admin,professional — papel no tenant JA resolvido. Fora do papel => 404. */
final class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $m = $request->attributes->get('membership');
        abort_unless($m instanceof Membership && in_array($m->role, $roles, true), 404);

        return $next($request);
    }
}
