<?php

namespace Tests\Feature;

use App\Models\JobCard;
use App\Models\Kaarigar;
use App\Models\Karat;
use App\Models\MetalClosing;
use App\Models\MetalLocation;
use App\Models\MetalTransaction;
use App\Models\Process;
use App\Models\Retailer;
use App\Models\Tenant;
use App\Services\Metal\Ledger;
use App\Services\Metal\LedgerException;
use App\Services\Metal\MetalOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class GoldLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private MetalOperations $ops;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('acme');
        $this->ops = app(MetalOperations::class);
        $this->ledger = app(Ledger::class);
    }

    /** Run the test body as the tenant. */
    private function t(callable $fn): mixed
    {
        return $this->asTenant($this->tenant, $fn);
    }

    private function karat(string $label): Karat
    {
        return Karat::where('label', $label)->firstOrFail();
    }

    private function process(string $name): Process
    {
        return Process::where('name', $name)->firstOrFail();
    }

    private function card(Kaarigar $k, string $process = 'Making', string $karat = '22K'): JobCard
    {
        return $this->ops->openJobCard(['kaarigar_id' => $k->id, 'process_id' => $this->process($process)->id, 'karat_id' => $this->karat($karat)->id]);
    }

    /** 1 kg of 22K in the vault = 916.000 g fine. */
    private function stockVault(string $gross = '1000', string $karat = '22K'): void
    {
        $this->ops->purchase([['karat_id' => $this->karat($karat)->id, 'gross' => $gross]], ['party_name' => 'Bullion dealer']);
    }

    private function fine(MetalLocation $loc): string
    {
        return $this->ledger->balance($loc)['fine'];
    }

    public function test_purchase_puts_fine_gold_in_the_vault(): void
    {
        $this->t(function () {
            $tx = $this->ops->purchase([['karat_id' => $this->karat('22K')->id, 'gross' => '100.000']], ['reference' => 'Tunch 42']);

            $this->assertSame('PUR/'.\App\Support\FinancialYear::label().'/0001', $tx->voucher_no);
            $this->assertSame('91.600', $tx->lines[0]->fine_wt);
            $this->assertSame('91.600', $this->fine($this->ledger->vault()));
            // Double entry: the outside world went negative by the same amount.
            $this->assertSame('-91.600', $this->fine($this->ledger->of(MetalLocation::OUTSIDE)));
        });
    }

    public function test_reel_example_wastage_within_and_above_limit(): void
    {
        $this->t(function () {
            $this->stockVault();
            $ramesh = Kaarigar::create(['name' => 'Ramesh']);
            $imran = Kaarigar::create(['name' => 'Imran']);
            $suresh = Kaarigar::create(['name' => 'Suresh']);
            $k22 = $this->karat('22K')->id;

            // Ramesh: issued 100 g fine (24K-equivalent figures kept simple with purity 100).
            $r = $this->card($ramesh);
            $this->ops->issue($r, [['karat_id' => $k22, 'gross' => '100', 'purity' => '100']], 'vault', []);
            $this->ops->receive($r, ['gross' => '98.4', 'purity' => '100'], null, 'finished', []);
            $r = $this->ops->closeJobCard($r);

            // Making allows 1.000 %: 1.000 g written off, 0.600 g stays on Ramesh and is flagged.
            $this->assertSame('1.600', $r->loss_fine);
            $this->assertSame('1.000', $r->allowed_loss_fine);
            $this->assertSame('0.600', $r->excess_loss_fine);
            $this->assertSame('0.600', $this->fine($this->ledger->kaarigar($ramesh)));
            $this->assertTrue(MetalTransaction::where('type', 'loss_writeoff')->where('job_card_id', $r->id)->value('over_limit'));

            // Imran: issued 80 g, returns 79.1 g + 0.3 g scrap → 0.6 g loss = 0.75 %, all within limit.
            $i = $this->card($imran);
            $this->ops->issue($i, [['karat_id' => $k22, 'gross' => '80', 'purity' => '100']], 'vault', []);
            $this->ops->receive($i, ['gross' => '79.1', 'purity' => '100'], ['gross' => '0.3', 'purity' => '100'], 'finished', []);
            $i = $this->ops->closeJobCard($i);
            $this->assertSame('0.600', $i->loss_fine);
            $this->assertSame('0.000', $i->excess_loss_fine);
            $this->assertSame('0.000', $this->fine($this->ledger->kaarigar($imran)));
            $this->assertSame('0.300', $this->fine($this->ledger->of(MetalLocation::SCRAP)));

            // Suresh still holds 60 g on the bench.
            $s = $this->card($suresh);
            $this->ops->issue($s, [['karat_id' => $k22, 'gross' => '60', 'purity' => '100']], 'vault', []);
            $this->assertSame('60.000', $this->fine($this->ledger->kaarigar($suresh)));

            // Owner approves Ramesh's excess.
            $this->ops->writeOffExcess($r, 'Fine filing design');
            $this->assertSame('0.000', $this->fine($this->ledger->kaarigar($ramesh)));
            $this->assertSame('written_off', $r->fresh()->excess_resolution);

            // The books always balance: sum over every location is zero.
            $total = '0.000';
            foreach ($this->ledger->balanceRows() as $row) {
                $total = \App\Support\Metal\Weight::add($total, $row['fine']);
            }
            $this->assertSame('0.000', $total);
        });
    }

    public function test_cannot_issue_more_than_the_vault_holds(): void
    {
        $this->t(function () {
            $this->stockVault('10');                 // 9.160 g fine
            $card = $this->card(Kaarigar::create(['name' => 'A']));

            $this->expectException(LedgerException::class);
            $this->expectExceptionMessage('Vault has only 9.160 g fine 22K gold');
            $this->ops->issue($card, [['karat_id' => $this->karat('22K')->id, 'gross' => '11']], 'vault', []);
        });
    }

    public function test_vault_is_checked_per_karat(): void
    {
        $this->t(function () {
            $this->stockVault('100', '24K');
            $card = $this->card(Kaarigar::create(['name' => 'A']));

            $this->expectException(LedgerException::class);
            $this->ops->issue($card, [['karat_id' => $this->karat('22K')->id, 'gross' => '1']], 'vault', []);
        });
    }

    public function test_cannot_receive_more_than_outstanding_and_partial_returns_work(): void
    {
        $this->t(function () {
            $this->stockVault();
            $card = $this->card(Kaarigar::create(['name' => 'A']));
            $this->ops->issue($card, [['karat_id' => $this->karat('22K')->id, 'gross' => '50']], 'vault', []);  // 45.800 fine

            $this->ops->receive($card, ['gross' => '20'], null, 'finished', []);
            $this->ops->receive($card, ['gross' => '29.5', 'pieces' => 3], null, 'finished', []);   // partial returns

            $this->expectException(LedgerException::class);
            $this->ops->receive($card, ['gross' => '1'], null, 'finished', []);   // only 0.458 fine left
        });
    }

    public function test_stones_and_findings_are_not_gold(): void
    {
        $this->t(function () {
            $this->stockVault();
            $card = $this->card(Kaarigar::create(['name' => 'A']), 'Stone setting');
            $this->ops->issue($card, [['karat_id' => $this->karat('22K')->id, 'gross' => '10']], 'vault', []);

            $tx = $this->ops->receive($card, ['gross' => '12.000', 'stone' => '2.000', 'findings' => '0.050'], null, 'finished', []);

            $this->assertSame('9.950', $tx->lines[0]->net_wt);
            $this->assertSame('9.114', $tx->lines[0]->fine_wt);   // 9.95 × 91.6 % = 9.1142
        });
    }

    public function test_reversal_instead_of_edit(): void
    {
        $this->t(function () {
            $tx = $this->ops->purchase([['karat_id' => $this->karat('22K')->id, 'gross' => '100']], []);

            $rev = $this->ledger->reverse($tx, 'Typed 100 instead of 10');

            $this->assertSame('0.000', $this->fine($this->ledger->vault()));
            $this->assertSame($rev->id, $tx->fresh()->reversed_by_id);
            $this->assertSame($tx->id, $rev->reverses_id);

            $this->expectException(LedgerException::class);
            $this->ledger->reverse($tx->fresh(), 'again');
        });
    }

    public function test_ledger_rows_are_append_only(): void
    {
        $this->t(function () {
            $tx = $this->ops->purchase([['karat_id' => $this->karat('22K')->id, 'gross' => '100']], []);

            try {
                $tx->update(['remarks' => 'edited']);
                $this->fail('Update should be refused');
            } catch (LogicException) {
            }

            $this->expectException(LogicException::class);
            $tx->lines[0]->delete();
        });
    }

    public function test_melting_loss_and_alloy(): void
    {
        $this->t(function () {
            $this->stockVault('100', '24K');   // 99.900 fine

            // 100 g 24K + 9 g alloy → 108.8 g 22K bar (99.661 fine); 0.239 g melting loss.
            $tx = $this->ops->melt(
                [['karat_id' => $this->karat('24K')->id, 'gross' => '100']],
                '9',
                ['karat_id' => $this->karat('22K')->id, 'gross' => '108.8'],
                $this->process('Melting'),
                [],
            );

            $this->assertSame('99.661', $this->ledger->balance($this->ledger->vault(), $this->karat('22K')->id)['fine']);
            $this->assertSame('0.000', $this->ledger->balance($this->ledger->vault(), $this->karat('24K')->id)['fine']);
            $this->assertSame('0.239', $this->fine($this->ledger->of(MetalLocation::LOSS)));
            $this->assertSame('0.000', $this->fine($this->ledger->of(MetalLocation::MELT)));
            $this->assertTrue($tx->over_limit);   // 0.239 % > Melting's 0.200 %
        });
    }

    public function test_refining_scrap(): void
    {
        $this->t(function () {
            $this->stockVault();
            $card = $this->card(Kaarigar::create(['name' => 'A']));
            $this->ops->issue($card, [['karat_id' => $this->karat('22K')->id, 'gross' => '20']], 'vault', []);
            $this->ops->receive($card, null, ['gross' => '5'], 'vault', []);   // 4.580 fine scrap

            $this->ops->refine(
                [['karat_id' => $this->karat('22K')->id, 'gross' => '5']],
                ['karat_id' => $this->karat('24K')->id, 'gross' => '4.5', 'purity' => '99.9'],   // 4.496 fine back
                ['party_name' => 'Refiner'],
            );

            $this->assertSame('0.000', $this->fine($this->ledger->of(MetalLocation::SCRAP)));
            $this->assertSame('0.084', $this->fine($this->ledger->of(MetalLocation::LOSS)));
            $this->assertSame('4.496', $this->ledger->balance($this->ledger->vault(), $this->karat('24K')->id)['fine']);
        });
    }

    public function test_retailer_deposit_needs_metal_account(): void
    {
        $this->t(function () {
            $retailer = Retailer::create(['shop_name' => 'Sharma', 'status' => 'active']);

            try {
                $this->ops->deposit($retailer, [['karat_id' => $this->karat('24K')->id, 'gross' => '10']], []);
                $this->fail('Should need a metal account');
            } catch (LedgerException) {
            }

            $retailer->update(['metal_account_enabled' => true]);
            $tx = $this->ops->deposit($retailer, [['karat_id' => $this->karat('24K')->id, 'gross' => '10']], []);

            // Gold sits in the vault; the retailer is owed it back (tracked on the party).
            $this->assertSame('9.990', $this->fine($this->ledger->vault()));
            $this->assertSame($retailer->id, $tx->retailer_id);
        });
    }

    public function test_closed_day_is_locked(): void
    {
        $this->t(function () {
            MetalClosing::create(['date' => today()->toDateString(), 'system_vault_fine' => '0']);

            $this->expectException(LedgerException::class);
            $this->expectExceptionMessage('The books are closed');
            $this->stockVault();
        });
    }

    public function test_job_card_with_more_returned_than_issued_cannot_close(): void
    {
        $this->t(function () {
            $this->stockVault();
            $card = $this->card(Kaarigar::create(['name' => 'A']));
            $this->ops->issue($card, [['karat_id' => $this->karat('22K')->id, 'gross' => '10']], 'vault', []);
            $this->ops->receive($card, ['gross' => '10'], null, 'finished', []);

            $card = $this->ops->closeJobCard($card);
            $this->assertSame('0.000', $card->loss_fine);
            $this->assertSame(JobCard::CLOSED, $card->status);

            $this->expectException(LedgerException::class);
            $this->ops->issue($card, [['karat_id' => $this->karat('22K')->id, 'gross' => '1']], 'vault', []);
        });
    }
}
