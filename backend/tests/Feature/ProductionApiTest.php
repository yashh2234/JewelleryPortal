<?php

namespace Tests\Feature;

use App\Models\Design;
use App\Models\Kaarigar;
use App\Models\Karat;
use App\Models\Order;
use App\Models\Retailer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('acme');
        $this->owner = $this->owner($this->tenant);
    }

    private function api(string $path): string
    {
        return $this->on($this->tenant, "api/v1/{$path}");
    }

    private function as(?User $user = null): static
    {
        return $this->actingAs($user ?? $this->owner, 'staff');
    }

    public function test_order_to_challan_over_http(): void
    {
        [$k22, $design, $retailer, $kaarigar] = $this->asTenant($this->tenant, fn () => [
            Karat::where('label', '22K')->value('id'),
            Design::create(['code' => 'KD-7', 'name' => 'Kada']),
            Retailer::create(['shop_name' => 'Sharma', 'status' => 'active']),
            Kaarigar::create(['name' => 'Ramesh']),
        ]);

        $this->as()->postJson($this->api('metal/purchase'), ['items' => [['karat_id' => $k22, 'gross' => '500']]])->assertCreated();

        $order = $this->as()->postJson($this->api('orders'), [
            'retailer_id' => $retailer->id, 'due_date' => today()->addDays(10)->toDateString(),
            'lines' => [['design_id' => $design->id, 'karat_id' => $k22, 'qty' => 2, 'size' => '2.6']],
        ])->assertCreated()->json('data');

        // Staff-entered orders skip "new": they are already reviewed.
        $this->assertSame('pending_approval', $order['status']);
        $this->as()->postJson($this->api("orders/{$order['id']}/approve"))->assertOk()->assertJsonPath('data.status', 'approved');

        // Advance against the order → receipt voucher, payment status "advance".
        $this->as()->postJson($this->api("orders/{$order['id']}/advance"), ['amount' => '5000', 'mode' => 'bank'])
            ->assertCreated()->assertJsonPath('payments.status', 'advance')->assertJsonPath('payments.paid', '5000.00');

        $wo = $this->as()->postJson($this->api("orders/{$order['id']}/work-orders"))->assertCreated()->json('data.0');
        $this->assertSame('work_order_created', $this->as()->getJson($this->api("orders/{$order['id']}"))->json('data.status'));

        $board = $this->as()->getJson($this->api('work-orders/board'))->assertOk();
        $melting = collect($board->json('columns'))->firstWhere('process.name', 'Melting');
        $this->assertSame($wo['wo_no'], $melting['cards'][0]['wo_no']);

        $detail = $this->as()->getJson($this->api("work-orders/{$wo['id']}"))->assertOk()->json('data');
        $stepId = $detail['steps'][0]['id'];

        $issue = $this->as()->postJson($this->api("work-orders/{$wo['id']}/issue"), [
            'step_id' => $stepId, 'kaarigar_id' => $kaarigar->id, 'items' => [['karat_id' => $k22, 'gross' => '50']],
        ])->assertCreated()->json('data');
        $this->assertSame('material_issued', $this->as()->getJson($this->api("orders/{$order['id']}"))->json('data.status'));

        $this->as()->postJson($this->api('metal/receive'), [
            'job_card_id' => $issue['job_card']['id'], 'destination' => 'wo', 'work' => ['gross' => '49.9'], 'close' => true,
        ])->assertCreated();
        $this->assertSame('in_production', $this->as()->getJson($this->api("orders/{$order['id']}"))->json('data.status'));

        $this->assertSame('45.708', $this->as()->getJson($this->api("work-orders/{$wo['id']}"))->json('data.gold_in_wo'));

        // Skip the remaining stages for this test and finish.
        foreach ($detail['steps'] as $s) {
            $this->as()->postJson($this->api("work-orders/{$wo['id']}/steps/{$s['id']}/advance"))->assertOk();
        }
        $items = $this->as()->postJson($this->api("work-orders/{$wo['id']}/finish"), [
            'pieces' => [['gross' => '24.8', 'size' => '2.6'], ['gross' => '24.9', 'size' => '2.6']],
        ])->assertCreated()->json('data');
        // The 0.1 g difference on the melting card is approved before the WO can close (blueprint rule 4).
        $this->as()->postJson($this->api("job-cards/{$issue['job_card']['id']}/approve-loss"), ['reason_code' => 'process_loss', 'note' => 'Melting'])->assertOk();
        $this->as()->postJson($this->api("work-orders/{$wo['id']}/complete"))->assertOk()->assertJsonPath('data.status', 'completed');
        $this->as()->getJson($this->api("orders/{$order['id']}"))->assertJsonPath('data.status', 'under_qc');

        $ids = array_column($items, 'id');
        // Passing needs the whole checklist.
        $this->as()->postJson($this->api('finished-items/qc'), ['item_ids' => $ids, 'result' => 'passed', 'checklist' => ['stone_setting' => false]])->assertUnprocessable();
        $this->as()->postJson($this->api('finished-items/qc'), ['item_ids' => $ids, 'result' => 'passed'])->assertOk()->assertJsonPath('updated', 2);
        // QC passed → ready for dispatch (no hallmarking step).
        $this->as()->getJson($this->api("orders/{$order['id']}"))->assertJsonPath('data.status', 'ready_for_dispatch');
        $this->as()->getJson($this->api('hallmark-lots'))->assertNotFound();

        $dispatch = $this->as()->postJson($this->api('dispatches'), [
            'retailer_id' => $retailer->id, 'order_id' => $order['id'], 'item_ids' => $ids,
            'courier' => 'Delhivery', 'tracking_no' => 'DL123',
        ])->assertCreated()->json('data');
        $this->assertSame('https://www.delhivery.com/track/package/DL123', $dispatch['tracking_url']);

        $this->as()->getJson($this->api("orders/{$order['id']}"))->assertJsonPath('data.status', 'dispatched');
        $this->as()->get($this->api("dispatches/{$dispatch['id']}/challan"))->assertOk()->assertHeader('content-type', 'application/pdf');

        // Shipment moves to delivered → the order is delivered, then closed by hand.
        $this->as()->patchJson($this->api("dispatches/{$dispatch['id']}/shipment"), ['shipment_status' => 'in_transit'])->assertOk();
        $this->as()->patchJson($this->api("dispatches/{$dispatch['id']}/shipment"), ['shipment_status' => 'delivered'])->assertOk()
            ->assertJsonPath('data.shipment_status', 'delivered');
        $this->as()->getJson($this->api("orders/{$order['id']}"))->assertJsonPath('data.status', 'delivered');
        $this->as()->postJson($this->api("orders/{$order['id']}/close"))->assertOk()->assertJsonPath('data.status', 'closed');

        // The retailer heard about it in the app as well.
        $this->assertTrue(DB::table('app_notifications')->where('user_type', 'staff')->exists());
    }

    public function test_roles_split_production_and_gold(): void
    {
        $production = $this->staffWithRole($this->tenant, 'production_manager');
        $sales = $this->staffWithRole($this->tenant, 'sales');
        [$k22, $design] = $this->asTenant($this->tenant, fn () => [Karat::where('label', '22K')->value('id'), Design::create(['code' => 'X', 'name' => 'X'])]);

        $wo = $this->as($production)->postJson($this->api('work-orders'), ['design_id' => $design->id, 'karat_id' => $k22, 'qty' => 5])
            ->assertCreated()->json('data');

        $this->as($sales)->postJson($this->api('work-orders'), ['design_id' => $design->id, 'karat_id' => $k22, 'qty' => 5])->assertForbidden();

        // Order manager plans work orders but cannot issue gold.
        $orders = $this->staffWithRole($this->tenant, 'order_manager');
        $this->as($orders)->postJson($this->api("work-orders/{$wo['id']}/issue"), [])->assertForbidden();
        // Quality manager can't create work orders; dispatch manager can't approve orders.
        $this->as($this->staffWithRole($this->tenant, 'quality_manager'))->postJson($this->api('work-orders'), ['design_id' => $design->id, 'karat_id' => $k22, 'qty' => 1])->assertForbidden();
        $orderId = $this->asTenant($this->tenant, fn () => Order::create([
            'order_no' => 'ORD-T1', 'retailer_id' => Retailer::create(['shop_name' => 'R', 'status' => 'active'])->id, 'status' => 'pending_approval',
        ])->id);
        $this->as($this->staffWithRole($this->tenant, 'dispatch_manager'))->postJson($this->api("orders/{$orderId}/approve"))->assertForbidden();
        $this->as($orders)->postJson($this->api("orders/{$orderId}/approve"))->assertOk();
    }
}
