<?php

namespace App\Tenancy;

/** Tenant indicado pelo HOST da requisicao (scoped). null = host principal. */
final class HostTenant
{
    public ?int $tenantId = null;

    public ?string $slug = null;
}
