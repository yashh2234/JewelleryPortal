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
use App\Services\Accounting\Books;
use App\Services\Accounting\Labour;
use App\Services\Accounting\Vouchers;
use App\Services\Production\OrderProgress;
use App\Services\Push\Notify;
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
 *   Issue                Vault | Finished | WO → Kaarigar          (against a job card)
 *   Handoff              Kaarigar → Kaarigar                       (head ↔ sub-kaarigar; custody, not a new issue)
 *   Receive              Kaarigar → destination (work), Vault (raw), WO (semi / rejected), Scrap (scrap, dust)
 *   Close job card       difference = in − out; stays on the kaarigar until approved with a reason
 *   Approve loss         Kaarigar → Loss (or excess recovered from labour)
 *   Melt                 Vault → Melt → Vault, difference → Loss
 *   Refine               Scrap → Refiner → Vault (24K), difference → Loss
 *   Transfer             between Vault, Finished goods and Scrap
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
            $books = app(Books::class);
            app(Vouchers::class)->metalReceipt(
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

    /** What a kaarigar can bring back on a job card (blueprint §3 return vouchers). */
    public const RETURN_KINDS = ['work', 'raw', 'semi', 'rejected'];

    public const SCRAP_KINDS = ['scrap', 'dust'];

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

        // Spec 16.3 / §6D: no more gold until earlier issues are acknowledged and reconciled.
        $incoming = $this->fineOf($items);
        app(IssueRules::class)->assert($card->kaarigar, $incoming, $header['override_reason'] ?? null);
        unset($header['override_reason']);

        return DB::transaction(function () use ($card, $items, $from, $to, $header) {
            $tx = $this->ledger->post(MetalTransaction::ISSUE, $this->cardHeader($card) + $header, array_map(
                fn ($i) => ['from' => $from, 'to' => $to, 'pieces' => $i['pieces'] ?? null] + $this->metal($i),
                $items,
            ));

            $grossTotal = Weight::add('0', ...$tx->lines->pluck('gross_wt')->all());
            app(Notify::class)->kaarigar(
                $card->kaarigar_id,
                'New gold issued · नया सोना',
                "{$grossTotal} g on {$card->card_no}. Please accept or dispute.",
                '/kaarigar/issues',
                'gold.issued',
                whatsapp: true,
            );

            $this->markStepStarted($card);

            return $tx;
        });
    }

    /**
     * Pass work and gold from one kaarigar's card to another kaarigar (blueprint §2).
     * A new child card is opened for the receiver under the giver's card. Custody
     * moves kaarigar → kaarigar: the giver's balance goes down by exactly what the
     * receiver's goes up, and nothing new leaves the vault.
     *
     * @param  array{process_id?: int|null, description?: string|null, pieces?: int|null, due_date?: string|null, date?: string|null}  $card
     * @param  list<array{gross: string, stone?: string, findings?: string, purity?: string|null, pieces?: int|null}>  $items
     */
    public function allocate(JobCard $from, Kaarigar $to, array $card, array $items, array $header): MetalTransaction
    {
        $this->assertOpen($from);
        if ($to->id === $from->kaarigar_id) {
            throw new LedgerException('Pick a different kaarigar to pass the work to.');
        }
        if (! $to->is_active) {
            throw new LedgerException("{$to->name} is inactive.");
        }

        return DB::transaction(function () use ($from, $to, $card, $items, $header) {
            $child = $this->openJobCard([
                'kaarigar_id' => $to->id,
                'process_id' => $card['process_id'] ?? $from->process_id,
                'karat_id' => $from->karat_id,
                'wo_step_id' => $from->wo_step_id,
                'parent_job_card_id' => $from->id,
                'design_id' => $from->design_id,
                'description' => $card['description'] ?? $from->description,
                'pieces' => $card['pieces'] ?? null,
                'due_date' => $card['due_date'] ?? $from->due_date?->toDateString(),
                'date' => $card['date'] ?? null,
            ]);

            app(IssueRules::class)->assert($to, $this->fineOf($items, $from), $header['override_reason'] ?? null);
            unset($header['override_reason']);

            return $this->handoff($from, $child, $items, $header);
        });
    }

    /**
     * Move custody between two open cards of different kaarigars: from a head's card
     * to a sub-kaarigar's child card, or back from a child card to its parent.
     *
     * @param  list<array{gross: string, stone?: string, findings?: string, purity?: string|null, pieces?: int|null}>  $items
     */
    public function handoff(JobCard $from, JobCard $to, array $items, array $header): MetalTransaction
    {
        $this->assertOpen($from);
        $this->assertOpen($to);

        if ($from->kaarigar_id === $to->kaarigar_id) {
            throw new LedgerException('A handoff must go to a different kaarigar.');
        }
        if ($from->karat_id !== $to->karat_id) {
            throw new LedgerException('Both job cards must be for the same karat.');
        }
        if ($items === []) {
            throw new LedgerException('Enter the weight being handed over.');
        }

        return DB::transaction(function () use ($from, $to, $items, $header) {
            JobCard::whereKey($from->id)->lockForUpdate()->first();

            $outstanding = $this->outstanding($this->cardFigures($from));
            $moving = $this->fineOf($items, $from);
            if (Weight::compare($moving, $outstanding) > 0) {
                throw new LedgerException("{$from->kaarigar->name} holds only {$outstanding} g fine on {$from->card_no}; this handoff is {$moving} g fine.");
            }

            $giver = $this->ledger->kaarigar($from->kaarigar);
            $taker = $this->ledger->kaarigar($to->kaarigar);

            $tx = $this->ledger->post(MetalTransaction::HANDOFF, $this->cardHeader($to) + ['from_job_card_id' => $from->id] + $header, array_map(
                fn ($i) => [
                    'from' => $giver, 'to' => $taker, 'karat' => $from->karat, 'kind' => 'handoff',
                    'gross' => (string) $i['gross'], 'stone' => (string) ($i['stone'] ?? '0'), 'findings' => (string) ($i['findings'] ?? '0'),
                    'purity' => isset($i['purity']) && $i['purity'] !== '' ? (string) $i['purity'] : null, 'pieces' => $i['pieces'] ?? null,
                ],
                $items,
            ));

            app(Notify::class)->kaarigar(
                $to->kaarigar_id,
                'Work handed to you · काम मिला',
                Weight::add('0', ...$tx->lines->pluck('gross_wt')->all())." g from {$from->kaarigar->name} on {$to->card_no}. Please accept or dispute.",
                '/kaarigar/issues',
                'gold.handoff',
            );

            $this->markStepStarted($to);

            return $tx;
        });
    }

    /**
     * Receive work back from the kaarigar. Kept for callers that only know work + scrap.
     *
     * @param  array{gross: string, stone?: string, findings?: string, purity?: string|null, pieces?: int|null}|null  $work
     * @param  array{gross: string, purity?: string|null}|null  $scrap
     */
    public function receive(JobCard $card, ?array $work, ?array $scrap, string $destination, array $header): MetalTransaction
    {
        return $this->receiveParts($card, array_filter(['work' => $work, 'scrap' => $scrap]), $destination, $header);
    }

    /**
     * Return voucher (blueprint §3): finished work, raw gold, semi-finished or
     * rejected pieces, scrap and dust, each recorded as its own line. A job card
     * can close over several receives (partial returns).
     *
     *   work            → the chosen destination (vault, finished goods or the work order)
     *   semi, rejected  → the work order when the card belongs to one, else the vault
     *   raw             → the vault (available raw-metal stock)
     *   scrap, dust     → the scrap & dust pool (recovered later by refining)
     *
     * @param  array<string, array{gross: string, stone?: string, findings?: string, purity?: string|null, pieces?: int|null}>  $parts  keyed by kind
     */
    public function receiveParts(JobCard $card, array $parts, string $destination, array $header): MetalTransaction
    {
        $this->assertOpen($card);

        $parts = array_filter($parts, fn ($p) => is_array($p) && isset($p['gross']) && Weight::isPositive(Weight::round((string) $p['gross'])));
        if ($parts === []) {
            throw new LedgerException('Enter at least one weight: work, raw gold, scrap, dust, semi-finished or rejected pieces.');
        }
        if ($unknown = array_diff(array_keys($parts), [...self::RETURN_KINDS, ...self::SCRAP_KINDS])) {
            throw new LedgerException('Unknown return type: '.implode(', ', $unknown).'.');
        }

        $kaarigar = $this->ledger->kaarigar($card->kaarigar);
        $inProcess = $card->wo_step_id ? 'wo' : 'vault';
        $lines = [];

        foreach ($parts as $kind => $p) {
            $to = match ($kind) {
                'work' => $this->stockLocation($destination, $card),
                'semi', 'rejected' => $this->stockLocation($inProcess, $card),
                'raw' => $this->ledger->vault(),
                default => $this->ledger->of(MetalLocation::SCRAP),
            };
            $lines[] = [
                'from' => $kaarigar, 'to' => $to, 'karat' => $card->karat, 'kind' => $kind,
                'gross' => (string) $p['gross'], 'stone' => (string) ($p['stone'] ?? '0'), 'findings' => (string) ($p['findings'] ?? '0'),
                'purity' => isset($p['purity']) && $p['purity'] !== '' ? (string) $p['purity'] : null, 'pieces' => $p['pieces'] ?? null,
            ];
        }

        return DB::transaction(function () use ($card, $lines, $header) {
            JobCard::whereKey($card->id)->lockForUpdate()->first();

            // Nothing can come back that this kaarigar does not hold on this card.
            $outstanding = $this->outstanding($this->cardFigures($card));
            $incoming = Weight::add('0', ...array_map(
                fn ($l) => Weight::fine(Weight::net($l['gross'], $l['stone'], $l['findings']), (string) ($l['purity'] ?? $card->karat->purity_pct)),
                $lines,
            ));

            if (Weight::compare($incoming, $outstanding) > 0) {
                throw new LedgerException("Only {$outstanding} g fine is outstanding on {$card->card_no}; this receive is {$incoming} g fine. Check the weights and purity.");
            }

            $tx = $this->ledger->post(MetalTransaction::RECEIVE, $this->cardHeader($card) + $header, $lines);
            app(OrderProgress::class)->forJobCard($card);

            return $tx;
        });
    }

    /**
     * Close the card and reconcile it: difference = gold in (issued + handed in)
     * − gold out (returned + scrap & dust + handed on). Nothing is written off
     * here: any difference waits on the kaarigar's balance until someone with the
     * approval right accepts it with a reason code and a note (blueprint §3).
     */
    public function closeJobCard(JobCard $card, ?string $date = null): JobCard
    {
        return DB::transaction(function () use ($card) {
            $card = JobCard::whereKey($card->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($card);

            $f = $this->cardFigures($card);
            $in = Weight::add($f['issued'], $f['handed_in']);
            $loss = $this->outstanding($f);

            if (Weight::compare($loss, '0') < 0) {
                throw new LedgerException("More fine came back than was given on {$card->card_no} ({$loss} g). Check the purity on the receives before closing.");
            }

            $pct = $card->process->allowedWastageFor($card->karat_id);
            $allowed = Weight::fine($in, $pct);       // gold in × allowed % ÷ 100
            $excess = Weight::compare($loss, $allowed) > 0 ? Weight::sub($loss, $allowed) : '0.000';

            if (Weight::isPositive($loss)) {
                app(Notify::class)->owners(
                    Weight::isPositive($excess) ? 'Gold reconciliation mismatch' : 'Gold difference to approve',
                    "{$card->kaarigar->name}: {$loss} g difference on {$card->card_no}"
                        .(Weight::isPositive($excess) ? " ({$excess} g over the allowed {$pct}%)." : " (within the allowed {$pct}%).")
                        .' Approve it with a reason before it leaves his balance.',
                    '/staff/metal/job-cards?f=alerts',
                    'gold.mismatch',
                );
            }

            $card->forceFill([
                'status' => JobCard::CLOSED,
                'issued_fine' => $f['issued'],
                'handed_in_fine' => $f['handed_in'],
                'returned_fine' => $f['returned'],
                'scrap_fine' => $f['scrap'],
                'handed_out_fine' => $f['handed_out'],
                'loss_fine' => $loss,
                'allowed_wastage_pct' => $pct,
                'allowed_loss_fine' => $allowed,
                'excess_loss_fine' => $excess,
                'loss_status' => Weight::isPositive($loss) ? JobCard::LOSS_PENDING : null,
                'closed_at' => now(),
                'closed_by' => Auth::guard('staff')->id(),
            ])->save();

            // Module 4: labour bill for the kaarigar.
            app(Labour::class)->billFor($card);

            return $card->fresh();
        });
    }

    /**
     * Authorised approval of the difference on a closed card. The whole difference
     * leaves the kaarigar's balance as an approved loss, except that the part above
     * the allowed % can instead be recovered from his labour in money.
     */
    public function approveLoss(JobCard $card, string $reasonCode, string $note, bool $recoverExcess = false): JobCard
    {
        if (! array_key_exists($reasonCode, JobCard::LOSS_REASONS)) {
            throw new LedgerException('Pick a reason for the difference.');
        }
        if (trim($note) === '') {
            throw new LedgerException('Add a note explaining the difference.');
        }

        return DB::transaction(function () use ($card, $reasonCode, $note, $recoverExcess) {
            $card = JobCard::whereKey($card->id)->lockForUpdate()->firstOrFail();

            if ($card->isOpen() || ! $card->lossPending()) {
                throw new LedgerException("There is no difference waiting for approval on {$card->card_no}.");
            }

            $excess = Weight::round((string) $card->excess_loss_fine);
            $hasExcess = Weight::isPositive($excess);
            $recovered = $hasExcess && $recoverExcess
                ? app(Labour::class)->recoverExcess($card)
                : null;

            $writeOff = $recovered ? Weight::sub((string) $card->loss_fine, $excess) : Weight::round((string) $card->loss_fine);
            $label = JobCard::LOSS_REASONS[$reasonCode];

            if (Weight::isPositive($writeOff)) {
                $this->ledger->post(MetalTransaction::LOSS_WRITEOFF, $this->cardHeader($card) + [
                    'over_limit' => $hasExcess && ! $recovered,
                    'remarks' => "Difference on {$card->card_no} approved ({$label}): {$note}",
                ], [[
                    'from' => $this->ledger->kaarigar($card->kaarigar),
                    'to' => $this->ledger->of(MetalLocation::LOSS),
                    'karat' => $card->karat,
                    'kind' => 'loss',
                    'fine' => $writeOff,
                ]]);
            }

            $card->forceFill([
                'loss_status' => JobCard::LOSS_APPROVED,
                'loss_reason_code' => $reasonCode,
                'loss_note' => $note,
                'loss_approved_by' => Auth::guard('staff')->id(),
                'loss_approved_at' => now(),
                'excess_resolution' => $hasExcess ? ($recovered ? 'recovered' : 'written_off') : null,
                'excess_resolved_at' => $hasExcess ? now() : null,
            ])->save();

            return $card->fresh();
        });
    }

    /**
     * Live fine figures for a card, net of reversals.
     *
     * @return array{issued: string, handed_in: string, returned: string, scrap: string, handed_out: string, by_kind: array<string, string>}
     */
    public function cardFigures(JobCard $card): array
    {
        $loc = $this->ledger->kaarigar($card->kaarigar)->id;
        $acc = ['issued' => '0.000', 'handed_in' => '0.000', 'returned' => '0.000', 'scrap' => '0.000', 'handed_out' => '0.000'];
        $byKind = [];

        $lines = fn (string $column) => MetalTransactionLine::query()
            ->whereHas('transaction', fn ($q) => $q->where($column, $card->id))
            ->where(fn ($q) => $q->where('to_location_id', $loc)->orWhere('from_location_id', $loc))
            ->get(['from_location_id', 'to_location_id', 'kind', 'fine_wt']);

        // Gold that came onto this card, and what came back from it.
        foreach ($lines('job_card_id') as $l) {
            $in = $l->to_location_id === $loc;

            // Reversal lines carry the original kind with direction flipped, so they net out.
            if ($l->kind === 'loss') {
                continue;
            }
            if (in_array($l->kind, [...self::RETURN_KINDS, ...self::SCRAP_KINDS], true)) {
                $bucket = in_array($l->kind, self::SCRAP_KINDS, true) ? 'scrap' : 'returned';
                $acc[$bucket] = $in ? Weight::sub($acc[$bucket], $l->fine_wt) : Weight::add($acc[$bucket], $l->fine_wt);
                $byKind[$l->kind] = $in ? Weight::sub($byKind[$l->kind] ?? '0.000', $l->fine_wt) : Weight::add($byKind[$l->kind] ?? '0.000', $l->fine_wt);

                continue;
            }

            $bucket = $l->kind === 'handoff' ? 'handed_in' : 'issued';
            $acc[$bucket] = $in ? Weight::add($acc[$bucket], $l->fine_wt) : Weight::sub($acc[$bucket], $l->fine_wt);
        }

        // Gold this card passed on to another kaarigar.
        foreach ($lines('from_job_card_id') as $l) {
            if ($l->kind !== 'handoff') {
                continue;
            }
            $out = $l->from_location_id === $loc;
            $acc['handed_out'] = $out ? Weight::add($acc['handed_out'], $l->fine_wt) : Weight::sub($acc['handed_out'], $l->fine_wt);
        }

        return $acc + ['by_kind' => $byKind];
    }

    /** Fine still with the kaarigar on this card (gold in − gold out). */
    public function outstanding(array $f): string
    {
        return Weight::sub(Weight::add($f['issued'], $f['handed_in']), Weight::add($f['returned'], $f['scrap'], $f['handed_out']));
    }

    private function markStepStarted(JobCard $card): void
    {
        if ($step = $card->woStep) {
            if ($step->status === 'pending') {
                $step->forceFill(['status' => 'in_progress', 'started_at' => now()])->save();
            }
            if ($step->workOrder->status === 'open') {
                $step->workOrder->forceFill(['status' => 'in_progress'])->save();
            }
            app(OrderProgress::class)->forWorkOrder($step->work_order_id);
        }
    }

    /** Fine weight of a list of items; purity falls back to the item's karat or the card's. */
    private function fineOf(array $items, ?JobCard $card = null): string
    {
        return Weight::add('0', ...array_map(fn ($i) => Weight::fine(
            Weight::net((string) $i['gross'], (string) ($i['stone'] ?? '0'), (string) ($i['findings'] ?? '0')),
            (string) (isset($i['purity']) && $i['purity'] !== ''
                ? $i['purity']
                : (isset($i['karat_id']) ? Karat::findOrFail($i['karat_id'])->purity_pct : $card->karat->purity_pct)),
        ), $items));
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
