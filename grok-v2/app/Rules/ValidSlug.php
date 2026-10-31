<?php

namespace App\Rules;

use App\Models\Tenant;
use App\Tenancy\TenantSlug;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Subdominio valido, nao reservado e livre (ignora o proprio tenant ao trocar). */
final class ValidSlug implements ValidationRule
{
    public function __construct(private ?int $ignoreTenantId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $s = TenantSlug::normalize((string) $value);
        if (($p = TenantSlug::problem($s)) !== null) {
            $fail($p);

            return;
        }
        $q = Tenant::query()->where('slug', $s);
        if ($this->ignoreTenantId !== null) {
            $q->where('id', '!=', $this->ignoreTenantId);
        }
        if ($q->exists()) {
            $fail('Esse endereço já está em uso.');
        }
    }
}
