<?php

namespace Tests\Feature;

use App\Models\Kaarigar;
use App\Models\Retailer;
use App\Models\Tenant;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** One login screen; the server decides staff / retailer / kaarigar. */
class UnifiedLoginTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('acme');

        $this->asTenant($this->tenant, function () {
            Retailer::create(['shop_name' => 'Sharma', 'status' => Retailer::STATUS_ACTIVE])
                ->users()->create(['name' => 'R', 'email' => 'shop@sharma.test', 'password' => 'password']);
            Kaarigar::create(['name' => 'Ramesh'])->appUsers()->create(['name' => 'Ramesh', 'phone' => '9876500001']);
        });
    }

    private function login(array $body)
    {
        return $this->fromSpa($this->tenant)->postJson($this->on($this->tenant, 'api/auth/login'), $body);
    }

    public function test_staff_email_lands_on_staff(): void
    {
        $this->login(['email' => 'owner@acme.test', 'password' => 'password'])
            ->assertOk()->assertJsonPath('type', 'staff')->assertJsonPath('user.roles.0', 'owner');

        $this->getJson($this->on($this->tenant, 'api/auth/me'))->assertOk()->assertJsonPath('type', 'staff');
        $this->getJson($this->on($this->tenant, 'api/v1/auth/me'))->assertOk();
        $this->getJson($this->on($this->tenant, 'api/portal/v1/me'))->assertUnauthorized();
    }

    public function test_retailer_email_lands_on_portal(): void
    {
        $this->login(['email' => 'shop@sharma.test', 'password' => 'password'])
            ->assertOk()->assertJsonPath('type', 'retailer')->assertJsonPath('user.retailer.shop_name', 'Sharma');

        $this->getJson($this->on($this->tenant, 'api/auth/me'))->assertJsonPath('type', 'retailer');
        $this->getJson($this->on($this->tenant, 'api/v1/karats'))->assertUnauthorized();
    }

    public function test_wrong_password_is_rejected_for_everyone(): void
    {
        $this->login(['email' => 'shop@sharma.test', 'password' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->getJson($this->on($this->tenant, 'api/auth/me'))->assertUnauthorized();
    }

    public function test_kaarigar_mobile_lands_on_kaarigar(): void
    {
        $sms = new class implements SmsSender {
            public ?string $code = null;

            public function sendOtp(string $phone, string $code): void
            {
                $this->code = $code;
            }
        };
        $this->app->instance(SmsSender::class, $sms);

        $this->fromSpa($this->tenant)->postJson($this->on($this->tenant, 'api/auth/otp'), ['phone' => '9876500001'])->assertOk();
        $this->fromSpa($this->tenant)->postJson($this->on($this->tenant, 'api/auth/otp/verify'), ['phone' => '9876500001', 'otp' => $sms->code])
            ->assertOk()->assertJsonPath('type', 'kaarigar')->assertJsonPath('user.kaarigar.name', 'Ramesh');

        $this->getJson($this->on($this->tenant, 'api/auth/me'))->assertJsonPath('type', 'kaarigar');
    }

    /** One mobile box for everyone: the number decides staff / retailer / kaarigar. */
    public function test_mobile_otp_works_for_staff_and_retailers_too(): void
    {
        $sms = new class implements SmsSender {
            public array $codes = [];

            public function sendOtp(string $phone, string $code): void
            {
                $this->codes[$phone] = $code;
            }
        };
        $this->app->instance(SmsSender::class, $sms);

        $this->asTenant($this->tenant, function () {
            \App\Models\User::role('owner')->first()->update(['phone' => '+91 98765 00010']);
            \App\Models\RetailerUser::where('email', 'shop@sharma.test')->update(['phone' => '9822000000']);
        });

        foreach ([['098765 00010', 'staff'], ['9822000000', 'retailer'], ['9876500001', 'kaarigar']] as [$typed, $type]) {
            $this->fromSpa($this->tenant)->postJson($this->on($this->tenant, 'api/auth/otp'), ['phone' => $typed])->assertOk();
            $digits = substr(preg_replace('/\D/', '', $typed), -10);
            $this->fromSpa($this->tenant)->postJson($this->on($this->tenant, 'api/auth/otp/verify'), ['phone' => $typed, 'otp' => $sms->codes[$digits]])
                ->assertOk()->assertJsonPath('type', $type);
        }

        // Unknown numbers: same answer, no SMS.
        $this->fromSpa($this->tenant)->postJson($this->on($this->tenant, 'api/auth/otp'), ['phone' => '9000000000'])->assertOk();
        $this->assertArrayNotHasKey('9000000000', $sms->codes);

        $this->fromSpa($this->tenant)->postJson($this->on($this->tenant, 'api/auth/otp'), ['phone' => '12345'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_signing_in_as_one_type_signs_out_the_other(): void
    {
        $this->login(['email' => 'owner@acme.test', 'password' => 'password'])->assertOk();
        $this->login(['email' => 'shop@sharma.test', 'password' => 'password'])->assertOk();

        $this->getJson($this->on($this->tenant, 'api/auth/me'))->assertJsonPath('type', 'retailer');
        $this->getJson($this->on($this->tenant, 'api/v1/auth/me'))->assertUnauthorized();
    }

    public function test_logout_ends_every_session(): void
    {
        $this->login(['email' => 'owner@acme.test', 'password' => 'password'])->assertOk();

        $this->fromSpa($this->tenant)->postJson($this->on($this->tenant, 'api/auth/logout'))->assertOk();

        $this->getJson($this->on($this->tenant, 'api/auth/me'))->assertUnauthorized();
    }
}
