<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // id() lanca MissingTenantContext se nao houver tenant: falha fechada.
        $builder->where($model->qualifyColumn('tenant_id'), app(TenantContext::class)->id());
    }
}
