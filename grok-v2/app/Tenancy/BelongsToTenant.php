<?php

namespace App\Tenancy;

use App\Tenancy\Exceptions\TenantMismatch;
use Illuminate\Database\Eloquent\Model;

/**
 * OBRIGATORIO em todo model com coluna tenant_id (teste de arquitetura verifica).
 * - Toda consulta e filtrada pelo tenant do contexto; sem contexto => excecao.
 * - Ao criar, tenant_id vem do contexto; valor diferente => excecao.
 * - tenant_id nunca pode ser alterado depois.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            $ctx = app(TenantContext::class)->id();
            if ($model->getAttribute('tenant_id') !== null && (int) $model->getAttribute('tenant_id') !== $ctx) {
                throw new TenantMismatch('tenant_id diferente do contexto.');
            }
            $model->setAttribute('tenant_id', $ctx);
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('tenant_id')) {
                throw new TenantMismatch('tenant_id e imutavel.');
            }
            if ((int) $model->getOriginal('tenant_id') !== app(TenantContext::class)->id()) {
                throw new TenantMismatch('Gravacao fora do tenant do contexto.');
            }
        });

        static::deleting(function (Model $model) {
            if ((int) $model->getAttribute('tenant_id') !== app(TenantContext::class)->id()) {
                throw new TenantMismatch('Exclusao fora do tenant do contexto.');
            }
        });
    }

    public function initializeBelongsToTenant(): void
    {
        $this->guarded = array_values(array_unique([...$this->guarded, 'tenant_id']));
        if ($this->fillable !== []) {
            $this->fillable = array_values(array_diff($this->fillable, ['tenant_id']));
        }
    }
}
