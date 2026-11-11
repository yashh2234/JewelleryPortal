<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;

/**
 * Holds the tenant for the current request / job / command.
 *
 * Tenant-owned models are filtered by TenantScope using this context. With no
 * tenant set (and central mode off) tenant queries return nothing, so forgetting
 * to resolve a tenant fails closed instead of leaking another tenant's rows.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    private bool $central = false;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;

        // spatie/laravel-permission "teams" are keyed on tenant_id.
        setPermissionsTeamId($tenant?->id);
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    /** Central mode disables the tenant scope (platform admin, nightly jobs across tenants). */
    public function isCentral(): bool
    {
        return $this->central;
    }

    /**
     * Run a callback as the given tenant, restoring the previous state afterwards.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(Tenant $tenant, callable $callback): mixed
    {
        [$previous, $wasCentral] = [$this->tenant, $this->central];
        $this->central = false;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->set($previous);
            $this->central = $wasCentral;
        }
    }

    /**
     * Run a callback with the tenant scope switched off. Use sparingly.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function runCentral(callable $callback): mixed
    {
        $wasCentral = $this->central;
        $this->central = true;

        try {
            return $callback();
        } finally {
            $this->central = $wasCentral;
        }
    }
}
