<?php

namespace Tests\Feature;

use App\Jobs\SendPush;
use App\Models\Kaarigar;
use App\Models\Karat;
use App\Models\Process;
use App\Services\Push\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PushTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_issue_pushes_to_the_kaarigars_phone(): void
    {
        $tenant = $this->makeTenant('acme');
        [$kaarigar, $app, $k22, $process] = $this->asTenant($tenant, function () {
            $k = Kaarigar::create(['name' => 'Ramesh']);

            return [$k, $k->appUsers()->create(['name' => 'Ramesh', 'phone' => '9876500001']), Karat::where('label', '22K')->value('id'), Process::value('id')];
        });

        // The kaarigar's phone subscribes.
        $this->actingAs($app, 'kaarigar')->postJson($this->on($tenant, 'api/push-subscriptions'), [
            'endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'BPUB', 'auth' => 'AUTH'],
        ])->assertCreated();
        $this->assertDatabaseHas('push_subscriptions', ['user_type' => 'kaarigar', 'user_id' => $app->id]);

        $sent = new class implements PushSender {
            public array $calls = [];

            public function send(array $subscriptions, array $payload): array
            {
                $this->calls[] = [array_column($subscriptions, 'endpoint'), $payload];

                return ['https://push.example/abc'];   // pretend it expired
            }
        };
        $this->app->instance(PushSender::class, $sent);

        $owner = $this->owner($tenant);
        $this->actingAs($owner, 'staff')->postJson($this->on($tenant, 'api/v1/metal/purchase'), ['items' => [['karat_id' => $k22, 'gross' => '100']]])->assertCreated();
        $this->actingAs($owner, 'staff')->postJson($this->on($tenant, 'api/v1/metal/issue'), [
            'source' => 'vault', 'kaarigar_id' => $kaarigar->id, 'process_id' => $process, 'karat_id' => $k22,
            'items' => [['karat_id' => $k22, 'gross' => '10']],
        ])->assertCreated();

        // Queue is sync in tests: the job ran.
        $this->assertCount(1, $sent->calls);
        $this->assertSame(['https://push.example/abc'], $sent->calls[0][0]);
        $this->assertStringContainsString('10.000 g', $sent->calls[0][1]['body']);
        // Expired endpoints are cleaned up.
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_push_is_queued_not_sent_inline(): void
    {
        Queue::fake();
        $tenant = $this->makeTenant('acme');
        [$kaarigar, $k22, $process] = $this->asTenant($tenant, fn () => [
            tap(Kaarigar::create(['name' => 'A']), fn ($k) => $k->appUsers()->create(['name' => 'A', 'phone' => '9876500001'])),
            Karat::where('label', '22K')->value('id'), Process::value('id'),
        ]);
        $owner = $this->owner($tenant);

        $this->actingAs($owner, 'staff')->postJson($this->on($tenant, 'api/v1/metal/purchase'), ['items' => [['karat_id' => $k22, 'gross' => '100']]]);
        $this->actingAs($owner, 'staff')->postJson($this->on($tenant, 'api/v1/metal/issue'), [
            'source' => 'vault', 'kaarigar_id' => $kaarigar->id, 'process_id' => $process, 'karat_id' => $k22,
            'items' => [['karat_id' => $k22, 'gross' => '10']],
        ])->assertCreated();

        Queue::assertPushed(SendPush::class, fn (SendPush $job) => $job->userType === 'kaarigar' && $job->tenantId === $tenant->id);
    }
}
