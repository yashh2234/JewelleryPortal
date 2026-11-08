<?php

namespace Tests\Feature;

use App\Models\Kaarigar;
use App\Models\KaarigarUser;
use App\Models\Karat;
use App\Models\Process;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoldLedgerApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Kaarigar $ramesh;

    private KaarigarUser $rameshApp;

    private int $k22;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('acme');
        $this->owner = $this->owner($this->tenant);

        [$this->ramesh, $this->rameshApp, $this->k22] = $this->asTenant($this->tenant, function () {
            $k = Kaarigar::create(['name' => 'Ramesh', 'phone' => '9876500001']);

            return [$k, $k->appUsers()->create(['name' => 'Ramesh', 'phone' => '9876500001']), Karat::where('label', '22K')->value('id')];
        });
    }

    private function api(string $path): string
    {
        return $this->on($this->tenant, "api/{$path}");
    }

    private function asOwner(): static
    {
        return $this->actingAs($this->owner, 'staff');
    }

    private function stock(): void
    {
        $this->asOwner()->postJson($this->api('v1/metal/purchase'), [
            'items' => [['karat_id' => $this->k22, 'gross' => '1000']], 'party_name' => 'Bullion dealer',
        ])->assertCreated();
    }

    private function issue(string $gross = '100'): array
    {
        return $this->asOwner()->postJson($this->api('v1/metal/issue'), [
            'source' => 'vault',
            'kaarigar_id' => $this->ramesh->id,
            'process_id' => $this->asTenant($this->tenant, fn () => Process::where('name', 'Making')->value('id')),
            'karat_id' => $this->k22,
            'description' => '50 Classic kade',
            'items' => [['karat_id' => $this->k22, 'gross' => $gross]],
        ])->assertCreated()->json('data');
    }

    public function test_issue_receive_close_over_http(): void
    {
        $this->stock();
        $issue = $this->issue('100');      // 91.600 fine
        $this->assertSame('91.600', $issue['fine_total']);
        $cardId = $issue['job_card']['id'];

        $res = $this->asOwner()->postJson($this->api('v1/metal/receive'), [
            'job_card_id' => $cardId, 'destination' => 'finished',
            'work' => ['gross' => '98.4'], 'scrap' => ['gross' => '0.2'], 'close' => true,
        ])->assertCreated();

        // 91.600 − 90.134 − 0.183 = 1.283 g loss = 1.40 % > 1 % allowed.
        $res->assertJsonPath('job_card.loss_fine', '1.283')
            ->assertJsonPath('job_card.excess_loss_fine', '0.367');
        $this->assertStringContainsString('above the allowed', $res->json('alert'));

        $this->asOwner()->getJson($this->api('v1/metal/balances?location_type=kaarigar'))
            ->assertOk()->assertJsonPath('data.0.fine', '0.367');

        $this->asOwner()->getJson($this->api('v1/job-cards?alerts=1'))->assertJsonPath('total', 1);

        $this->asOwner()->getJson($this->api('v1/metal/summary'))
            ->assertOk()
            ->assertJsonPath('wastage_alerts', 1)
            ->assertJsonPath('gold.kaarigar', '0.367')
            ->assertJsonPath('gold.loss', '0.916');

        $this->asOwner()->getJson($this->api('v1/reports/wastage'))
            ->assertOk()->assertJsonPath('data.0.name', 'Ramesh')->assertJsonPath('data.0.wastage_pct', '1.401');

        $this->asOwner()->postJson($this->api("v1/job-cards/{$cardId}/write-off-excess"), ['reason' => 'Agreed'])->assertCreated();
        $this->asOwner()->getJson($this->api('v1/metal/summary'))->assertJsonPath('wastage_alerts', 0);
    }

    public function test_stock_errors_are_clear(): void
    {
        $this->asOwner()->postJson($this->api('v1/metal/issue'), [
            'source' => 'vault', 'kaarigar_id' => $this->ramesh->id,
            'process_id' => $this->asTenant($this->tenant, fn () => Process::value('id')), 'karat_id' => $this->k22,
            'items' => [['karat_id' => $this->k22, 'gross' => '10']],
        ])->assertUnprocessable()->assertJsonPath('message', 'Vault has only 0.000 g fine 22K gold; this needs 9.160 g.');
    }

    public function test_metal_permissions(): void
    {
        $sales = $this->staffWithRole($this->tenant, 'sales');
        $metal = $this->staffWithRole($this->tenant, 'metal_manager');

        $this->actingAs($sales, 'staff')->postJson($this->api('v1/metal/purchase'), ['items' => [['karat_id' => $this->k22, 'gross' => '1']]])->assertForbidden();
        $this->actingAs($metal, 'staff')->postJson($this->api('v1/metal/purchase'), ['items' => [['karat_id' => $this->k22, 'gross' => '1']]])->assertCreated();

        // Reversals and approving excess loss are owner-level.
        $txId = $this->asOwner()->getJson($this->api('v1/metal/transactions'))->json('data.0.id');
        $this->actingAs($metal, 'staff')->postJson($this->api("v1/metal/transactions/{$txId}/reverse"), ['reason' => 'x'])->assertForbidden();
        $this->asOwner()->postJson($this->api("v1/metal/transactions/{$txId}/reverse"), ['reason' => 'Wrong weight'])
            ->assertCreated()->assertJsonPath('data.type', 'reversal');
    }

    public function test_kaarigar_app_accept_return_and_store_approval(): void
    {
        Storage::fake('public');
        $this->stock();
        $issue = $this->issue('50');   // 45.800 fine
        $cardId = $issue['job_card']['id'];

        $k = fn () => $this->actingAs($this->rameshApp, 'kaarigar');

        $k()->getJson($this->api('kaarigar/v1/home'))->assertOk()->assertJsonPath('gold.fine', '45.800')->assertJsonPath('new_issues', 1);
        $k()->getJson($this->api('kaarigar/v1/issues'))->assertJsonPath('data.0.voucher_no', $issue['voucher_no']);
        $k()->postJson($this->api("kaarigar/v1/issues/{$issue['id']}/accept"))->assertOk();
        $k()->getJson($this->api('kaarigar/v1/home'))->assertJsonPath('new_issues', 0);

        // Return request with photos: no ledger change yet.
        $k()->post($this->api("kaarigar/v1/jobs/{$cardId}/return"), [
            'work_gross' => '49.5', 'scrap_gross' => '0.2', 'pieces' => 50, 'client_key' => 'offline-1',
            'work_photos' => [UploadedFile::fake()->image('work.jpg')],
            'scale_photos' => [UploadedFile::fake()->image('scale.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();

        // Offline replay with the same key doesn't create a second request.
        $k()->post($this->api("kaarigar/v1/jobs/{$cardId}/return"), ['work_gross' => '49.5', 'client_key' => 'offline-1'], ['Accept' => 'application/json'])->assertOk();

        $k()->getJson($this->api('kaarigar/v1/home'))->assertJsonPath('gold.fine', '45.800');

        $requests = $this->asOwner()->getJson($this->api('v1/kaarigar-requests'))->assertOk()->assertJsonPath('total', 1);
        $this->assertCount(2, $requests->json('data.0.attachments'));

        // Store weighs: 49.4 g work (not 49.5) and closes the card.
        $this->asOwner()->postJson($this->api('v1/kaarigar-requests/'.$requests->json('data.0.id').'/approve'), [
            'work_gross' => '49.4', 'close' => true,
        ])->assertOk()->assertJsonPath('transaction.type', 'receive')->assertJsonPath('job_card.status', 'closed');

        $k()->getJson($this->api('kaarigar/v1/khata'))
            ->assertOk()
            ->assertJsonPath('totals.issued', '45.800')
            ->assertJsonPath('totals.returned', '45.250');
    }

    public function test_kaarigar_cannot_touch_someone_elses_issue(): void
    {
        $this->stock();
        $issue = $this->issue('10');
        $other = $this->asTenant($this->tenant, fn () => Kaarigar::create(['name' => 'Imran'])->appUsers()->create(['name' => 'Imran', 'phone' => '9876500002']));

        $this->actingAs($other, 'kaarigar')->postJson($this->api("kaarigar/v1/issues/{$issue['id']}/accept"))->assertNotFound();
        $this->actingAs($other, 'kaarigar')->getJson($this->api('kaarigar/v1/home'))->assertJsonPath('gold.fine', '0.000');
    }

    public function test_dispute_shows_on_dashboard(): void
    {
        $this->stock();
        $issue = $this->issue('10');

        $this->actingAs($this->rameshApp, 'kaarigar')
            ->postJson($this->api("kaarigar/v1/issues/{$issue['id']}/dispute"), ['reason' => 'Got 9.8 g only', 'weight_received' => '9.8'])
            ->assertOk();

        $this->asOwner()->getJson($this->api('v1/metal/summary'))
            ->assertJsonPath('disputed_issues', 1)->assertJsonPath('pending_requests', 1);
    }

    public function test_daybook_and_closing(): void
    {
        $this->stock();   // 916.000 fine in vault

        $this->asOwner()->getJson($this->api('v1/metal/daybook'))
            ->assertOk()->assertJsonPath('closing.vault', '916.000')->assertJsonCount(1, 'transactions');

        $this->asOwner()->postJson($this->api('v1/metal/closing'), ['date' => today()->toDateString(), 'physical_vault_fine' => '915.900'])
            ->assertUnprocessable();

        $this->asOwner()->postJson($this->api('v1/metal/closing'), [
            'date' => today()->toDateString(), 'physical_vault_fine' => '916.000',
        ])->assertCreated()->assertJsonPath('data.difference_fine', '0.000');

        // The day is now locked.
        $this->asOwner()->postJson($this->api('v1/metal/purchase'), ['items' => [['karat_id' => $this->k22, 'gross' => '1']]])
            ->assertUnprocessable()->assertJsonPath('message', 'The books are closed up to '.today()->toDateString().'. Post this entry with a later date.');
    }

    public function test_slip_and_statement_pdfs_and_qr_page(): void
    {
        $this->stock();
        $issue = $this->issue('10');

        $this->asOwner()->get($this->api("v1/metal/transactions/{$issue['id']}/slip"))
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->asOwner()->get($this->api("v1/kaarigars/{$this->ramesh->id}/ledger.pdf"))
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $token = $this->asTenant($this->tenant, fn () => $this->ramesh->fresh()->slip_token);
        $this->get($this->on($this->tenant, "khata/{$token}"))->assertOk()->assertSee('Ramesh')->assertSee('9.160');
    }

    public function test_nightly_rebuild_checks_the_books(): void
    {
        $this->stock();
        $this->issue('10');

        $this->artisan('metal:rebuild-balances')->expectsOutput('All ledgers balance.')->assertSuccessful();
        $this->assertDatabaseCount('location_balances', 3);   // outside, vault, Ramesh
    }
}
