<?php

namespace Laravel\Ai\Concerns;

trait HasTenantContext
{
    /**
     * The current tenant identifier.
     */
    protected int|string|null $tenantId = null;

    /**
     * Set the tenant context for multi-tenant applications.
     */
    public function forTenant(int|string $tenantId): static
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    /**
     * Get the current tenant identifier.
     */
    public function currentTenant(): int|string|null
    {
        return $this->tenantId;
    }

    /**
     * Determine if a tenant context is set.
     */
    public function hasTenantContext(): bool
    {
        return $this->tenantId !== null;
    }
}
