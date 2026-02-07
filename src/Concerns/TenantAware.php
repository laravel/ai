<?php

namespace Laravel\Ai\Concerns;

trait TenantAware
{
    /**
     * The current tenant identifier.
     */
    protected int|string|null $tenantId = null;

    /**
     * Set the tenant context for the current operation.
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
     * Determine if multi-tenancy is enabled.
     */
    protected function isMultiTenancyEnabled(): bool
    {
        return config('ai.multi_tenancy.enabled', false);
    }

    /**
     * Get the tenant column name from configuration.
     */
    protected function tenantColumn(): string
    {
        return config('ai.multi_tenancy.column', 'tenant_id');
    }

    /**
     * Determine if a tenant context is set.
     */
    public function hasTenantContext(): bool
    {
        return $this->tenantId !== null;
    }

    /**
     * Get the tenant ID or throw an exception if not set.
     *
     * @throws \RuntimeException
     */
    protected function requireTenantContext(): int|string
    {
        if (! $this->hasTenantContext()) {
            throw new \RuntimeException(
                'Multi-tenancy is enabled but no tenant context has been set. '.
                'Call forTenant($tenantId) before performing this operation.'
            );
        }

        return $this->tenantId;
    }
}
