<?php

namespace Tests\Feature;

use App\Models\DesignImage;
use App\Models\Karat;
use App\Models\Process;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MastersTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('acme');
        $this->owner = $this->owner($this->tenant);
        $this->actingAs($this->owner, 'staff');
    }

    private function url(string $path): string
    {
        return $this->on($this->tenant, "api/v1/{$path}");
    }

    public function test_idempotency_key_prevents_double_posting(): void
    {
        $headers = ['Idempotency-Key' => 'k-123'];
        $body = ['label' => '21K', 'purity_pct' => '87.500'];

        $first = $this->postJson($this->url('karats'), $body, $headers)->assertCreated();
        $this->postJson($this->url('karats'), $body, $headers)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, $this->asTenant($this->tenant, fn () => Karat::where('label', '21K')->count()));

        $this->postJson($this->url('karats'), ['label' => '19K'] + $body, $headers)->assertUnprocessable();
    }

    public function test_failed_validation_is_replayed_but_not_reposted(): void
    {
        $headers = ['Idempotency-Key' => 'k-bad'];
        $this->postJson($this->url('karats'), ['label' => '21K'], $headers)->assertUnprocessable();
        $this->postJson($this->url('karats'), ['label' => '21K'], $headers)
            ->assertUnprocessable()->assertHeader('Idempotent-Replayed', 'true');
    }

    public function test_karat_purity_is_validated_and_unique_per_tenant(): void
    {
        $this->postJson($this->url('karats'), ['label' => '22K', 'purity_pct' => '91.6'])
            ->assertUnprocessable()->assertJsonValidationErrors('label');

        $this->postJson($this->url('karats'), ['label' => 'X', 'purity_pct' => '101'])
            ->assertUnprocessable()->assertJsonValidationErrors('purity_pct');

        $this->postJson($this->url('karats'), ['label' => 'X', 'purity_pct' => '91.6666'])
            ->assertUnprocessable()->assertJsonValidationErrors('purity_pct');
    }

    public function test_wastage_rules_override_process_default_per_karat(): void
    {
        [$process, $k22, $k18] = $this->asTenant($this->tenant, fn () => [
            Process::where('name', 'Soldering')->first(), Karat::where('label', '22K')->first(), Karat::where('label', '18K')->first(),
        ]);

        $this->putJson($this->url("processes/{$process->id}/wastage-rules"), [
            'rules' => [['karat_id' => $k18->id, 'allowed_wastage_pct' => '1.250']],
        ])->assertOk()->assertJsonCount(1, 'data.wastage_rules');

        $this->asTenant($this->tenant, function () use ($process, $k22, $k18) {
            $this->assertSame('1.250', $process->fresh()->allowedWastageFor($k18->id));
            $this->assertSame('1.000', $process->fresh()->allowedWastageFor($k22->id));
        });
    }

    public function test_kaarigar_with_app_access(): void
    {
        $this->postJson($this->url('kaarigars'), [
            'name' => 'Ramesh', 'phone' => '9876500001', 'type' => 'in_house', 'app_access' => true,
        ])->assertCreated()->assertJsonPath('data.app_users.0.phone', '9876500001');

        $this->postJson($this->url('kaarigars'), [
            'name' => 'Duplicate', 'phone' => '9876500001', 'type' => 'in_house', 'app_access' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_design_with_karats_collections_and_images(): void
    {
        Storage::fake('public');

        $karatId = $this->asTenant($this->tenant, fn () => Karat::where('label', '22K')->value('id'));
        $collection = $this->postJson($this->url('collections'), ['name' => 'Temple'])->assertCreated()->json('data');

        $design = $this->postJson($this->url('designs'), [
            'code' => 'KD-101', 'name' => 'Classic kada', 'approx_weight_min' => '18.5', 'approx_weight_max' => '22',
            'sizes' => ['2.4', '2.6'], 'making_charge' => '450', 'making_type' => 'per_gram',
            'karat_ids' => [$karatId], 'collection_ids' => [$collection['id']], 'is_published' => true,
        ])->assertCreated()
            ->assertJsonPath('data.approx_weight_min', '18.500')
            ->assertJsonPath('data.karats.0.label', '22K')
            ->json('data');

        $upload = $this->post($this->url("designs/{$design['id']}/images"), [
            'images' => [UploadedFile::fake()->image('kada.jpg', 2400, 1800)],
        ], ['Accept' => 'application/json'])->assertCreated();

        $image = $upload->json('data.0');
        $this->assertStringEndsWith('.webp', $image['url']);
        $stored = $this->asTenant($this->tenant, fn () => DesignImage::find($image['id']));
        Storage::disk('public')->assertExists([$stored->path, $stored->thumb_path]);
        [$w] = getimagesizefromstring(Storage::disk('public')->get($stored->path));
        $this->assertSame(1600, $w);

        $this->deleteJson($this->url("designs/{$design['id']}"))->assertOk();
        Storage::disk('public')->assertMissing($stored->path);
    }

    public function test_route_template_keeps_step_order(): void
    {
        $ids = $this->asTenant($this->tenant, fn () => Process::whereIn('name', ['Casting', 'Filing', 'Polishing'])
            ->orderByDesc('sequence')->pluck('id')->all());

        $this->postJson($this->url('route-templates'), ['name' => 'Cast', 'process_ids' => $ids])
            ->assertCreated()
            ->assertJsonPath('data.steps.0.process.name', 'Polishing')
            ->assertJsonPath('data.steps.2.process.name', 'Casting');
    }

    public function test_gold_rates_fall_back_to_latest_earlier_rate(): void
    {
        [$k22, $k18] = $this->asTenant($this->tenant, fn () => [Karat::where('label', '22K')->value('id'), Karat::where('label', '18K')->value('id')]);

        $this->postJson($this->url('gold-rates'), ['date' => today()->subDay()->toDateString(), 'rates' => [
            ['karat_id' => $k22, 'rate_per_g' => '7100'], ['karat_id' => $k18, 'rate_per_g' => '5800'],
        ]])->assertOk();

        $this->postJson($this->url('gold-rates'), ['date' => today()->toDateString(), 'rates' => [
            ['karat_id' => $k22, 'rate_per_g' => '7200.50'],
        ]])->assertOk();

        $rates = collect($this->getJson($this->url('gold-rates'))->assertOk()->json('data'))->keyBy('karat_id');
        $this->assertSame('7200.50', $rates[$k22]['rate_per_g']);
        $this->assertSame('5800.00', $rates[$k18]['rate_per_g']);
    }

    public function test_staff_user_and_custom_role(): void
    {
        $this->postJson($this->url('roles'), ['name' => 'store_helper', 'permissions' => ['kaarigars.view']])
            ->assertCreated();

        $this->postJson($this->url('users'), [
            'name' => 'Helper', 'email' => 'helper@acme.test', 'password' => 'secret123', 'roles' => ['store_helper'],
        ])->assertCreated()->assertJsonPath('data.permissions', ['kaarigars.view']);

        $this->patchJson($this->url('roles/'.$this->asTenant($this->tenant, fn () => Role::where('name', 'owner')->value('id'))), ['permissions' => []])
            ->assertUnprocessable();
    }

    public function test_manifest_and_spa_are_branded_per_tenant(): void
    {
        $this->get($this->on($this->tenant, 'manifest.webmanifest'))
            ->assertOk()
            ->assertJsonPath('name', 'Acme Gold')
            ->assertJsonPath('scope', '/');

        $public = sys_get_temp_dir().'/spa-test-'.uniqid();
        mkdir($public, 0777, true);
        file_put_contents("{$public}/app.html", '<html><head><title>x</title></head><body></body></html>');
        $this->app->usePublicPath($public);

        $this->get($this->on($this->tenant, 'kaarigar/khata'))
            ->assertOk()
            ->assertSee('window.__BOOT__', false)
            ->assertSee('"subdomain":"acme"', false);

        // API, Sanctum and storage paths never fall through to the app page.
        $this->getJson($this->on($this->tenant, 'api/nope'))->assertNotFound();
    }
}
