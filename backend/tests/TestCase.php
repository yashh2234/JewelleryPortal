<?php

namespace Tests;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Provision a tenant (owner: owner@{sub}.test / password). */
    protected function makeTenant(string $subdomain = 'acme'): Tenant
    {
        $this->seed(PlanSeeder::class);

        return app(TenantProvisioner::class)->provision([
            'name' => ucfirst($subdomain).' Gold',
            'subdomain' => $subdomain,
            'owner_name' => 'Owner',
            'owner_email' => "owner@{$subdomain}.test",
            'owner_password' => 'password',
        ]);
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    protected function asTenant(Tenant $tenant, callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($tenant, $callback);
    }

    protected function owner(Tenant $tenant): User
    {
        return $this->asTenant($tenant, fn () => User::role('owner')->firstOrFail());
    }

    protected function staffWithRole(Tenant $tenant, string $role): User
    {
        return $this->asTenant($tenant, function () use ($role) {
            $user = User::create(['name' => ucfirst($role), 'email' => "{$role}@example.test", 'password' => 'password']);
            $user->assignRole($role);

            return $user;
        });
    }

    /** Full URL on the tenant's subdomain. */
    protected function on(Tenant|string $tenant, string $path): string
    {
        $sub = $tenant instanceof Tenant ? $tenant->subdomain : $tenant;

        return "http://{$sub}.".config('tenancy.central_domain').'/'.ltrim($path, '/');
    }

    /** Requests that look like they come from our own SPA (Sanctum stateful + JSON). */
    protected function fromSpa(Tenant $tenant): static
    {
        $origin = "http://{$tenant->subdomain}.".config('tenancy.central_domain');

        return $this->withHeaders(['Referer' => $origin.'/', 'Origin' => $origin, 'Accept' => 'application/json']);
    }
}
