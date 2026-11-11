<?php

namespace App\Services\Metal;

use App\Models\Kaarigar;
use App\Models\MetalTransactionLine;
use App\Support\Metal\Weight;

/** Kaarigar khata (statement), derived from ledger lines. */
class Statements
{
    public function __construct(private Ledger $ledger) {}

    /**
     * Every issue, return, scrap and loss for a kaarigar with a running fine balance.
     *
     * @return array{opening: string, closing: string, rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function kaarigar(Kaarigar $kaarigar, ?string $from = null, ?string $to = null): array
    {
        $loc = $this->ledger->kaarigar($kaarigar)->id;
        $opening = $from ? $this->ledger->balance($loc, asOf: date('Y-m-d', strtotime($from.' -1 day')))['fine'] : '0.000';

        $lines = MetalTransactionLine::query()
            ->with(['transaction:id,voucher_no,type,date,remarks,job_card_id,reverses_id', 'transaction.jobCard:id,card_no,process_id', 'transaction.jobCard.process:id,name', 'karat:id,label'])
            ->where(fn ($q) => $q->where('to_location_id', $loc)->orWhere('from_location_id', $loc))
            ->whereHas('transaction', fn ($q) => $q
                ->when($from, fn ($q) => $q->where('date', '>=', $from))
                ->when($to, fn ($q) => $q->where('date', '<=', $to)))
            ->get()
            ->sortBy(fn ($l) => [$l->transaction->date, $l->metal_transaction_id, $l->id])
            ->values();

        $running = $opening;
        $totals = ['issued' => '0.000', 'handoff' => '0.000', 'returned' => '0.000', 'scrap' => '0.000', 'loss' => '0.000'];
        $rows = [];

        foreach ($lines as $l) {
            $in = $l->to_location_id === $loc;
            $running = $in ? Weight::add($running, $l->fine_wt) : Weight::sub($running, $l->fine_wt);

            $bucket = match (true) {
                $l->kind === 'loss' => 'loss',
                in_array($l->kind, MetalOperations::SCRAP_KINDS, true) => 'scrap',
                in_array($l->kind, MetalOperations::RETURN_KINDS, true) => 'returned',
                $l->kind === 'handoff' => 'handoff',
                default => 'issued',
            };
            // Reversals flip direction; keep the bucket and the sign honest.
            // Handoffs are netted: gold in from another kaarigar is +, gold passed on is −.
            $signed = in_array($bucket, ['issued', 'handoff'], true) === $in ? $l->fine_wt : Weight::negate($l->fine_wt);
            $totals[$bucket] = Weight::add($totals[$bucket], $signed);

            $rows[] = [
                'date' => $l->transaction->date,
                'voucher_no' => $l->transaction->voucher_no,
                'type' => $l->transaction->type,
                'kind' => $bucket,
                'line_kind' => $l->kind,
                'job_card' => $l->transaction->jobCard?->card_no,
                'process' => $l->transaction->jobCard?->process?->name,
                'karat' => $l->karat->label,
                'gross_wt' => $l->gross_wt,
                'purity_pct' => $l->purity_pct,
                'in' => $in ? $l->fine_wt : null,
                'out' => $in ? null : $l->fine_wt,
                'balance' => $running,
                'remarks' => $l->transaction->remarks,
            ];
        }

        return ['opening' => $opening, 'closing' => $running, 'rows' => $rows, 'totals' => $totals];
    }
}
