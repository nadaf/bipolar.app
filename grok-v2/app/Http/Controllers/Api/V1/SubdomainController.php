<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Tenant;
use App\Rules\ValidSlug;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Endereco opcional <slug>.bipolar.app.br do tenant atual.
 * Pode definir: paciente no tenant pessoal, profissional no tenant solo, admin na clinica.
 */
class SubdomainController extends Controller
{
    public function update(Request $request, TenantContext $ctx)
    {
        $tenant = Tenant::findOrFail($ctx->id());
        $m = $request->attributes->get('membership');
        $allowed = [Tenant::PERSONAL => Membership::PATIENT, Tenant::SOLO => Membership::PROFESSIONAL, Tenant::CLINIC => Membership::ADMIN];
        abort_unless($m instanceof Membership && ($allowed[$tenant->kind] ?? null) === $m->role, 404);

        $v = $request->validate(['subdomain' => ['present', 'nullable', 'string', new ValidSlug($tenant->id)]]);
        $tenant->slug = $v['subdomain'] === null ? null : strtolower($v['subdomain']);
        $tenant->save();

        return ['data' => ['subdomain' => $tenant->slug, 'url' => $tenant->slug ? 'https://'.$tenant->slug.'.'.config('bipolar.base_domain') : null]];
    }
}
