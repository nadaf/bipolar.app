<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** CSP estrita (sem inline, sem eval, sem CDN) e cabecalhos de privacidade. */
final class SecurityHeaders
{
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; "
        ."connect-src 'self'; worker-src 'self'; manifest-src 'self'; frame-ancestors 'none'; base-uri 'none'; "
        ."form-action 'self'; object-src 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $r = $next($request);
        $r->headers->set('Content-Security-Policy', self::CSP);
        $r->headers->set('X-Content-Type-Options', 'nosniff');
        $r->headers->set('Referrer-Policy', 'no-referrer');
        $r->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), interest-cohort=()');
        $r->headers->set('X-Frame-Options', 'DENY');
        if ($request->user()) {
            $r->headers->set('Cache-Control', 'no-store, private');
        }

        return $r;
    }
}
