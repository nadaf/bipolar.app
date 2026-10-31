<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\HostTenant;
use App\Tenancy\TenantSlug;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve o tenant pelo host:
 *  - host principal (bipolar.app.br, www., host do APP_URL) => sem tenant de host;
 *  - <slug>.bipolar.app.br => tenant com esse slug (inexistente/reservado/invalido => 404);
 *  - qualquer outro host => 404.
 * O host SO restringe: nunca concede acesso. Quem decide e ResolveTenant (vinculo + token/sessao).
 */
final class ResolveHost
{
    public function __construct(private HostTenant $host) {}

    public function handle(Request $request, Closure $next): Response
    {
        $h = strtolower($request->getHost());
        $base = strtolower((string) config('bipolar.base_domain'));
        // nunca herda o host de uma requisicao anterior no mesmo processo (testes, workers longos)
        $this->host->tenantId = null;
        $this->host->slug = null;

        if (! in_array($h, self::mainHosts(), true)) {
            abort_unless($base !== '' && str_ends_with($h, '.'.$base), 404);
            $slug = substr($h, 0, -strlen('.'.$base));
            abort_if(str_contains($slug, '.') || TenantSlug::problem($slug) !== null, 404);
            $tenant = Tenant::query()->where('slug', $slug)->first();
            abort_if($tenant === null, 404);
            $this->host->tenantId = $tenant->id;
            $this->host->slug = $slug;
        }

        return $next($request);
    }

    /** @return list<string> */
    public static function mainHosts(): array
    {
        $base = strtolower((string) config('bipolar.base_domain'));
        $app = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $extra = array_map('strtolower', (array) config('bipolar.main_hosts', []));

        return array_values(array_filter(array_unique([$base, 'www.'.$base, $app, ...$extra])));
    }
}
