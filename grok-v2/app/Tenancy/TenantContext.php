<?php

namespace App\Tenancy;

use App\Tenancy\Exceptions\MissingTenantContext;

/** Contexto do tenant da requisicao atual (scoped: zera a cada requisicao/job). */
final class TenantContext
{
    private ?int $tenantId = null;

    public function set(int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    public function id(): int
    {
        if ($this->tenantId === null) {
            throw new MissingTenantContext('Nenhum tenant no contexto: acesso a dado de tenant bloqueado.');
        }

        return $this->tenantId;
    }

    /** Executa um bloco dentro de um tenant (jobs, cron, testes) e restaura o anterior. */
    public function run(int $tenantId, callable $fn): mixed
    {
        $prev = $this->tenantId;
        $this->tenantId = $tenantId;
        try {
            return $fn();
        } finally {
            $this->tenantId = $prev;
        }
    }
}
