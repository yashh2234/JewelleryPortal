<?php

namespace App\Services\Metal;

use App\Models\JobCard;
use App\Models\Kaarigar;
use App\Models\MetalTransactionLine;
use App\Support\Metal\Weight;
use Illuminate\Support\Facades\DB;

/** Kaarigar khata (statement) and wastage reports, all derived from ledger lines. */
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
        $totals = ['issued' => '0.000', 'returned' => '0.000', 'scrap' => '0.000', 'loss' => '0.000'];
        $rows = [];

        foreach ($lines as $l) {
            $in = $l->to_location_id === $loc;
            $running = $in ? Weight::add($running, $l->fine_wt) : Weight::sub($running, $l->fine_wt);

            $bucket = match (true) {
                $l->kind === 'loss' => 'loss',
                $l->kind === 'scrap' => 'scrap',
                $l->kind === 'work' => 'returned',
                default => 'issued',
            };
            // Reversals flip direction; keep the bucket and the sign honest.
            $signed = ($bucket === 'issued') === $in ? $l->fine_wt : Weight::negate($l->fine_wt);
            $totals[$bucket] = Weight::add($totals[$bucket], $signed);

            $rows[] = [
                'date' => $l->transaction->date,
                'voucher_no' => $l->transaction->voucher_no,
                'type' => $l->transaction->type,
                'kind' => $bucket,
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

    /**
     * Wastage % per kaarigar or per process over closed job cards in a date range.
     *
     * @return list<array{id: int, name: string, cards: int, issued: string, loss: string, excess: string, wastage_pct: string}>
     */
    public function wastage(string $groupBy, string $from, string $to): array
    {
        $column = $groupBy === 'process' ? 'process_id' : 'kaarigar_id';
        $table = $groupBy === 'process' ? 'processes' : 'kaarigars';

        $rows = JobCard::query()
            ->where('status', JobCard::CLOSED)
            ->whereBetween('closed_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->join($table, "{$table}.id", '=', "job_cards.{$column}")
            ->groupBy("job_cards.{$column}", "{$table}.name")
            ->select(["job_cards.{$column} as id", "{$table}.name as name"])
            ->selectRaw('COUNT(*) as cards, SUM(issued_fine) as issued, SUM(loss_fine) as loss, SUM(excess_loss_fine) as excess')
            ->toBase()
            ->get();

        return $rows->map(function ($r) {
            $issued = Weight::fromDb($r->issued);
            $loss = Weight::fromDb($r->loss);

            return [
                'id' => (int) $r->id,
                'name' => $r->name,
                'cards' => (int) $r->cards,
                'issued' => $issued,
                'loss' => $loss,
                'excess' => Weight::fromDb($r->excess),
                'wastage_pct' => Weight::percentOf($loss, $issued),
            ];
        })->sortByDesc(fn ($r) => (float) $r['wastage_pct'])->values()->all();
    }
}
