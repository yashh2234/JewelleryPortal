<?php

use App\Models\Tenant;
use App\Support\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Existing workspaces get the new permissions and the spec's preset roles. Roles that
 * could confirm/dispatch before (orders.manage) keep doing so via the new
 * orders.approve / dispatch.manage; production keeps QC via qc.manage.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, Permissions::GUARD);
        }

        $context = app(TenantContext::class);

        foreach (Tenant::all() as $tenant) {
            $context->runAs($tenant, function () {
                foreach (Role::with('permissions')->where('guard_name', Permissions::GUARD)->get() as $role) {
                    $has = $role->permissions->pluck('name');
                    $add = [];
                    if ($has->contains(Permissions::ORDERS_MANAGE)) {
                        $add = [...$add, Permissions::ORDERS_APPROVE, Permissions::DISPATCH_MANAGE];
                    }
                    if ($has->contains(Permissions::PRODUCTION_MANAGE)) {
                        $add[] = Permissions::QC_MANAGE;
                    }
                    if ($add) {
                        $role->givePermissionTo($add);
                    }
                }

                foreach (Permissions::defaultRoles() as $name => $permissions) {
                    $role = Role::where('name', $name)->where('guard_name', Permissions::GUARD)->first();
                    if (! $role) {
                        Role::create(['name' => $name, 'guard_name' => Permissions::GUARD])->syncPermissions($permissions);
                    } elseif ($name === Permissions::ROLE_OWNER) {
                        $role->syncPermissions(Permissions::all());
                    }
                }
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Roles and permissions are left in place: users may already hold them.
    }
};
