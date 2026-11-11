<?php

namespace Tests\Feature;

use App\Models\Design;
use App\Models\FinishedItem;
use App\Models\JobCard;
use App\Models\Kaarigar;
use App\Models\Karat;
use App\Models\MetalTransaction;
use App\Models\Order;
use App\Models\Process;
use App\Models\Retailer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Spec v2 rules: gold-issue gating, work-order link, reconciliation before WO
 * completion, QC checklist → rework, portal additions, reports, CRM, audit.
 */
class ErpFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Kaarigar $ramesh;

    private int $k22;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('acme');
        $this->owner = $this->owner($this->tenant);
        [$this->ramesh, $this->k22] = $this->asTenant($this->tenant, fn () => [
            Kaarigar::create(['name' => 'Ramesh', 'phone' => '9876500001']),
            Karat::where('label', '22K')->value('id'),
        ]);
        $this->as()->postJson($this->api('metal/purchase'), ['items' => [['karat_id' => $this->k22, 'gross' => '1000']]])->assertCreated();
    }

    private function api(string $p): string
    {
        return $this->on($this->tenant, "api/v1/{$p}");
    }

    private function as(?User $u = null): static
    {
        return $this->actingAs($u ?? $this->owner, 'staff');
    }

    /** An approved order with one work order; returns [order, wo, firstStepId]. */
    private function orderWithWo(?Retailer $retailer = null): array
    {
        [$design, $retailer] = $this->asTenant($this->tenant, fn () => [
            Design::create(['code' => 'KD-'.uniqid(), 'name' => 'Kada', 'production_days' => 12]),
            $retailer ?? Retailer::create(['shop_name' => 'Sharma', 'status' => 'active']),
        ]);
        $order = $this->as()->postJson($this->api('orders'), ['retailer_id' => $retailer->id, 'lines' => [['design_id' => $design->id, 'karat_id' => $this->k22, 'qty' => 2]]])->json('data');
        $this->as()->postJson($this->api("orders/{$order['id']}/approve"))->assertOk();
        $wo = $this->as()->postJson($this->api("orders/{$order['id']}/work-orders"))->json('data.0');
        $step = $this->as()->getJson($this->api("work-orders/{$wo['id']}"))->json('data.steps.0.id');

        return [$order, $wo, $step, $retailer];
    }

    public function test_loose_issue_needs_a_work_order_and_work_order_id_picks_the_stage(): void
    {
        [, $wo] = $this->orderWithWo();
        $process = $this->asTenant($this->tenant, fn () => Process::value('id'));

        $this->as()->postJson($this->api('metal/issue'), [
            'source' => 'vault', 'kaarigar_id' => $this->ramesh->id, 'process_id' => $process, 'karat_id' => $this->k22,
            'items' => [['karat_id' => $this->k22, 'gross' => '10']],
        ])->assertUnprocessable()->assertJsonFragment(['message' => 'Pick the work order this gold is for. (Loose job cards are turned off in Settings → Gold rules.)']);

        $tx = $this->as()->postJson($this->api('metal/issue'), [
            'source' => 'vault', 'kaarigar_id' => $this->ramesh->id, 'work_order_id' => $wo['id'],
            'items' => [['karat_id' => $this->k22, 'gross' => '10']],
        ])->assertCreated()->json('data');
        $this->assertSame($wo['wo_no'], $this->asTenant($this->tenant, fn () => JobCard::find($tx['job_card']['id'])->woStep->workOrder->wo_no));
    }

    public function test_unacknowledged_issue_blocks_the_next_one_until_signed_or_overridden(): void
    {
        [, $wo, $step] = $this->orderWithWo();
        $issue = fn (array $extra = []) => $this->as()->postJson($this->api("work-orders/{$wo['id']}/issue"), [
            'step_id' => $step, 'kaarigar_id' => $this->ramesh->id, 'items' => [['karat_id' => $this->k22, 'gross' => '10']],
        ] + $extra);

        $first = $issue()->assertCreated()->json('data');
        // A second issue the same day is fine (the 24 h window).
        $issue()->assertCreated();

        // Two days later nobody has acknowledged the first slip.
        $this->asTenant($this->tenant, fn () => MetalTransaction::whereKey($first['id'])->update(['created_at' => now()->subDays(2)]));
        $this->as()->getJson($this->api("metal/issue-check?kaarigar_id={$this->ramesh->id}"))->assertOk()->assertJsonCount(1, 'blockers');
        $issue()->assertUnprocessable();

        // A metal manager may issue but not override the rules: the reason is ignored and the issue is refused.
        $metal = $this->staffWithRole($this->tenant, 'metal_manager');
        $this->as($metal)->postJson($this->api("work-orders/{$wo['id']}/issue"), [
            'step_id' => $step, 'kaarigar_id' => $this->ramesh->id, 'items' => [['karat_id' => $this->k22, 'gross' => '10']], 'override_reason' => 'urgent',
        ])->assertUnprocessable();
        $this->assertFalse(DB::table('activity_log')->where('log_name', 'gold-issue-override')->exists());
    }

    public function test_override_and_signed_slip(): void
    {
        [, $wo, $step] = $this->orderWithWo();
        $body = ['step_id' => $step, 'kaarigar_id' => $this->ramesh->id, 'items' => [['karat_id' => $this->k22, 'gross' => '10']]];
        $first = $this->as()->postJson($this->api("work-orders/{$wo['id']}/issue"), $body)->assertCreated()->json('data');
        $this->asTenant($this->tenant, fn () => MetalTransaction::whereKey($first['id'])->update(['created_at' => now()->subDays(2)]));

        $this->as()->postJson($this->api("work-orders/{$wo['id']}/issue"), $body)->assertUnprocessable();
        $this->as()->postJson($this->api("work-orders/{$wo['id']}/issue"), $body + ['override_reason' => 'Wedding rush, slip signed tomorrow'])->assertCreated();
        $this->assertTrue(DB::table('activity_log')->where('log_name', 'gold-issue-override')->exists());

        // Signing the paper slip clears the block.
        $this->as()->postJson($this->api("metal/transactions/{$first['id']}/acknowledge"))->assertOk();
        $this->as()->getJson($this->api("metal/issue-check?kaarigar_id={$this->ramesh->id}"))->assertJsonCount(0, 'blockers');
    }

    public function test_gold_limit_per_kaarigar(): void
    {
        [, $wo, $step] = $this->orderWithWo();
        $this->as()->patchJson($this->api("kaarigars/{$this->ramesh->id}"), ['max_fine_balance' => '20'])->assertOk();

        $this->as()->postJson($this->api("work-orders/{$wo['id']}/issue"), [
            'step_id' => $step, 'kaarigar_id' => $this->ramesh->id, 'items' => [['karat_id' => $this->k22, 'gross' => '30']],   // 27.48 fine
        ])->assertUnprocessable();
        $this->as()->postJson($this->api("work-orders/{$wo['id']}/issue"), [
            'step_id' => $step, 'kaarigar_id' => $this->ramesh->id, 'items' => [['karat_id' => $this->k22, 'gross' => '20']],
        ])->assertCreated();

        $this->as()->getJson($this->api("kaarigars/{$this->ramesh->id}"))->assertOk()
            ->assertJsonPath('profile.limit', '20.000')->assertJsonPath('profile.gold_held.fine', '18.320');
    }

    public function test_unapproved_difference_blocks_issue_and_work_order_completion(): void
    {
        [$order, $wo, $step] = $this->orderWithWo();
        $tx = $this->as()->postJson($this->api("work-orders/{$wo['id']}/issue"), [
            'step_id' => $step, 'kaarigar_id' => $this->ramesh->id, 'items' => [['karat_id' => $this->k22, 'gross' => '50']],
        ])->json('data');
        // 10 g gross missing: far above any allowed wastage.
        $this->as()->postJson($this->api('metal/receive'), ['job_card_id' => $tx['job_card']['id'], 'destination' => 'wo', 'work' => ['gross' => '40'], 'close' => true])->assertCreated();
        $this->assertTrue(DB::table('app_notifications')->where('event', 'gold.mismatch')->exists());

        $this->as()->getJson($this->api("metal/issue-check?kaarigar_id={$this->ramesh->id}"))->assertJsonCount(1, 'blockers');

        $this->as()->postJson($this->api("work-orders/{$wo['id']}/finish"), ['pieces' => [['gross' => '19'], ['gross' => '19']]])->assertCreated();
        $this->as()->postJson($this->api("work-orders/{$wo['id']}/complete"))->assertUnprocessable();

        $this->as()->postJson($this->api("job-cards/{$tx['job_card']['id']}/approve-loss"), ['reason_code' => 'polishing_loss', 'note' => 'Checked, polishing dust lost'])->assertSuccessful();
        $this->as()->postJson($this->api("work-orders/{$wo['id']}/complete"))->assertOk();
        $this->as()->getJson($this->api("orders/{$order['id']}"))->assertJsonPath('data.status', 'under_qc');
    }

    public function test_qc_fail_records_defects_and_sends_pieces_to_rework(): void
    {
        [$order, $wo, $step] = $this->orderWithWo();
        $tx = $this->as()->postJson($this->api("work-orders/{$wo['id']}/issue"), [
            'step_id' => $step, 'kaarigar_id' => $this->ramesh->id, 'items' => [['karat_id' => $this->k22, 'gross' => '50']],
        ])->json('data');
        $this->as()->postJson($this->api('metal/receive'), ['job_card_id' => $tx['job_card']['id'], 'destination' => 'wo', 'work' => ['gross' => '49.9'], 'close' => true])->assertCreated();
        $items = $this->as()->postJson($this->api("work-orders/{$wo['id']}/finish"), ['pieces' => [['gross' => '24.8'], ['gross' => '24.9']]])->json('data');
        // Blueprint rule 4: even a difference within the allowed % is approved before the WO closes.
        $this->as()->postJson($this->api("work-orders/{$wo['id']}/complete"))->assertUnprocessable();
        $this->as()->postJson($this->api("job-cards/{$tx['job_card']['id']}/approve-loss"), ['reason_code' => 'process_loss', 'note' => 'Normal'])->assertOk();
        $this->as()->postJson($this->api("work-orders/{$wo['id']}/complete"))->assertOk();

        $polish = $this->asTenant($this->tenant, fn () => Process::where('name', 'Polishing')->value('id'));
        $this->as()->postJson($this->api('finished-items/qc'), ['item_ids' => [$items[1]['id']], 'result' => 'failed'])->assertUnprocessable();   // defects needed

        $res = $this->as()->postJson($this->api('finished-items/qc'), [
            'item_ids' => [$items[1]['id']], 'result' => 'failed', 'defects' => 'Stone loose', 'checklist' => ['stone_setting' => false],
            'rework_process_ids' => [$polish],
        ])->assertOk()->json();
        $this->assertNotNull($res['rework']['wo_no']);
        $this->assertSame('Stone loose', $this->asTenant($this->tenant, fn () => FinishedItem::find($items[1]['id'])->qc_defects));
        $this->as()->getJson($this->api("orders/{$order['id']}"))->assertJsonPath('data.status', 'rework_required');

        $this->as()->getJson($this->api("finished-items/{$items[1]['id']}/history"))->assertOk()->assertJsonPath('history.0.event', 'Finished');
    }

    public function test_portal_wishlist_moq_query_statement_and_notifications(): void
    {
        [$retailer, $shop, $design] = $this->asTenant($this->tenant, function () {
            $d = Design::create(['code' => 'NK-9', 'name' => 'Necklace', 'is_published' => true, 'approx_weight_min' => '30', 'approx_weight_max' => '34',
                'min_order_qty' => 5, 'stone_details' => 'Kundan', 'availability' => 'made_to_order']);
            $d->karats()->sync([$this->k22]);
            $r = Retailer::create(['shop_name' => 'Gupta', 'status' => 'active']);

            return [$r, $r->users()->create(['name' => 'G', 'email' => 'g@gupta.test', 'password' => 'password', 'phone' => '9876500099']), $d];
        });
        $portal = fn (string $p) => $this->on($this->tenant, "api/portal/v1/{$p}");
        $as = fn () => $this->actingAs($shop, 'retailer');

        $as()->postJson($portal("wishlist/{$design->id}"))->assertOk();
        $as()->getJson($portal('designs?wishlist=1'))->assertJsonPath('total', 1)->assertJsonPath('data.0.wishlisted', true)
            ->assertJsonPath('data.0.min_order_qty', 5)->assertJsonPath('data.0.stone_details', 'Kundan');

        $as()->postJson($portal('cart'), ['design_id' => $design->id, 'karat_id' => $this->k22, 'qty' => 2])->assertCreated();
        $as()->postJson($portal('orders'), [])->assertUnprocessable();   // below MOQ of 5
        $as()->postJson($portal('cart'), ['design_id' => $design->id, 'karat_id' => $this->k22, 'qty' => 3])->assertCreated();
        $order = $as()->postJson($portal('orders'), [])->assertCreated()->json('data');
        $this->assertSame('new', $order['status']);

        // Customisation + reference photo before approval.
        $line = $as()->getJson($portal("orders/{$order['id']}"))->json('data.lines.0.id');
        $as()->post($portal("orders/{$order['id']}/lines/{$line}"), ['customization' => 'Name engraved: Priya',
            'reference' => UploadedFile::fake()->image('ref.jpg', 400, 400)])->assertOk();
        $as()->getJson($portal("orders/{$order['id']}"))->assertJsonPath('data.lines.0.customization', 'Name engraved: Priya')
            ->assertJsonPath('data.editable', true)->assertJsonPath('data.payments.status', 'unpaid');

        // Staff: new → pending approval → approved; the retailer can no longer edit.
        $this->as()->postJson($this->api("orders/{$order['id']}/submit"))->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $this->as()->postJson($this->api("orders/{$order['id']}/approve"))->assertOk();
        $as()->postJson($portal("orders/{$order['id']}/lines/{$line}"), ['customization' => 'x'])->assertUnprocessable();
        $as()->getJson($portal("orders/{$order['id']}"))->assertJsonPath('data.stage', 'approved')->assertJsonPath('data.expected_completion', null);

        // Query thread.
        $q = $as()->postJson($portal('queries'), ['type' => 'customization', 'subject' => 'Change size', 'body' => 'Can we do 2.8?', 'order_id' => $order['id']])->assertCreated()->json('data');
        $this->as()->postJson($this->api("queries/{$q['id']}/reply"), ['body' => 'Yes, done.'])->assertOk()->assertJsonPath('data.status', 'answered');
        $as()->getJson($portal("queries/{$q['id']}"))->assertJsonCount(2, 'data.messages');

        // Bell + statement.
        $as()->getJson($portal('notifications'))->assertOk()->assertJsonPath('data.0.event', 'query.answered');
        $as()->postJson($portal('notifications/read'))->assertJsonPath('unread', 0);
        $as()->get($portal('statement.pdf'))->assertOk()->assertHeader('content-type', 'application/pdf');

        // CRM sees the retailer's history and logs a call.
        $this->as()->postJson($this->api("crm/retailers/{$retailer->id}/notes"), ['channel' => 'call', 'body' => 'Discussed Diwali stock', 'follow_up_on' => today()->toDateString()])->assertCreated();
        $this->as()->getJson($this->api("crm/retailers/{$retailer->id}"))->assertOk()->assertJsonPath('stats.orders', 1)->assertJsonCount(1, 'notes');
        $this->as()->getJson($this->api('crm/follow-ups'))->assertJsonCount(1, 'data');
    }

    public function test_dashboard_reports_exports_and_audit(): void
    {
        $this->orderWithWo();

        $this->as()->getJson($this->api('dashboard'))->assertOk()->assertJsonPath('orders.total', 1)
            ->assertJsonPath('orders.in_production', 1)->assertJsonPath('gold.vault', '916.000')->assertJsonCount(12, 'charts.months');

        // A dispatch manager sees no money or gold.
        $this->as($this->staffWithRole($this->tenant, 'dispatch_manager'))->getJson($this->api('dashboard'))->assertOk()
            ->assertJsonPath('money', null)->assertJsonPath('gold', null);

        $groups = $this->as()->getJson($this->api('reports'))->assertOk()->json('data');
        foreach (collect($groups)->flatMap(fn ($g) => $g['reports'])->pluck('name') as $name) {
            $this->as()->getJson($this->api("reports/{$name}"))->assertOk()->assertJsonStructure(['title', 'columns', 'rows']);
        }
        $this->as()->get($this->api('reports/gold-stock?format=xlsx'))->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->as()->get($this->api('reports/production-by-kaarigar?format=pdf'))->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->as()->getJson($this->api('audit?subject=Order'))->assertOk()->assertJsonPath('data.0.subject', 'Order');
        $this->as($this->staffWithRole($this->tenant, 'sales_manager'))->getJson($this->api('audit'))->assertForbidden();
    }
}
