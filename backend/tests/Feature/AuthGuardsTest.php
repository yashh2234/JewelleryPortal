<?php

namespace Tests\Feature;

use App\Models\Kaarigar;
use App\Models\KaarigarUser;
use App\Models\Retailer;
use App\Models\RetailerUser;
use App\Models\Tenant;
use App\Services\Auth\LoginOtp;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthGuardsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('acme');
    }

    private function retailerUser(string $status = Retailer::STATUS_ACTIVE): RetailerUser
    {
        return $this->asTenant($this->tenant, function () use ($status) {
            $retailer = Retailer::create(['shop_name' => 'Sharma', 'status' => $status]);

            return $retailer->users()->create(['name' => 'R', 'email' => 'shop@sharma.test', 'password' => 'password']);
        });
    }

    private function kaarigarUser(): KaarigarUser
    {
        return $this->asTenant($this->tenant, fn () => Kaarigar::create(['name' => 'Ramesh', 'type' => 'in_house'])
            ->appUsers()->create(['name' => 'Ramesh', 'phone' => '9876500001']));
    }

    public function test_retailer_can_never_reach_staff_routes(): void
    {
        $retailer = $this->retailerUser();

        $this->actingAs($retailer, 'retailer')
            ->getJson($this->on($this->tenant, 'api/v1/auth/me'))
            ->assertUnauthorized();

        $this->actingAs($retailer, 'retailer')
            ->getJson($this->on($this->tenant, 'api/v1/karats'))
            ->assertUnauthorized();
    }

    public function test_kaarigar_cannot_reach_staff_or_portal(): void
    {
        $k = $this->kaarigarUser();

        $this->actingAs($k, 'kaarigar')->getJson($this->on($this->tenant, 'api/v1/auth/me'))->assertUnauthorized();
        $this->actingAs($k, 'kaarigar')->getJson($this->on($this->tenant, 'api/portal/v1/me'))->assertUnauthorized();
        $this->actingAs($k, 'kaarigar')->getJson($this->on($this->tenant, 'api/kaarigar/v1/me'))
            ->assertOk()->assertJsonPath('user.kaarigar.name', 'Ramesh');
    }

    public function test_staff_cannot_use_portal_routes(): void
    {
        $this->actingAs($this->owner($this->tenant), 'staff')
            ->getJson($this->on($this->tenant, 'api/portal/v1/me'))
            ->assertUnauthorized();
    }

    public function test_permissions_gate_master_writes(): void
    {
        $metal = $this->staffWithRole($this->tenant, 'metal_manager');

        $this->actingAs($metal, 'staff')
            ->postJson($this->on($this->tenant, 'api/v1/karats'), ['label' => '21K', 'purity_pct' => '87.5'])
            ->assertForbidden();

        $this->actingAs($metal, 'staff')
            ->getJson($this->on($this->tenant, 'api/v1/kaarigars'))
            ->assertOk();

        $this->actingAs($this->owner($this->tenant), 'staff')
            ->postJson($this->on($this->tenant, 'api/v1/karats'), ['label' => '21K', 'purity_pct' => '87.5'])
            ->assertCreated()->assertJsonPath('data.purity_pct', '87.500');
    }

    public function test_deactivated_staff_is_logged_out(): void
    {
        $user = $this->staffWithRole($this->tenant, 'sales');
        $this->asTenant($this->tenant, fn () => $user->update(['is_active' => false]));

        $this->actingAs($user, 'staff')->getJson($this->on($this->tenant, 'api/v1/auth/me'))->assertForbidden();
    }

    public function test_workspace_keeps_one_active_owner(): void
    {
        $owner = $this->owner($this->tenant);

        $this->actingAs($owner, 'staff')
            ->patchJson($this->on($this->tenant, "api/v1/users/{$owner->id}"), ['roles' => ['sales']])
            ->assertUnprocessable();
    }

    public function test_retailer_signup_needs_approval(): void
    {
        $this->fromSpa($this->tenant)->postJson($this->on($this->tenant, 'api/portal/v1/register'), [
            'shop_name' => 'Verma Gold', 'contact_name' => 'Verma', 'city' => 'Nagpur', 'phone' => '9822000001',
            'email' => 'verma@shop.test', 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated()->assertJsonPath('retailer.status', 'pending');

        $login = ['email' => 'verma@shop.test', 'password' => 'secret123'];

        $this->fromSpa($this->tenant)->postJson($this->on($this->tenant, 'api/auth/login'), $login)
            ->assertForbidden()->assertJsonPath('status', 'pending');

        $retailer = $this->asTenant($this->tenant, fn () => Retailer::where('shop_name', 'Verma Gold')->first());
        $this->actingAs($this->owner($this->tenant), 'staff')
            ->postJson($this->on($this->tenant, "api/v1/retailers/{$retailer->id}/approve"))
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->fromSpa($this->tenant)->postJson($this->on($this->tenant, 'api/auth/login'), $login)
            ->assertOk()->assertJsonPath('user.retailer.shop_name', 'Verma Gold');
    }

    public function test_kaarigar_otp_login(): void
    {
        $sms = new class implements SmsSender
        {
            public array $sent = [];

            public function sendOtp(string $phone, string $code): void
            {
                $this->sent[$phone] = $code;
            }
        };
        $this->app->instance(SmsSender::class, $sms);
        $this->kaarigarUser();

        $this->fromSpa($this->tenant)->postJson($this->on($this->tenant, 'api/auth/otp'), ['phone' => '9876500001'])
            ->assertOk()->assertJsonMissingPath('debug_otp');

        // Unknown numbers get the same answer and no SMS.
        $this->fromSpa($this->tenant)->postJson($this->on($this->tenant, 'api/auth/otp'), ['phone' => '9000000000'])
            ->assertOk();
        $this->assertArrayNotHasKey('9000000000', $sms->sent);

        $this->fromSpa($this->tenant)->postJson($this->on($this->tenant, 'api/auth/otp/verify'), ['phone' => '9876500001', 'otp' => '000000'])
            ->assertUnprocessable();

        $this->fromSpa($this->tenant)->postJson($this->on($this->tenant, 'api/auth/otp/verify'), ['phone' => '9876500001', 'otp' => $sms->sent['9876500001']])
            ->assertOk()->assertJsonPath('user.name', 'Ramesh');

        // OTP is single-use.
        $this->fromSpa($this->tenant)->postJson($this->on($this->tenant, 'api/auth/otp/verify'), ['phone' => '9876500001', 'otp' => $sms->sent['9876500001']])
            ->assertUnprocessable();
    }

    public function test_otp_locks_after_too_many_wrong_attempts(): void
    {
        $sms = new class implements SmsSender
        {
            public ?string $code = null;

            public function sendOtp(string $phone, string $code): void
            {
                $this->code = $code;
            }
        };
        $this->app->instance(SmsSender::class, $sms);
        $this->kaarigarUser();

        $otp = app(LoginOtp::class);
        $this->asTenant($this->tenant, function () use ($otp, $sms) {
            $otp->send('9876500001');
            for ($i = 0; $i < 5; $i++) {
                $this->assertNull($otp->verify('9876500001', $sms->code === '111111' ? '222222' : '111111'));
            }
            $this->assertNull($otp->verify('9876500001', $sms->code), 'Correct code must be refused once locked');
        });
    }
}
