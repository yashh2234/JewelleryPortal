<?php

namespace Tests\Feature;

use App\Models\Design;
use App\Models\Karat;
use App\Models\Tenant;
use App\Support\NumberSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_provisions_a_ready_workspace(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->postJson('http://yourapp.test/api/platform/signup', [
            'name' => 'Shree Gold',
            'subdomain' => 'shree',
            'owner_name' => 'Owner',
            'owner_email' => 'Owner@Shree.test',
            'owner_password' => 'secret123',
        ])->assertCreated()->assertJsonPath('app_url', 'http://shree.yourapp.test/');

        $tenant = Tenant::where('subdomain', 'shree')->firstOrFail();
        $this->assertSame(Tenant::STATUS_TRIAL, $tenant->status);
        $this->assertNotNull($tenant->subscription);

        $this->asTenant($tenant, function () {
            $this->assertSame('91.600', Karat::where('label', '22K')->value('purity_pct'));
            $owner = \App\Models\User::where('email', 'owner@shree.test')->firstOrFail();
            $this->assertTrue($owner->hasRole('owner'));
            $this->assertTrue($owner->can('metal.issue'));
        });
    }

    public function test_signup_rejects_reserved_and_taken_subdomains(): void
    {
        $this->makeTenant('acme');

        foreach (['acme', 'www', 'Bad_Name'] as $sub) {
            $this->postJson('http://yourapp.test/api/platform/signup', [
                'name' => 'X', 'subdomain' => $sub, 'owner_name' => 'X',
                'owner_email' => 'x@x.test', 'owner_password' => 'secret123',
            ])->assertUnprocessable()->assertJsonValidationErrors('subdomain');
        }
    }

    public function test_unknown_or_suspended_workspace_is_refused(): void
    {
        $this->getJson($this->on('nobody', 'api/tenant'))->assertNotFound();

        $tenant = $this->makeTenant('acme');
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->getJson($this->on($tenant, 'api/tenant'))->assertForbidden();
    }

    public function test_tenant_resolved_from_header_on_central_domain(): void
    {
        $this->makeTenant('acme');

        $this->getJson('http://localhost/api/tenant', ['X-Tenant' => 'acme'])
            ->assertOk()->assertJsonPath('data.subdomain', 'acme');
    }

    public function test_each_tenant_sees_only_its_own_rows(): void
    {
        $a = $this->makeTenant('alpha');
        $b = $this->makeTenant('beta');

        $this->asTenant($b, fn () => Karat::create(['label' => '21K', 'purity_pct' => '87.500']));

        $labels = $this->actingAs($this->owner($a), 'staff')
            ->getJson($this->on($a, 'api/v1/karats'))
            ->assertOk()
            ->json('data.*.label');

        $this->assertNotContains('21K', $labels);
        $this->assertCount(7, $labels);
    }

    public function test_cannot_reach_another_tenants_record_by_id(): void
    {
        $a = $this->makeTenant('alpha');
        $b = $this->makeTenant('beta');

        $design = $this->asTenant($b, fn () => Design::create(['code' => 'B-1', 'name' => 'Beta kada']));

        $this->actingAs($this->owner($a), 'staff')
            ->getJson($this->on($a, "api/v1/designs/{$design->id}"))
            ->assertNotFound();

        $this->actingAs($this->owner($a), 'staff')
            ->patchJson($this->on($a, "api/v1/designs/{$design->id}"), ['name' => 'Hacked'])
            ->assertNotFound();
    }

    public function test_validation_ids_must_belong_to_the_tenant(): void
    {
        $a = $this->makeTenant('alpha');
        $b = $this->makeTenant('beta');
        $foreignKarat = $this->asTenant($b, fn () => Karat::first());

        $this->actingAs($this->owner($a), 'staff')
            ->postJson($this->on($a, 'api/v1/designs'), [
                'code' => 'A-1', 'name' => 'Kada', 'karat_ids' => [$foreignKarat->id],
            ])
            ->assertUnprocessable()->assertJsonValidationErrors('karat_ids.0');
    }

    public function test_user_of_one_tenant_is_not_authenticated_on_another(): void
    {
        $a = $this->makeTenant('alpha');
        $b = $this->makeTenant('beta');

        $this->actingAs($this->owner($a), 'staff')
            ->getJson($this->on($b, 'api/v1/auth/me'))
            ->assertUnauthorized();
    }

    public function test_same_email_can_log_in_only_to_its_own_workspace(): void
    {
        $a = $this->makeTenant('alpha');
        $b = $this->makeTenant('beta');

        $this->fromSpa($a)->postJson($this->on($a, 'api/auth/login'), ['email' => 'owner@alpha.test', 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.roles.0', 'owner');

        $this->fromSpa($b)->postJson($this->on($b, 'api/auth/login'), ['email' => 'owner@alpha.test', 'password' => 'password'])
            ->assertUnprocessable();
    }

    public function test_no_tenant_means_no_rows_and_no_writes(): void
    {
        $this->makeTenant('acme');

        $this->assertSame(0, Karat::count());

        $this->expectException(LogicException::class);
        Karat::create(['label' => '21K', 'purity_pct' => '87.5']);
    }

    public function test_tenant_id_cannot_be_moved(): void
    {
        $a = $this->makeTenant('alpha');
        $b = $this->makeTenant('beta');

        $this->expectException(LogicException::class);
        $this->asTenant($a, fn () => Karat::first()->forceFill(['tenant_id' => $b->id])->save());
    }

    public function test_number_series_per_tenant_and_financial_year(): void
    {
        $a = $this->makeTenant('alpha');
        $b = $this->makeTenant('beta');

        $this->asTenant($a, function () {
            $this->assertSame('ORD/26-27/0001', NumberSeries::next('ORD', '2026-11-08'));
            $this->assertSame('ORD/26-27/0002', NumberSeries::next('ORD', '2026-11-09'));
            $this->assertSame('ORD/27-28/0001', NumberSeries::next('ORD', '2027-04-01'));
            $this->assertSame('WO/26-27/0001', NumberSeries::next('WO', '2026-11-08'));
        });

        $this->asTenant($b, fn () => $this->assertSame('ORD/26-27/0001', NumberSeries::next('ORD', '2026-11-08')));
    }

    public function test_writes_are_audited_with_tenant(): void
    {
        $tenant = $this->makeTenant('acme');

        $this->actingAs($this->owner($tenant), 'staff')
            ->postJson($this->on($tenant, 'api/v1/karats'), ['label' => '21K', 'purity_pct' => '87.500'])
            ->assertCreated();

        $this->assertDatabaseHas('activity_log', [
            'tenant_id' => $tenant->id,
            'subject_type' => Karat::class,
            'event' => 'created',
            'causer_id' => $this->owner($tenant)->id,
        ]);
    }
}
