<?php

namespace Tests\Feature;

use App\Models\Design;
use App\Models\Kaarigar;
use App\Models\Karat;
use App\Models\Retailer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->as()->postJson($this->api("orders/{$order['id']}/confirm"))->assertOk()->assertJsonPath('data.status', 'confirmed');
        $wo = $this->as()->postJson($this->api("orders/{$order['id']}/work-orders"))->assertCreated()->json('data.0');

        $board = $this->as()->getJson($this->api('work-orders/board'))->assertOk();
        $melting = collect($board->json('columns'))->firstWhere('process.name', 'Melting');
        $this->assertSame($wo['wo_no'], $melting['cards'][0]['wo_no']);

        $detail = $this->as()->getJson($this->api("work-orders/{$wo['id']}"))->assertOk()->json('data');
        $stepId = $detail['steps'][0]['id'];

        $issue = $this->as()->postJson($this->api("work-orders/{$wo['id']}/issue"), [
            'step_id' => $stepId, 'kaarigar_id' => $kaarigar->id, 'items' => [['karat_id' => $k22, 'gross' => '50']],
        ])->assertCreated()->json('data');

        $this->as()->postJson($this->api('metal/receive'), [
            'job_card_id' => $issue['job_card']['id'], 'destination' => 'wo', 'work' => ['gross' => '49.9'], 'close' => true,
        ])->assertCreated();

        $this->assertSame('45.708', $this->as()->getJson($this->api("work-orders/{$wo['id']}"))->json('data.gold_in_wo'));

        // Skip the remaining stages for this test and finish.
        foreach ($detail['steps'] as $s) {
            $this->as()->postJson($this->api("work-orders/{$wo['id']}/steps/{$s['id']}/advance"))->assertOk();
        }
        $items = $this->as()->postJson($this->api("work-orders/{$wo['id']}/finish"), [
            'pieces' => [['gross' => '24.8', 'size' => '2.6'], ['gross' => '24.9', 'size' => '2.6']],
        ])->assertCreated()->json('data');
        $this->as()->postJson($this->api("work-orders/{$wo['id']}/complete"))->assertOk()->assertJsonPath('data.status', 'completed');
        $this->as()->getJson($this->api("orders/{$order['id']}"))->assertJsonPath('data.status', 'ready');

        $ids = array_column($items, 'id');
        $this->as()->postJson($this->api('finished-items/qc'), ['item_ids' => $ids, 'result' => 'passed'])->assertOk()->assertJsonPath('updated', 2);

        $ahc = $this->as()->postJson($this->api('ahcs'), ['name' => 'Pune AHC', 'charge_per_piece' => '45'])->assertCreated()->json('data');
        $lot = $this->as()->postJson($this->api('hallmark-lots'), ['ahc_id' => $ahc['id'], 'karat_id' => $k22, 'item_ids' => $ids])->assertCreated()->json('data');
        $this->as()->postJson($this->api("hallmark-lots/{$lot['id']}/send"), ['manak_request_no' => 'MR-991'])->assertOk();
        $this->as()->get($this->api("hallmark-lots/{$lot['id']}/sheet"))->assertOk()->assertHeader('content-type', 'application/pdf');

        $csv = "tag,huid\n{$items[0]['tag_no']},AB12CD\n{$items[1]['tag_no']},XY34ZW";
        $parsed = $this->as()->postJson($this->api("hallmark-lots/{$lot['id']}/huids/import"), ['text' => $csv])->assertOk()->json('data');
        $this->assertSame('XY34ZW', $parsed[1]['huid']);

        $this->as()->postJson($this->api("hallmark-lots/{$lot['id']}/return"), ['results' => $parsed])
            ->assertOk()->assertJsonPath('data.status', 'returned');

        $this->as()->getJson($this->api('finished-items?search=AB12CD'))->assertJsonPath('total', 1);

        $dispatch = $this->as()->postJson($this->api('dispatches'), [
            'retailer_id' => $retailer->id, 'order_id' => $order['id'], 'item_ids' => $ids,
        ])->assertCreated()->json('data');

        $this->as()->getJson($this->api("orders/{$order['id']}"))->assertJsonPath('data.status', 'dispatched');
        $this->as()->get($this->api("dispatches/{$dispatch['id']}/challan"))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_roles_split_production_and_gold(): void
    {
        $production = $this->staffWithRole($this->tenant, 'production_manager');
        $sales = $this->staffWithRole($this->tenant, 'sales');
        [$k22, $design] = $this->asTenant($this->tenant, fn () => [Karat::where('label', '22K')->value('id'), Design::create(['code' => 'X', 'name' => 'X'])]);

        $wo = $this->as($production)->postJson($this->api('work-orders'), ['design_id' => $design->id, 'karat_id' => $k22, 'qty' => 5])
            ->assertCreated()->json('data');

        $this->as($sales)->postJson($this->api('work-orders'), ['design_id' => $design->id, 'karat_id' => $k22, 'qty' => 5])->assertForbidden();

        // Production manager moves stages but cannot issue gold.
        $this->as($production)->postJson($this->api("work-orders/{$wo['id']}/issue"), [])->assertForbidden();
    }
}
