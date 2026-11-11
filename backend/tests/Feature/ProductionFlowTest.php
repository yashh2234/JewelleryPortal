<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Design;
use App\Models\FinishedItem;
use App\Models\Kaarigar;
use App\Models\Karat;
use App\Models\MetalLocation;
use App\Models\Order;
use App\Models\Retailer;
use App\Models\Tenant;
use App\Models\WorkOrder;
use App\Services\Metal\Ledger;
use App\Services\Metal\LedgerException;
use App\Services\Metal\MetalOperations;
use App\Services\Production\Dispatching;
use App\Services\Production\Production;
use App\Support\Metal\Weight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('acme');
    }

    public function test_order_to_dispatch(): void
    {
        $this->asTenant($this->tenant, function () {
            $ops = app(MetalOperations::class);
            $prod = app(Production::class);
            $ledger = app(Ledger::class);
            $k22 = Karat::where('label', '22K')->first();

            $ops->purchase([['karat_id' => $k22->id, 'gross' => '1000']], []);
            $design = Design::create(['code' => 'KD-1', 'name' => 'Classic kada', 'category_id' => Category::where('name', 'Kada')->value('id')]);
            $retailer = Retailer::create(['shop_name' => 'Sharma', 'status' => 'active']);
            $ramesh = Kaarigar::create(['name' => 'Ramesh']);
            $imran = Kaarigar::create(['name' => 'Imran']);

            // Order with two sizes of one design → batched into one WO.
            $order = $prod->createOrder($retailer, [
                ['design_id' => $design->id, 'karat_id' => $k22->id, 'size' => '2.4', 'qty' => 1, 'target_wt_min' => '18', 'target_wt_max' => '22'],
                ['design_id' => $design->id, 'karat_id' => $k22->id, 'size' => '2.6', 'qty' => 2],
            ], ['due_date' => today()->addWeek()->toDateString()]);
            $this->assertStringStartsWith('ORD/', $order->order_no);

            try {
                $prod->createWorkOrders($order);
                $this->fail('Unconfirmed orders cannot go to production');
            } catch (LedgerException) {
            }

            $prod->confirm($order);
            [$wo] = $prod->createWorkOrders($order, [$order->lines->pluck('id')->all()]);
            $this->assertSame(3, $wo->qty);
            $this->assertSame(['2.4' => 1, '2.6' => 2], $wo->sizes);
            $this->assertSame(Order::WORK_ORDER_CREATED, $order->fresh()->status);
            $steps = $wo->steps()->with('process')->get();
            $this->assertSame('Melting', $steps[0]->process->name);   // default route

            // Stage 1 from the vault; returns into the WO.
            $issue = $prod->issueForStep($steps[0], $ramesh->id, [['karat_id' => $k22->id, 'gross' => '70']]);   // 64.120 fine
            $this->assertSame('vault', $issue->lines[0]->from->type);
            $this->assertSame(Order::MATERIAL_ISSUED, $order->fresh()->status);
            $card = $issue->jobCard;
            $ops->receive($card, ['gross' => '69.9'], null, 'wo', []);
            $this->assertSame(Order::IN_PRODUCTION, $order->fresh()->status);
            $ops->closeJobCard($card);
            $prod->advance($steps[0]);
            $this->assertSame('64.028', $prod->goldInWo($wo)['fine']);

            // Stage 2 comes out of the WO's own gold.
            $issue2 = $prod->issueForStep($steps[1]->fresh(), $imran->id, [['karat_id' => $k22->id, 'gross' => '69.9']]);
            $this->assertSame('wo', $issue2->lines[0]->from->type);
            $ops->receive($issue2->jobCard, ['gross' => '69.5'], ['gross' => '0.2'], 'wo', []);
            $ops->closeJobCard($issue2->jobCard);

            // Three finished pieces with actual weights; the rest stays in the WO.
            $items = $prod->finish($wo->fresh(), [
                ['gross' => '22.10', 'size' => '2.4', 'order_line_id' => $order->lines[0]->id],
                ['gross' => '23.30', 'size' => '2.6', 'order_line_id' => $order->lines[1]->id],
                ['gross' => '23.80', 'size' => '2.6', 'order_line_id' => $order->lines[1]->id],
            ]);
            $this->assertCount(3, $items);
            $this->assertSame('20.244', $items[0]->fine_wt);

            // Blueprint rule 4: the work order closes only after every difference is approved.
            try {
                $prod->complete($wo->fresh());
                $this->fail('Differences must be approved first');
            } catch (LedgerException $e) {
                $this->assertStringContainsString('Approve the gold difference', $e->getMessage());
            }
            $ops->approveLoss($card->fresh(), 'process_loss', 'Melting loss');
            $ops->approveLoss($issue2->jobCard->fresh(), 'polishing_loss', 'Checked');

            $prod->complete($wo->fresh());
            $this->assertSame('0.000', $prod->goldInWo($wo)['fine']);        // leftover moved to scrap
            $this->assertSame(Order::UNDER_QC, $order->fresh()->status);   // pieces wait for QC

            // QC: two pass, one fails (finished goods enter stock only after QC approval).
            FinishedItem::whereKey([$items[0]->id, $items[1]->id])->update(['qc_status' => 'passed']);
            FinishedItem::whereKey($items[2]->id)->update(['qc_status' => 'failed', 'qc_defects' => 'Porous casting']);

            // A failed piece can't be dispatched; it goes to rework.
            $dispatching = app(Dispatching::class);
            try {
                $dispatching->dispatch($retailer, [$items[2]->id], $order->fresh());
                $this->fail('Failed piece must not dispatch');
            } catch (LedgerException) {
            }
            $rework = $prod->rework([$items[2]->id], [$steps[4]->process_id], 'QC failed');
            $this->assertSame('high', $rework->priority);
            $this->assertSame($items[2]->fresh()->fine_wt, $prod->goldInWo($rework)['fine']);

            $dispatch = $dispatching->dispatch($retailer, [$items[0]->id, $items[1]->id], $order->fresh(), ['remarks' => 'By courier']);
            $this->assertStringStartsWith('DC/', $dispatch->challan_no);
            $this->assertSame(Order::PARTIALLY_DISPATCHED, $order->fresh()->status);
            $this->assertSame(FinishedItem::DISPATCHED, $items[0]->fresh()->status);

            // Books still balance across every location.
            $sum = Weight::add('0', ...array_column($ledger->balanceRows(), 'fine'));
            $this->assertSame('0.000', $sum);
            $this->assertSame('0.000', $ledger->balance($ledger->kaarigar($ramesh))['fine']);
        });
    }

    public function test_qc_passed_piece_dispatches_without_hallmarking(): void
    {
        $this->asTenant($this->tenant, function () {
            $ops = app(MetalOperations::class);
            $prod = app(Production::class);
            $k22 = Karat::where('label', '22K')->first();
            $ops->purchase([['karat_id' => $k22->id, 'gross' => '100']], []);
            $design = Design::create(['code' => 'R-1', 'name' => 'Ring']);

            $wo = $prod->newWorkOrder($design, $k22->id, 1);
            app(Ledger::class)->post('transfer', ['work_order_id' => $wo->id], [[
                'from' => app(Ledger::class)->vault(), 'to' => app(Ledger::class)->workOrder($wo), 'karat' => $k22, 'gross' => '5',
            ]]);
            [$item] = $prod->finish($wo, [['gross' => '5']]);

            $retailer = Retailer::create(['shop_name' => 'Sharma', 'status' => 'active']);

            try {
                app(Dispatching::class)->dispatch($retailer, [$item->id], null);
                $this->fail('QC must pass first');
            } catch (LedgerException $e) {
                $this->assertStringContainsString('QC-passed', $e->getMessage());
            }

            $item->forceFill(['qc_status' => 'passed'])->save();
            app(Dispatching::class)->dispatch($retailer, [$item->id], null);
            $this->assertSame(WorkOrder::OPEN, $wo->fresh()->status);
            $this->assertSame('0.000', app(Ledger::class)->balance(app(Ledger::class)->of(MetalLocation::FINISHED))['fine']);
        });
    }
}
