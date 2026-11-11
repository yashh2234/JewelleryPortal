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
        // These tests drive the ledger with loose job cards; the work-order rule has its own tests.
        $this->tenant->update(['settings' => ['require_work_order' => false]]);
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
            'process_id' => $this->asTenant($this->tenant, fn () => Process::where('name', 'Soldering')->value('id')),
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

        // 91.600 − 90.134 − 0.183 = 1.283 g difference = 1.40 % > 1 % allowed.
        $res->assertJsonPath('job_card.loss_fine', '1.283')
            ->assertJsonPath('job_card.excess_loss_fine', '0.367')
            ->assertJsonPath('job_card.loss_status', 'pending');
        $this->assertStringContainsString('until approved', $res->json('alert'));

        // Nothing is written off on its own: the whole difference stays with Ramesh.
        $this->asOwner()->getJson($this->api('v1/metal/balances?location_type=kaarigar'))
            ->assertOk()->assertJsonPath('data.0.fine', '1.283');

        $this->asOwner()->getJson($this->api('v1/job-cards?alerts=1'))->assertJsonPath('total', 1);

        $this->asOwner()->getJson($this->api('v1/metal/summary'))
            ->assertOk()
            ->assertJsonPath('wastage_alerts', 1)
            ->assertJsonPath('pending_loss_fine', '1.283')
            ->assertJsonPath('gold.kaarigar', '1.283')
            ->assertJsonPath('gold.loss', '0.000');

        // Approval needs a known reason code and a note.
        $this->asOwner()->postJson($this->api("v1/job-cards/{$cardId}/approve-loss"), ['reason_code' => 'because', 'note' => 'x'])->assertUnprocessable();
        $this->asOwner()->postJson($this->api("v1/job-cards/{$cardId}/approve-loss"), ['reason_code' => 'polishing_loss', 'note' => 'Agreed after checking'])
            ->assertOk()->assertJsonPath('data.loss_status', 'approved')->assertJsonPath('data.loss_reason_code', 'polishing_loss');
        $this->asOwner()->getJson($this->api('v1/metal/summary'))->assertJsonPath('wastage_alerts', 0)->assertJsonPath('gold.loss', '1.283');
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

    public function test_kaarigar_reports_raw_gold_dust_and_rejects_in_a_return(): void
    {
        $this->stock();
        $issue = $this->issue('50');
        $cardId = $issue['job_card']['id'];

        $this->actingAs($this->rameshApp, 'kaarigar')->post($this->api("kaarigar/v1/jobs/{$cardId}/return"), [
            'work_gross' => '40', 'raw_gross' => '5', 'dust_gross' => '0.4', 'rejected_gross' => '4', 'rejected_pieces' => 2,
        ], ['Accept' => 'application/json'])->assertCreated();

        $reqId = $this->asOwner()->getJson($this->api('v1/kaarigar-requests'))->json('data.0.id');
        $tx = $this->asOwner()->postJson($this->api("v1/kaarigar-requests/{$reqId}/approve"), ['destination' => 'finished'])->assertOk()->json('transaction');

        $this->assertEqualsCanonicalizing(['work', 'raw', 'rejected', 'dust'], array_column($tx['lines'], 'kind'));
        $this->asOwner()->getJson($this->api('v1/metal/stock?location=scrap'))->assertJsonPath('total_fine', '0.366');
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

    public function test_head_kaarigar_hands_work_to_his_sub_kaarigar_in_the_app(): void
    {
        Storage::fake('public');
        $this->stock();
        [$suresh, $sureshApp, $imran] = $this->asTenant($this->tenant, function () {
            $s = Kaarigar::create(['name' => 'Suresh', 'head_kaarigar_id' => $this->ramesh->id]);
            $i = Kaarigar::create(['name' => 'Imran']);   // not under Ramesh

            return [$s, $s->appUsers()->create(['name' => 'Suresh', 'phone' => '9876500003']), $i];
        });
        $issue = $this->issue('100');   // 91.600 fine to Ramesh
        $cardId = $issue['job_card']['id'];
        $casting = $this->asTenant($this->tenant, fn () => Process::where('name', 'Casting')->value('id'));
        $r = fn () => $this->actingAs($this->rameshApp, 'kaarigar');
        $s = fn () => $this->actingAs($sureshApp, 'kaarigar');

        $r()->getJson($this->api('kaarigar/v1/home'))->assertJsonPath('is_head', true);
        $r()->getJson($this->api('kaarigar/v1/team'))->assertOk()->assertJsonPath('data.0.name', 'Suresh');

        // Only to his own sub-kaarigars.
        $r()->postJson($this->api("kaarigar/v1/jobs/{$cardId}/handoff"), ['kaarigar_id' => $imran->id, 'gross' => '10'])->assertUnprocessable();

        $r()->postJson($this->api("kaarigar/v1/jobs/{$cardId}/handoff"), [
            'kaarigar_id' => $suresh->id, 'process_id' => $casting, 'gross' => '60', 'pieces' => 20, 'note' => 'Cast 20 kade',
        ])->assertCreated();

        // Custody moved; the vault did not move again.
        $r()->getJson($this->api('kaarigar/v1/home'))->assertJsonPath('gold.fine', '36.640');
        $this->asOwner()->getJson($this->api('v1/metal/stock?location=vault'))->assertJsonPath('total_fine', '824.400');

        // Suresh must accept it, like an issue.
        $s()->getJson($this->api('kaarigar/v1/issues'))->assertJsonPath('data.0.type', 'handoff')->assertJsonPath('data.0.from', 'Ramesh');
        $handoffId = $s()->getJson($this->api('kaarigar/v1/issues'))->json('data.0.id');
        $s()->postJson($this->api("kaarigar/v1/issues/{$handoffId}/accept"))->assertOk();

        // Progress update with a photo, then hand 59.8 g back to Ramesh.
        $sub = $s()->getJson($this->api('kaarigar/v1/jobs'))->assertJsonPath('data.0.from_kaarigar', 'Ramesh')->assertJsonPath('data.0.can_hand_back', true)->json('data.0');
        $s()->post($this->api("kaarigar/v1/jobs/{$sub['id']}/status"), [
            'status' => 'ready', 'note' => 'Casting done', 'photos' => [UploadedFile::fake()->image('cast.jpg')],
        ], ['Accept' => 'application/json'])->assertOk();
        $s()->getJson($this->api('kaarigar/v1/jobs'))->assertJsonPath('data.0.note', 'Casting done')->assertJsonCount(1, 'data.0.photos');

        $s()->postJson($this->api("kaarigar/v1/jobs/{$sub['id']}/hand-back"), ['gross' => '59.8'])->assertCreated();
        $s()->getJson($this->api('kaarigar/v1/home'))->assertJsonPath('gold.fine', '0.183');

        // The store sees the trail on the head's card, with who posted it.
        $card = $this->asOwner()->getJson($this->api("v1/job-cards/{$cardId}"))->assertOk();
        $card->assertJsonPath('data.live.handed_out', '54.960')->assertJsonPath('data.live.handed_in', '54.777')
            ->assertJsonPath('data.children.0.kaarigar.name', 'Suresh');
        $this->assertStringContainsString('kaarigar app', $card->json('transactions.1.created_by'));
    }

    public function test_store_allocates_between_kaarigars_and_gold_ledger_shows_handoffs(): void
    {
        $this->stock();
        $issue = $this->issue('50');
        $imran = $this->asTenant($this->tenant, fn () => Kaarigar::create(['name' => 'Imran']));

        $tx = $this->asOwner()->postJson($this->api("v1/job-cards/{$issue['job_card']['id']}/allocate"), [
            'kaarigar_id' => $imran->id, 'gross' => '20',
        ])->assertCreated()->assertJsonPath('data.type', 'handoff')->json('data');

        $this->assertSame($issue['job_card']['card_no'], $tx['from_job_card']['card_no']);
        $this->asOwner()->getJson($this->api('v1/metal/transactions?type=handoff'))->assertJsonPath('total', 1);
        $this->asOwner()->postJson($this->api("v1/job-cards/{$tx['job_card']['id']}/hand-back"), ['gross' => '5'])->assertCreated();

        // Day-book / closing and the wastage report are gone.
        $this->asOwner()->getJson($this->api('v1/metal/daybook'))->assertNotFound();
        $this->asOwner()->getJson($this->api('v1/reports/wastage'))->assertNotFound();
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
