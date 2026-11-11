<?php

namespace App\Services\Accounting;

use App\Http\Controllers\Staff\GoldRateController;
use App\Models\JobCard;
use App\Models\Karat;
use App\Models\LabourRate;
use App\Models\MetalLocation;
use App\Models\MetalTransaction;
use App\Models\MetalTransactionLine;
use App\Models\Voucher;
use App\Services\Metal\Ledger;
use App\Services\Metal\LedgerException;
use App\Support\Metal\Weight;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;

/**
 * Kaarigar labour bill, posted automatically when a job card closes:
 *   labour = rate × returned work gross (per gram) or × pieces (per piece).
 * Wastage above the limit is never deducted on its own: when the owner approves
 * the difference he may choose to recover the excess in money (recoverExcess).
 */
class Labour
{
    public function __construct(private Books $books, private Ledger $ledger, private TenantContext $context) {}

    public function billFor(JobCard $card): ?Voucher
    {
        if ($card->isOpen() || $card->labour_voucher_id) {
            return null;
        }

        $rate = LabourRate::for($card->process_id, $card->kaarigar_id);
        if (! $rate) {
            return null;
        }

        $work = $this->returnedWork($card);
        $qty = $rate->basis === 'per_piece' ? (string) ($work['pieces'] ?: ($card->pieces ?? 0)) : $work['gross'];
        $labour = Money::mul($qty, $rate->rate);
        $basisLabel = $rate->basis === 'per_piece' ? "{$qty} pcs × ₹{$rate->rate}" : "{$qty} g × ₹{$rate->rate}/g";

        if (Money::isZero($labour)) {
            return null;
        }

        $party = $this->books->party($card->kaarigar);
        $voucher = $this->books->post(Voucher::LABOUR, today()->toDateString(), $party, [
            ['account' => 'labour', 'debit' => $labour, 'memo' => $basisLabel],
            ['party' => $party, 'credit' => $labour],
        ], [
            'narration' => "Labour for {$card->card_no} ({$card->process->name})",
            'source_type' => 'job_card',
            'source_id' => $card->id,
            'amount' => $labour,
            'meta' => ['labour' => $labour, 'deduction' => '0.00', 'basis' => $basisLabel, 'excess_fine' => '0.000'],
        ]);

        $card->forceFill(['labour_voucher_id' => $voucher->id])->save();

        return $voucher;
    }

    /**
     * Approver chose to recover the gold above the allowed wastage from the
     * kaarigar's money: deduct it at the day's fine rate and take the gold off his
     * metal balance as "recovered".
     */
    public function recoverExcess(JobCard $card): Voucher
    {
        $excess = Weight::round((string) $card->excess_loss_fine);
        $fineRate = $this->fineRate();
        if ($fineRate === null) {
            throw new LedgerException('Set today\'s gold rate before recovering wastage in money.');
        }

        $deduction = Money::mul($excess, $fineRate);
        $party = $this->books->party($card->kaarigar);

        $voucher = $this->books->post(Voucher::JOURNAL, today()->toDateString(), $party, [
            ['party' => $party, 'debit' => $deduction],
            ['account' => 'wastage_recovery', 'credit' => $deduction, 'memo' => "Excess wastage {$excess} g @ ₹{$fineRate}/g"],
        ], [
            'narration' => "Excess wastage on {$card->card_no} recovered from {$card->kaarigar->name}",
            'source_type' => 'job_card',
            'source_id' => $card->id,
            'amount' => $deduction,
            'meta' => ['deduction' => $deduction, 'excess_fine' => $excess],
        ]);

        $this->ledger->post(MetalTransaction::LOSS_WRITEOFF, [
            'job_card_id' => $card->id, 'kaarigar_id' => $card->kaarigar_id, 'process_id' => $card->process_id,
            'work_order_id' => $card->woStep?->work_order_id,
            'over_limit' => true, 'remarks' => "Excess wastage on {$card->card_no} recovered in money ({$voucher->voucher_no})",
        ], [[
            'from' => $this->ledger->kaarigar($card->kaarigar), 'to' => $this->ledger->of(MetalLocation::LOSS),
            'karat' => $card->karat, 'kind' => 'loss', 'fine' => $excess,
        ]]);

        return $voucher;
    }

    /** @return array{gross: string, pieces: int} returned work on the card, net of reversals */
    private function returnedWork(JobCard $card): array
    {
        $loc = $this->ledger->kaarigar($card->kaarigar)->id;
        $gross = '0.000';
        $pieces = 0;

        $lines = MetalTransactionLine::query()->where('kind', 'work')
            ->whereHas('transaction', fn ($q) => $q->where('job_card_id', $card->id))
            ->where(fn ($q) => $q->where('from_location_id', $loc)->orWhere('to_location_id', $loc))
            ->get(['from_location_id', 'gross_wt', 'pieces']);

        foreach ($lines as $l) {
            $out = $l->from_location_id === $loc;
            $gross = $out ? Weight::add($gross, $l->gross_wt) : Weight::sub($gross, $l->gross_wt);
            $pieces += ($out ? 1 : -1) * (int) $l->pieces;
        }

        return ['gross' => $gross, 'pieces' => max(0, $pieces)];
    }

    /** Today's rate for the purest karat (fine gold), or null if none is set. */
    private function fineRate(): ?string
    {
        $purest = Karat::orderByDesc('purity_pct')->first();
        $rates = GoldRateController::effectiveRates(today()->toDateString())->keyBy('karat_id');
        $rate = $purest ? ($rates[$purest->id]->rate_per_g ?? null) : null;

        return $rate !== null ? Money::round((string) $rate) : null;
    }
}
