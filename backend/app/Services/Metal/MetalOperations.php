<?php

namespace App\Services\Metal;

use App\Models\JobCard;
use App\Models\Kaarigar;
use App\Models\Karat;
use App\Models\MetalLocation;
use App\Models\MetalTransaction;
use App\Models\MetalTransactionLine;
use App\Models\Process;
use App\Models\Retailer;
use App\Support\Metal\Weight;
use App\Support\NumberSeries;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Module 1 business operations, each a thin rule layer over Ledger::post().
 *
 *   Purchase / opening   Outside → Vault
 *   Retailer deposit     Outside → Vault (the retailer's metal credit is tracked on the party; Module 4)
 *   Issue                Vault | Finished → Kaarigar               (against a job card)
 *   Receive              Kaarigar → Vault | Finished (work) + Kaarigar → Scrap (scrap)
 *   Close job card       loss = issued − returned − scrap; within limit → Loss, above → stays + alert
 *   Melt                 Vault → Melt → Vault, difference → Loss
 *   Refine               Scrap → Refiner → Vault (24K), difference → Loss
 *   Transfer             between Vault and Finished goods
 */
class MetalOperations
{
    public function __construct(private Ledger $ledger) {}

    // ── Stock in ─────────────────────────────────────────────────────────────

    /** @param list<array{karat_id: int, gross: string, purity?: string|null}> $items */
    public function purchase(array $items, array $header, bool $opening = false): MetalTransaction
    {
        $outside = $this->ledger->of(MetalLocation::OUTSIDE);
        $vault = $this->ledger->vault();

        return $this->ledger->post($opening ? MetalTransaction::OPENING : MetalTransaction::PURCHASE, $header, array_map(
            fn ($i) => ['from' => $outside, 'to' => $vault] + $this->metal($i),
            $items,
        ));
    }

    public function deposit(Retailer $retailer, array $items, array $header): MetalTransaction
    {
        if (! $retailer->metal_account_enabled) {
            throw new LedgerException("{$retailer->shop_name} does not have a metal account. Turn it on in the retailer's settings first.");
        }

        $outside = $this->ledger->of(MetalLocation::OUTSIDE);
        $vault = $this->ledger->vault();

        return DB::transaction(function () use ($retailer, $header, $items, $outside, $vault) {
            $tx = $this->ledger->post(MetalTransaction::DEPOSIT, ['retailer_id' => $retailer->id] + $header, array_map(
                fn ($i) => ['from' => $outside, 'to' => $vault, 'kind' => 'deposit'] + $this->metal($i),
                $items,
            ));

            // Module 4: the retailer's metal account is credited with the fine gold.
            $books = app(\App\Services\Accounting\Books::class);
            app(\App\Services\Accounting\Vouchers::class)->metalReceipt(
                $books->party($retailer),
                Weight::add('0', ...$tx->lines->pluck('fine_wt')->all()),
                $tx->date,
                "Metal deposit {$tx->voucher_no}",
                ['source_type' => 'metal_transaction', 'source_id' => $tx->id],
            );

            return $tx;
        });
    }

    // ── Kaarigar work ────────────────────────────────────────────────────────

    public function openJobCard(array $data): JobCard
    {
        return DB::transaction(fn () => JobCard::create(Arr::except($data, ['date']) + [
            'card_no' => NumberSeries::next('JC', $data['date'] ?? null),
        ]));
    }

    /**
     * Issue gold to the kaarigar on a job card.
     *
     * @param  list<array{karat_id: int, gross: string, purity?: string|null, pieces?: int|null}>  $items
     */
    public function issue(JobCard $card, array $items, string $source, array $header): MetalTransaction
    {
        $this->assertOpen($card);
        $from = $this->stockLocation($source, $card);
        $to = $this->ledger->kaarigar($card->kaarigar);

        return DB::transaction(function () use ($card, $items, $from, $to, $header) {
            $tx = $this->ledger->post(MetalTransaction::ISSUE, $this->cardHeader($card) + $header, array_map(
                fn ($i) => ['from' => $from, 'to' => $to, 'pieces' => $i['pieces'] ?? null] + $this->metal($i),
                $items,
            ));

            $grossTotal = Weight::add('0', ...$tx->lines->pluck('gross_wt')->all());
            app(\App\Services\Push\Notify::class)->kaarigar(
                $card->kaarigar_id,
                'New gold issued · नया सोना',
                "{$grossTotal} g on {$card->card_no}. Please accept or dispute.",
                '/app/kaarigar/issues',
            );

            if ($step = $card->woStep) {
                if ($step->status === 'pending') {
                    $step->forceFill(['status' => 'in_progress', 'started_at' => now()])->save();
                }
                if ($step->workOrder->status === 'open') {
                    $step->workOrder->forceFill(['status' => 'in_progress'])->save();
                }
            }

            return $tx;
        });
    }

    /**
     * Receive work (and optionally scrap) back from the kaarigar. A job card can
     * close over several receives (partial returns).
     *
     * @param  array{gross: string, stone?: string, findings?: string, purity?: string|null, pieces?: int|null}|null  $work
     * @param  array{gross: string, purity?: string|null}|null  $scrap
     */
    public function receive(JobCard $card, ?array $work, ?array $scrap, string $destination, array $header): MetalTransaction
    {
        $this->assertOpen($card);

        if (! $work && ! $scrap) {
            throw new LedgerException('Enter the work weight, the scrap weight, or both.');
        }

        $kaarigar = $this->ledger->kaarigar($card->kaarigar);
        $karat = $card->karat;
        $lines = [];

        if ($work) {
            $lines[] = [
                'from' => $kaarigar, 'to' => $this->stockLocation($destination, $card), 'karat' => $karat, 'kind' => 'work',
                'gross' => $work['gross'], 'stone' => $work['stone'] ?? '0', 'findings' => $work['findings'] ?? '0',
                'purity' => $work['purity'] ?? null, 'pieces' => $work['pieces'] ?? null,
            ];
        }

        if ($scrap) {
            $lines[] = [
                'from' => $kaarigar, 'to' => $this->ledger->of(MetalLocation::SCRAP), 'karat' => $karat, 'kind' => 'scrap',
                'gross' => $scrap['gross'], 'purity' => $scrap['purity'] ?? null,
            ];
        }

        return DB::transaction(function () use ($card, $lines, $header) {
            JobCard::whereKey($card->id)->lockForUpdate()->first();

            // Nothing can come back that was never issued on this card.
            $figures = $this->cardFigures($card);
            $outstanding = Weight::sub($figures['issued'], Weight::add($figures['returned'], $figures['scrap']));
            $incoming = Weight::add('0', ...array_map(
                fn ($l) => Weight::fine(Weight::net((string) $l['gross'], (string) ($l['stone'] ?? '0'), (string) ($l['findings'] ?? '0')), (string) ($l['purity'] ?? $card->karat->purity_pct)),
                $lines,
            ));

            if (Weight::compare($incoming, $outstanding) > 0) {
                throw new LedgerException("Only {$outstanding} g fine is outstanding on {$card->card_no}; this receive is {$incoming} g fine. Check the weights and purity.");
            }

            return $this->ledger->post(MetalTransaction::RECEIVE, $this->cardHeader($card) + $header, $lines);
        });
    }

    /**
     * Close the card: loss = issued − returned − scrap. Loss within the allowed
     * wastage is written off automatically; anything above it stays on the
     * kaarigar's balance and is flagged for the owner.
     */
    public function closeJobCard(JobCard $card, ?string $date = null): JobCard
    {
        return DB::transaction(function () use ($card, $date) {
            $card = JobCard::whereKey($card->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($card);

            $f = $this->cardFigures($card);
            $loss = Weight::sub($f['issued'], Weight::add($f['returned'], $f['scrap']));

            if (Weight::compare($loss, '0') < 0) {
                throw new LedgerException("More fine came back than was issued on {$card->card_no} ({$loss} g). Check the purity on the receives before closing.");
            }

            $pct = $card->process->allowedWastageFor($card->karat_id);
            $allowed = Weight::fine($f['issued'], $pct);       // issued × allowed % ÷ 100
            $writeOff = Weight::compare($loss, $allowed) <= 0 ? $loss : $allowed;
            $excess = Weight::sub($loss, $writeOff);

            if (Weight::isPositive($writeOff)) {
                $this->ledger->post(MetalTransaction::LOSS_WRITEOFF, $this->cardHeader($card) + [
                    'date' => $date ?? today()->toDateString(),
                    'over_limit' => Weight::isPositive($excess),
                    'remarks' => "Wastage on {$card->card_no} within the allowed {$pct}%",
                ], [[
                    'from' => $this->ledger->kaarigar($card->kaarigar),
                    'to' => $this->ledger->of(MetalLocation::LOSS),
                    'karat' => $card->karat,
                    'kind' => 'loss',
                    'fine' => $writeOff,
                ]]);
            }

            if (Weight::isPositive($excess)) {
                app(\App\Services\Push\Notify::class)->owners(
                    'Wastage above limit',
                    "{$card->kaarigar->name}: {$loss} g loss on {$card->card_no} ({$excess} g over the allowed {$pct}%).",
                    '/app/staff/metal/job-cards?f=alerts',
                );
            }

            $card->forceFill([
                'status' => JobCard::CLOSED,
                'issued_fine' => $f['issued'],
                'returned_fine' => $f['returned'],
                'scrap_fine' => $f['scrap'],
                'loss_fine' => $loss,
                'allowed_wastage_pct' => $pct,
                'allowed_loss_fine' => $allowed,
                'excess_loss_fine' => $excess,
                'closed_at' => now(),
                'closed_by' => Auth::guard('staff')->id(),
            ])->save();

            // Module 4: labour bill for the kaarigar (and recovery of excess wastage).
            app(\App\Services\Accounting\Labour::class)->billFor($card);

            return $card->fresh();
        });
    }

    /** Owner approves writing off loss that was above the limit. */
    public function writeOffExcess(JobCard $card, string $reason): MetalTransaction
    {
        return DB::transaction(function () use ($card, $reason) {
            $card = JobCard::whereKey($card->id)->lockForUpdate()->firstOrFail();

            if ($card->isOpen() || ! Weight::isPositive($card->excess_loss_fine) || $card->excess_resolution) {
                throw new LedgerException('There is no unresolved excess wastage on this job card.');
            }

            $tx = $this->ledger->post(MetalTransaction::LOSS_WRITEOFF, $this->cardHeader($card) + [
                'over_limit' => true,
                'remarks' => "Excess wastage on {$card->card_no} approved: {$reason}",
            ], [[
                'from' => $this->ledger->kaarigar($card->kaarigar),
                'to' => $this->ledger->of(MetalLocation::LOSS),
                'karat' => $card->karat,
                'kind' => 'loss',
                'fine' => $card->excess_loss_fine,
            ]]);

            $card->forceFill(['excess_resolution' => 'written_off', 'excess_resolved_at' => now()])->save();

            return $tx;
        });
    }

    /** @return array{issued: string, returned: string, scrap: string} live fine figures for a card */
    public function cardFigures(JobCard $card): array
    {
        $kaarigarLoc = $this->ledger->kaarigar($card->kaarigar)->id;

        $lines = MetalTransactionLine::query()
            ->whereHas('transaction', fn ($q) => $q->where('job_card_id', $card->id))
            ->where(fn ($q) => $q->where('to_location_id', $kaarigarLoc)->orWhere('from_location_id', $kaarigarLoc))
            ->get(['from_location_id', 'to_location_id', 'kind', 'fine_wt']);

        $issued = $returned = $scrap = '0.000';

        foreach ($lines as $l) {
            $in = $l->to_location_id === $kaarigarLoc;   // gold going to the kaarigar
            $sign = fn (string $acc) => $in ? Weight::add($acc, $l->fine_wt) : Weight::sub($acc, $l->fine_wt);

            // Reversal lines carry the original kind with direction flipped, so they net out.
            match (true) {
                $l->kind === 'loss' => null,
                $l->kind === 'scrap' => $scrap = $in ? Weight::sub($scrap, $l->fine_wt) : Weight::add($scrap, $l->fine_wt),
                $l->kind === 'work' => $returned = $in ? Weight::sub($returned, $l->fine_wt) : Weight::add($returned, $l->fine_wt),
                default => $issued = $sign($issued),
            };
        }

        return ['issued' => $issued, 'returned' => $returned, 'scrap' => $scrap];
    }

    // ── Vault work ───────────────────────────────────────────────────────────

    /**
     * Melting / alloying. Inputs leave the vault, the bar comes back; fine in must
     * equal fine out + melting loss. Alloy (copper/silver) adds gross, not fine.
     *
     * @param  list<array{karat_id: int, gross: string, purity?: string|null}>  $inputs
     * @param  array{karat_id: int, gross: string, purity?: string|null}  $output
     */
    public function melt(array $inputs, string $alloyGross, array $output, ?Process $process, array $header): MetalTransaction
    {
        $vault = $this->ledger->vault();
        $melt = $this->ledger->of(MetalLocation::MELT);
        $outKarat = Karat::findOrFail($output['karat_id']);

        $lines = array_map(fn ($i) => ['from' => $vault, 'to' => $melt] + $this->metal($i), $inputs);

        if (Weight::isPositive(Weight::round($alloyGross))) {
            $lines[] = ['from' => $this->ledger->of(MetalLocation::OUTSIDE), 'to' => $melt, 'karat' => $outKarat, 'kind' => 'alloy', 'gross' => $alloyGross, 'purity' => '0'];
        }

        $lines[] = ['from' => $melt, 'to' => $vault, 'kind' => 'work'] + $this->metal($output);

        $fineIn = Weight::add('0', ...array_map(fn ($i) => Weight::fine($i['gross'], (string) ($i['purity'] ?? Karat::findOrFail($i['karat_id'])->purity_pct)), $inputs));
        $fineOut = Weight::fine($output['gross'], (string) ($output['purity'] ?? $outKarat->purity_pct));
        $loss = Weight::sub($fineIn, $fineOut);

        if (Weight::compare($loss, '0') < 0) {
            throw new LedgerException("The bar has more fine gold ({$fineOut} g) than went in ({$fineIn} g). Check the purity of the bar.");
        }

        $overLimit = false;
        if (Weight::isPositive($loss)) {
            $lines[] = ['from' => $melt, 'to' => $this->ledger->of(MetalLocation::LOSS), 'karat' => $outKarat, 'kind' => 'loss', 'fine' => $loss];
            $allowed = $process ? Weight::fine($fineIn, $process->allowedWastageFor($outKarat->id)) : $loss;
            $overLimit = Weight::compare($loss, $allowed) > 0;
        }

        return $this->ledger->post(MetalTransaction::MELT, ['process_id' => $process?->id, 'over_limit' => $overLimit] + $header, $lines);
    }

    /**
     * Refining: scrap goes to the refiner; fine gold comes back to the vault.
     * The difference is refining loss.
     */
    public function refine(array $sent, array $returned, array $header): MetalTransaction
    {
        $scrap = $this->ledger->of(MetalLocation::SCRAP);
        $refiner = $this->ledger->of(MetalLocation::REFINER);
        $vault = $this->ledger->vault();

        $sentLines = array_map(fn ($i) => ['from' => $scrap, 'to' => $refiner, 'kind' => 'scrap'] + $this->metal($i), $sent);
        $back = ['from' => $refiner, 'to' => $vault, 'kind' => 'work'] + $this->metal($returned);

        $fineSent = Weight::add('0', ...array_map(fn ($l) => Weight::fine(Weight::round($l['gross']), (string) ($l['purity'] ?? $l['karat']->purity_pct)), $sentLines));
        $fineBack = Weight::fine(Weight::round($back['gross']), (string) ($back['purity'] ?? $back['karat']->purity_pct));
        $loss = Weight::sub($fineSent, $fineBack);

        if (Weight::compare($loss, '0') < 0) {
            throw new LedgerException("The refiner returned more fine ({$fineBack} g) than was sent ({$fineSent} g).");
        }

        $lines = [...$sentLines, $back];
        if (Weight::isPositive($loss)) {
            $lines[] = ['from' => $refiner, 'to' => $this->ledger->of(MetalLocation::LOSS), 'karat' => $back['karat'], 'kind' => 'loss', 'fine' => $loss];
        }

        return $this->ledger->post(MetalTransaction::REFINE, $header, $lines);
    }

    public function transfer(string $from, string $to, array $items, array $header): MetalTransaction
    {
        $fromLoc = $this->stockLocation($from);
        $toLoc = $this->stockLocation($to);

        return $this->ledger->post(MetalTransaction::TRANSFER, $header, array_map(
            fn ($i) => ['from' => $fromLoc, 'to' => $toLoc] + $this->metal($i),
            $items,
        ));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function metal(array $item): array
    {
        return [
            'karat' => Karat::findOrFail($item['karat_id']),
            'gross' => (string) $item['gross'],
            'stone' => (string) ($item['stone'] ?? '0'),
            'findings' => (string) ($item['findings'] ?? '0'),
            'purity' => isset($item['purity']) && $item['purity'] !== '' ? (string) $item['purity'] : null,
            'pieces' => $item['pieces'] ?? null,
        ];
    }

    private function stockLocation(string $name, ?JobCard $card = null): MetalLocation
    {
        return match ($name) {
            'vault' => $this->ledger->vault(),
            'finished' => $this->ledger->of(MetalLocation::FINISHED),
            'scrap' => $this->ledger->of(MetalLocation::SCRAP),
            'wo' => $card?->woStep
                ? $this->ledger->workOrder($card->woStep->workOrder)
                : throw new LedgerException('This job card is not part of a work order.'),
            default => throw new LedgerException("Unknown stock location [{$name}]."),
        };
    }

    private function cardHeader(JobCard $card): array
    {
        return [
            'job_card_id' => $card->id,
            'kaarigar_id' => $card->kaarigar_id,
            'process_id' => $card->process_id,
            'work_order_id' => $card->woStep?->work_order_id,
        ];
    }

    private function assertOpen(JobCard $card): void
    {
        if (! $card->isOpen()) {
            throw new LedgerException("Job card {$card->card_no} is closed.");
        }
    }

    public function kaarigarLocation(Kaarigar $kaarigar): MetalLocation
    {
        return $this->ledger->kaarigar($kaarigar);
    }
}
