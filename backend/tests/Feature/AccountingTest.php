<?php

namespace Tests\Feature;

use App\Models\Design;
use App\Models\Dispatch;
use App\Models\FinancialYear;
use App\Models\GoldRate;
use App\Models\Kaarigar;
use App\Models\Karat;
use App\Models\LabourRate;
use App\Models\MakingSlab;
use App\Models\Party;
use App\Models\Process;
use App\Models\Retailer;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Services\Accounting\Books;
use App\Services\Accounting\Invoicing;
use App\Services\Accounting\Reports;
use App\Services\Accounting\Vouchers;
use App\Services\Metal\Ledger;
use App\Services\Metal\LedgerException;
use App\Services\Metal\MetalOperations;
use App\Services\Production\Dispatching;
use App\Services\Production\Production;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('acme');
        $this->tenant->update(['state_code' => '27']);   // Maharashtra
    }

    private function t(callable $fn): mixed
    {
        return $this->asTenant($this->tenant->fresh(), $fn);
    }

    /** Make a dispatch of two 22K pieces (net 10.000 g and 12.000 g) to the retailer. */
    private function dispatchTo(Retailer $retailer, string $stone = '0'): Dispatch
    {
        $ops = app(MetalOperations::class);
        $prod = app(Production::class);
        $ledger = app(Ledger::class);
        $k22 = Karat::where('label', '22K')->first();
        $ops->purchase([['karat_id' => $k22->id, 'gross' => '100']], []);
        $design = Design::create(['code' => 'R1', 'name' => 'Ring', 'making_charge' => '500', 'making_type' => 'per_gram']);
        $wo = $prod->newWorkOrder($design, $k22->id, 2);
        $ledger->post('transfer', ['work_order_id' => $wo->id], [['from' => $ledger->vault(), 'to' => $ledger->workOrder($wo), 'karat' => $k22, 'gross' => '30']]);
        $items = $prod->finish($wo, [['gross' => '10', 'stone' => $stone], ['gross' => '12']]);
        foreach ($items as $i) {
            $i->forceFill(['qc_status' => 'passed'])->save();
        }

        return app(Dispatching::class)->dispatch($retailer, collect($items)->pluck('id')->all(), null);
    }

    public function test_cash_terms_invoice_with_cgst_sgst(): void
    {
        $this->t(function () {
            $k22 = Karat::where('label', '22K')->first();
            GoldRate::create(['date' => today()->toDateString(), 'karat_id' => $k22->id, 'rate_per_g' => '7200']);
            $retailer = Retailer::create(['shop_name' => 'Sharma', 'status' => 'active', 'gstin' => '27ABCDE1234F1Z5', 'state_code' => '27']);
            $dispatch = $this->dispatchTo($retailer);

            $preview = app(Invoicing::class)->preview($dispatch);
            // metal 22 g × 7200 = 158400; making from the default slab (rate 0) falls back to design ₹500/g = 11000.
            $this->assertSame('158400.00', $preview['totals']['metal_value']);
            $this->assertSame('11000.00', $preview['totals']['making']);
            $this->assertSame('169400.00', $preview['totals']['taxable']);
            $this->assertSame('2541.00', $preview['totals']['cgst']);   // 1.5 %, per line (1155.00 + 1386.00)
            $this->assertSame('2541.00', $preview['totals']['sgst']);
            $this->assertSame('0.00', $preview['totals']['igst']);
            $this->assertFalse($preview['inter_state']);

            $v = app(Invoicing::class)->post($dispatch);
            $this->assertStringStartsWith('INV/', $v->voucher_no);
            $this->assertSame($v->id, $dispatch->fresh()->invoice_voucher_id);

            $party = app(Books::class)->party($retailer);
            $this->assertSame('174482.00', app(Books::class)->partyBalance($party)['money']);

            $this->expectException(LedgerException::class);
            app(Invoicing::class)->post($dispatch->fresh());
        });
    }

    public function test_inter_state_igst_and_retailer_slab(): void
    {
        $this->t(function () {
            $k22 = Karat::where('label', '22K')->first();
            GoldRate::create(['date' => today()->toDateString(), 'karat_id' => $k22->id, 'rate_per_g' => '7000']);
            $slab = MakingSlab::create(['name' => 'Gold club', 'basis' => 'percent', 'rate' => '8']);
            $retailer = Retailer::create(['shop_name' => 'Gujarat Gold', 'status' => 'active', 'state_code' => '24', 'making_slab_id' => $slab->id]);

            $p = app(Invoicing::class)->preview($this->dispatchTo($retailer));
            $this->assertTrue($p['inter_state']);
            $this->assertSame('12320.00', $p['totals']['making']);       // 8 % of 154000
            $this->assertSame('4989.60', $p['totals']['igst']);          // 3 % of 166320
            $this->assertSame('0.00', $p['totals']['cgst']);
        });
    }

    public function test_metal_terms_settle_gold_from_the_deposit(): void
    {
        $this->t(function () {
            $k22 = Karat::where('label', '22K')->first();
            $k24 = Karat::where('label', '24K')->first();
            GoldRate::create(['date' => today()->toDateString(), 'karat_id' => $k22->id, 'rate_per_g' => '7200']);
            $retailer = Retailer::create(['shop_name' => 'Sharma', 'status' => 'active', 'metal_account_enabled' => true, 'state_code' => '27']);
            $books = app(Books::class);

            // Deposit 25 g of 24K (24.975 fine) → retailer's metal account credited automatically.
            app(MetalOperations::class)->deposit($retailer, [['karat_id' => $k24->id, 'gross' => '25']], []);
            $party = $books->party($retailer);
            $this->assertSame('-24.975', $books->partyBalance($party)['fine']);

            $v = app(Invoicing::class)->post($this->dispatchTo($retailer), ['terms' => 'metal']);
            // 22 g net × 91.6 % = 20.152 g fine billed against the deposit.
            $bal = $books->partyBalance($party);
            $this->assertSame('-4.823', $bal['fine']);
            // Money owed = making 11000 + GST on everything (3 % of 169400 = 5082) = 16082.
            $this->assertSame('16082.00', $bal['money']);
            $this->assertSame('metal', $v->meta['terms']);

            // Rate-cut the remaining metal we owe them: we now owe money instead.
            app(Vouchers::class)->rateCut($party, '4.823', '7850', today()->toDateString());
            $bal = $books->partyBalance($party);
            $this->assertSame('0.000', $bal['fine']);
            $this->assertSame('-21778.55', $bal['money']);   // 16082 − 4.823 g × 7850 (37860.55)

            try {
                app(Vouchers::class)->rateCut($party, '1', '7850', today()->toDateString());
                $this->fail('Nothing left to fix');
            } catch (LedgerException) {
            }
        });
    }

    public function test_labour_bill_on_close_and_excess_recovered_only_on_approval(): void
    {
        $this->t(function () {
            $ops = app(MetalOperations::class);
            $k22 = Karat::where('label', '22K')->first();
            $k24 = Karat::where('label', '24K')->first();
            GoldRate::create(['date' => today()->toDateString(), 'karat_id' => $k24->id, 'rate_per_g' => '7800']);
            $making = Process::where('name', 'Soldering')->first();
            $ramesh = Kaarigar::create(['name' => 'Ramesh']);
            LabourRate::create(['process_id' => $making->id, 'basis' => 'per_gram', 'rate' => '150']);

            $ops->purchase([['karat_id' => $k22->id, 'gross' => '200']], []);
            $card = $ops->openJobCard(['kaarigar_id' => $ramesh->id, 'process_id' => $making->id, 'karat_id' => $k22->id]);
            $ops->issue($card, [['karat_id' => $k22->id, 'gross' => '100', 'purity' => '100']], 'vault', []);
            $ops->receive($card, ['gross' => '98.4', 'purity' => '100'], null, 'finished', []);
            $card = $ops->closeJobCard($card);   // 1.6 % loss, 0.600 g over the 1 % limit

            $v = Voucher::find($card->labour_voucher_id);
            $this->assertSame('labour', $v->type);
            $this->assertSame('14760.00', $v->meta['labour']);       // 98.4 g × ₹150
            $this->assertSame('0.00', $v->meta['deduction']);        // nothing deducted on its own

            $books = app(Books::class);
            $ledger = app(Ledger::class);
            $this->assertSame('-14760.00', $books->partyBalance($books->party($ramesh))['money']);
            // The whole difference stays on him until someone approves it.
            $this->assertSame('pending', $card->loss_status);
            $this->assertSame('1.600', $ledger->balance($ledger->kaarigar($ramesh))['fine']);

            // Owner approves: the allowed part is written off, the excess recovered from labour.
            $card = $ops->approveLoss($card, 'polishing_loss', 'Fine filigree, checked', recoverExcess: true);
            $this->assertSame('recovered', $card->excess_resolution);
            $this->assertSame('approved', $card->loss_status);
            $this->assertSame('-10080.00', $books->partyBalance($books->party($ramesh))['money']);   // 14760 − 0.600 g × ₹7800
            $this->assertSame('0.000', $ledger->balance($ledger->kaarigar($ramesh))['fine']);

            // Weekly payment.
            app(Vouchers::class)->payment($books->party($ramesh), '10080', 'cash', today()->toDateString(), 'Weekly');
            $this->assertSame('0.00', $books->partyBalance($books->party($ramesh))['money']);
        });
    }

    public function test_books_balance_and_cancel_and_lock(): void
    {
        $this->t(function () {
            $books = app(Books::class);
            $vouchers = app(Vouchers::class);
            $supplier = Party::create(['type' => 'supplier', 'name' => 'Bullion Co']);

            $pb = $vouchers->purchase($supplier, today()->toDateString(), 'metal', '100', '2000', '3', false, 'Bullion on loan');
            $this->assertSame('-100.000', $books->partyBalance($supplier)['fine']);
            $this->assertSame('-2060.00', $books->partyBalance($supplier)['money']);

            $tb = app(Reports::class)->trialBalance(today()->toDateString());
            $this->assertTrue($tb['balanced']);
            $this->assertTrue(app(Reports::class)->balanceSheet(today()->toDateString())['balanced']);

            $books->cancel($pb->fresh('entries.account', 'entries.party'), 'Wrong supplier');
            $this->assertSame('0.000', $books->partyBalance($supplier)['fine']);
            $this->assertSame('cancelled', $pb->fresh()->status);

            FinancialYear::query()->update(['locked_until' => today()->toDateString()]);
            $this->expectException(LedgerException::class);
            $vouchers->receipt($supplier, '10', 'cash', today()->toDateString());
        });
    }

    public function test_unbalanced_journal_is_refused(): void
    {
        $this->t(function () {
            $books = app(Books::class);
            $this->expectException(LedgerException::class);
            $this->expectExceptionMessage('does not balance');
            $books->post('journal', today()->toDateString(), null, [
                ['account' => 'cash', 'debit' => '100'],
                ['account' => 'capital', 'credit' => '99'],
            ]);
        });
    }
}
