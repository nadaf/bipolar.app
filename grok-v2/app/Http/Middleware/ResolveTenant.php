<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Models\PersonalAccessToken;
use App\Tenancy\HostTenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Define o tenant da requisicao. Regras (todas precisam concordar, senao 404):
 *  1. o usuario precisa ter vinculo (membership) com o tenant;
 *  2. o token (API) ou a sessao (web) carrega o tenant em que foi emitido: ele manda;
 *  3. em subdominio, o tenant do host tem de ser o mesmo do token/sessao;
 *  4. X-Tenant-Id, se enviado, tem de ser o mesmo.
 * Nada disso vem de parametro livre; divergencia => 404 (nao revela existencia).
 */
final class ResolveTenant
{
    public function __construct(private TenantContext $ctx, private HostTenant $host) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $bound = null;
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken && $token->exists) {
            $bound = $token->tenant_id !== null ? (int) $token->tenant_id : null;
        } elseif ($request->hasSession()) {
            $s = $request->session()->get('tenant_id');
            $bound = $s !== null ? (int) $s : null;
        }

        $candidates = array_filter([$bound, $this->host->tenantId], fn ($v) => $v !== null);
        $header = $request->header('X-Tenant-Id');
        if ($header !== null) {
            abort_unless(ctype_digit((string) $header), 404);
            $candidates[] = (int) $header;
        }
        abort_if(count(array_unique($candidates)) > 1, 404);
        $wanted = $candidates === [] ? null : reset($candidates);

        $m = Membership::defaultFor($user->id, $wanted);
        abort_if($m === null, 404);

        $this->ctx->set($m->tenant_id);
        $request->attributes->set('membership', $m);

        try {
            return $next($request);
        } finally {
            $this->ctx->clear();
        }
    }
}
