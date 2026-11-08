<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Notifications\ResetPasswordLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_trial_is_read_only(): void
    {
        $tenant = $this->makeTenant('acme');
        $tenant->update(['trial_ends_at' => now()->subDay()]);
        $owner = $this->owner($tenant);

        $this->actingAs($owner, 'staff')->getJson($this->on($tenant, 'api/v1/karats'))->assertOk();
        $this->actingAs($owner, 'staff')
            ->postJson($this->on($tenant, 'api/v1/karats'), ['label' => '21K', 'purity_pct' => '87.5'])
            ->assertStatus(402)->assertJsonPath('code', 'trial_expired');

        $this->getJson($this->on($tenant, 'api/tenant'))->assertJsonPath('data.read_only', true);
    }

    public function test_plan_limits_apply(): void
    {
        $tenant = $this->makeTenant('acme');
        Plan::where('id', $tenant->plan_id)->update(['max_designs' => 1, 'max_users' => 1]);
        $owner = $this->owner($tenant);

        $this->actingAs($owner, 'staff')->postJson($this->on($tenant, 'api/v1/designs'), ['code' => 'A', 'name' => 'A'])->assertCreated();
        $this->actingAs($owner, 'staff')->postJson($this->on($tenant, 'api/v1/designs'), ['code' => 'B', 'name' => 'B'])
            ->assertUnprocessable()->assertJsonPath('message', 'Your plan allows 1 designs. Upgrade the plan to add more.');

        $this->actingAs($owner, 'staff')->postJson($this->on($tenant, 'api/v1/users'), [
            'name' => 'X', 'email' => 'x@acme.test', 'password' => 'secret123', 'roles' => ['sales'],
        ])->assertUnprocessable();
    }

    public function test_super_admin_manages_workspaces(): void
    {
        $tenant = $this->makeTenant('acme');
        $pro = Plan::where('code', 'pro')->first();
        PlatformAdmin::create(['name' => 'Yash', 'email' => 'admin@platform.test', 'password' => 'secret123']);

        $this->getJson('http://yourapp.test/api/platform/admin/tenants')->assertUnauthorized();

        $this->withHeaders(['Referer' => 'http://yourapp.test/'])
            ->postJson('http://yourapp.test/api/platform/admin/login', ['email' => 'admin@platform.test', 'password' => 'secret123'])
            ->assertOk();

        $this->getJson('http://yourapp.test/api/platform/admin/tenants')
            ->assertOk()->assertJsonPath('data.0.subdomain', 'acme')->assertJsonPath('data.0.users_count', 1);

        $this->patchJson("http://yourapp.test/api/platform/admin/tenants/{$tenant->id}", ['status' => 'active', 'plan_id' => $pro->id])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.plan.code', 'pro');

        // Tenant staff can't use the admin API.
        $this->postJson('http://yourapp.test/api/platform/admin/logout')->assertOk();
        $this->actingAs($this->owner($tenant), 'staff')->getJson('http://yourapp.test/api/platform/admin/tenants')->assertUnauthorized();
    }

    public function test_password_reset_for_staff(): void
    {
        Notification::fake();
        $tenant = $this->makeTenant('acme');

        $this->fromSpa($tenant)->postJson($this->on($tenant, 'api/auth/forgot'), ['email' => 'owner@acme.test'])->assertOk();
        $this->fromSpa($tenant)->postJson($this->on($tenant, 'api/auth/forgot'), ['email' => 'nobody@acme.test'])->assertOk();

        $url = null;
        Notification::assertSentTo(new AnonymousNotifiable, ResetPasswordLink::class, function ($n, $channels, $notifiable) use (&$url) {
            $url = (fn () => $this->url)->call($n);

            return $notifiable->routes['mail'] === 'owner@acme.test';
        });
        Notification::assertCount(1);

        parse_str(parse_url($url, PHP_URL_QUERY), $q);

        $this->fromSpa($tenant)->postJson($this->on($tenant, 'api/auth/reset'), [
            'email' => 'owner@acme.test', 'token' => 'wrong', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertUnprocessable();

        $this->fromSpa($tenant)->postJson($this->on($tenant, 'api/auth/reset'), [
            'email' => 'owner@acme.test', 'token' => $q['token'], 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertOk();

        $this->fromSpa($tenant)->postJson($this->on($tenant, 'api/auth/login'), ['email' => 'owner@acme.test', 'password' => 'newpass123'])
            ->assertOk()->assertJsonPath('type', 'staff');

        // Single use.
        $this->fromSpa($tenant)->postJson($this->on($tenant, 'api/auth/reset'), [
            'email' => 'owner@acme.test', 'token' => $q['token'], 'password' => 'another123', 'password_confirmation' => 'another123',
        ])->assertUnprocessable();
    }
}
