<?php

namespace App\Services\Tenancy;

use App\Models\Category;
use App\Models\Karat;
use App\Models\MakingSlab;
use App\Models\Plan;
use App\Models\Process;
use App\Models\RouteTemplate;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\Books;
use App\Support\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates a tenant with its owner, trial subscription, roles and starter masters
 * (karats, processes with default wastage, categories, a default route).
 */
class TenantProvisioner
{
    public const TRIAL_DAYS = 14;

    /** Karats with standard BIS fineness; tenants edit tunch values afterwards. */
    public const DEFAULT_KARATS = [
        ['24K', '99.900'], ['23K', '95.800'], ['22K', '91.600'], ['20K', '83.300'],
        ['18K', '75.000'], ['14K', '58.500'], ['9K', '37.500'],
    ];

    /** Production stages in shop order (workflow blueprint §2) with a starting allowed wastage % (owner tunes these). */
    public const DEFAULT_PROCESSES = [
        ['Melting', '0.200'], ['Casting', '0.500'], ['Filing', '0.800'], ['Soldering', '1.000'],
        ['Stone setting', '0.300'], ['Polishing', '0.500'], ['Finishing', '0.300'],
    ];

    public const DEFAULT_CATEGORIES = ['Kada', 'Chain', 'Bangle', 'Ring', 'Necklace', 'Earring', 'Pendant'];

    public const RESERVED_SUBDOMAINS = [
        'www', 'app', 'api', 'admin', 'portal', 'kaarigar', 'mail', 'smtp', 'ftp', 'cpanel', 'webmail',
        'static', 'assets', 'cdn', 'help', 'support', 'billing', 'status', 'dev', 'staging', 'test',
    ];

    public function __construct(private TenantContext $context) {}

    /**
     * @param  array{name: string, subdomain: string, owner_name: string, owner_email: string,
     *               owner_password: string, owner_phone?: ?string, gstin?: ?string, phone?: ?string, plan_code?: ?string}  $data
     */
    public function provision(array $data): Tenant
    {
        return DB::transaction(function () use ($data) {
            $plan = Plan::where('code', $data['plan_code'] ?? 'trial')->first()
                ?? Plan::where('is_active', true)->orderBy('price')->first();

            $tenant = Tenant::create([
                'name' => $data['name'],
                'subdomain' => strtolower($data['subdomain']),
                'gstin' => $data['gstin'] ?? null,
                'state_code' => isset($data['gstin']) ? substr($data['gstin'], 0, 2) : null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['owner_email'],
                'plan_id' => $plan?->id,
                'status' => Tenant::STATUS_TRIAL,
                'trial_ends_at' => now()->addDays(self::TRIAL_DAYS),
            ]);

            if ($plan) {
                Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'status' => 'trialing',
                    'current_period_start' => now()->toDateString(),
                    'current_period_end' => now()->addDays(self::TRIAL_DAYS)->toDateString(),
                ]);
            }

            $this->context->runAs($tenant, function () use ($data) {
                $this->createRoles();
                $this->createMasters();
                app(Books::class)->setup();
                app(Books::class)->financialYear(today()->toDateString());

                $owner = User::create([
                    'name' => $data['owner_name'],
                    'email' => strtolower($data['owner_email']),
                    'phone' => $data['owner_phone'] ?? null,
                    'password' => $data['owner_password'],
                ]);
                $owner->assignRole(Permissions::ROLE_OWNER);
            });

            return $tenant;
        });
    }

    private function createRoles(): void
    {
        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, Permissions::GUARD);
        }

        foreach (Permissions::defaultRoles() as $role => $permissions) {
            Role::create(['name' => $role, 'guard_name' => Permissions::GUARD])->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createMasters(): void
    {
        foreach (self::DEFAULT_KARATS as $i => [$label, $purity]) {
            Karat::create(['label' => $label, 'purity_pct' => $purity, 'sort' => $i]);
        }

        $processes = [];
        foreach (self::DEFAULT_PROCESSES as $i => [$name, $wastage]) {
            $processes[$name] = Process::create(['name' => $name, 'sequence' => ($i + 1) * 10, 'default_wastage_pct' => $wastage]);
        }

        foreach (self::DEFAULT_CATEGORIES as $i => $name) {
            Category::create(['name' => $name, 'sort' => $i]);
        }

        $route = RouteTemplate::create(['name' => 'Standard (hand-made)', 'is_default' => true]);
        foreach (['Melting', 'Casting', 'Filing', 'Soldering', 'Stone setting', 'Polishing', 'Finishing'] as $i => $name) {
            $route->steps()->create(['process_id' => $processes[$name]->id, 'sequence' => $i + 1]);
        }

        MakingSlab::create(['name' => 'Standard', 'basis' => 'per_gram', 'rate' => '0', 'is_default' => true]);
    }
}
