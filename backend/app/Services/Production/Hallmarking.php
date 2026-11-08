<?php

namespace App\Services\Production;

use App\Models\Ahc;
use App\Models\FinishedItem;
use App\Models\HallmarkLot;
use App\Models\Karat;
use App\Models\MetalLocation;
use App\Models\MetalTransaction;
use App\Services\Metal\Ledger;
use App\Services\Metal\LedgerException;
use App\Support\Metal\Weight;
use App\Support\NumberSeries;
use Illuminate\Support\Facades\DB;

/**
 * Module 6: BIS hallmarking. The system can't hallmark; it tracks the lot sent to
 * the AHC (its gold stays on the books under an "AHC" location), and records each
 * piece's pass/fail, HUID and returned weight. Sample weight lost in testing is
 * posted as hallmark loss.
 */
class Hallmarking
{
    public const HUID_PATTERN = '/^[A-Z0-9]{6}$/';

    public function __construct(private Ledger $ledger) {}

    /** @param list<int> $itemIds */
    public function createLot(Ahc $ahc, Karat $karat, array $itemIds): HallmarkLot
    {
        $items = FinishedItem::whereIn('id', $itemIds)->get();

        if ($items->count() !== count(array_unique($itemIds)) || $items->isEmpty()) {
            throw new LedgerException('Pick at least one piece.');
        }
        foreach ($items as $item) {
            if ($item->status !== FinishedItem::IN_STOCK || $item->qc_status !== 'passed') {
                throw new LedgerException("{$item->tag_no} must be in stock and QC-passed.");
            }
            if ($item->huid) {
                throw new LedgerException("{$item->tag_no} is already hallmarked ({$item->huid}).");
            }
            if ($item->karat_id !== $karat->id) {
                throw new LedgerException("{$item->tag_no} is not {$karat->label}; one lot has one declared purity.");
            }
        }
        if (\App\Models\HallmarkLotItem::whereIn('finished_item_id', $itemIds)->whereHas('lot', fn ($q) => $q->where('status', '!=', HallmarkLot::RETURNED))->exists()) {
            throw new LedgerException('Some pieces are already in another open lot.');
        }

        return DB::transaction(function () use ($ahc, $karat, $items) {
            $lot = HallmarkLot::create(['lot_no' => NumberSeries::next('HM'), 'ahc_id' => $ahc->id, 'karat_id' => $karat->id]);

            foreach ($items as $item) {
                $lot->items()->create(['finished_item_id' => $item->id, 'sent_wt' => $item->gross_wt]);
            }

            $lot->forceFill(['pieces' => $items->count(), 'gross_wt' => Weight::add('0', ...$items->pluck('gross_wt')->all())])->save();

            return $lot->load('items.finishedItem');
        });
    }

    /** Hand the lot to the AHC: gold moves Finished → AHC. */
    public function send(HallmarkLot $lot, ?string $manakRequestNo = null): HallmarkLot
    {
        if ($lot->status !== HallmarkLot::DRAFT) {
            throw new LedgerException("Lot {$lot->lot_no} has already been sent.");
        }

        return DB::transaction(function () use ($lot, $manakRequestNo) {
            $lot->load('items.finishedItem', 'ahc', 'karat');
            $finished = $this->ledger->of(MetalLocation::FINISHED);
            $ahc = $this->ledger->ahc($lot->ahc);

            $tx = $this->ledger->post(MetalTransaction::HALLMARK_SEND, ['party_name' => $lot->ahc->name, 'reference' => $lot->lot_no, 'remarks' => "Hallmark lot {$lot->lot_no}"],
                $lot->items->map(fn ($li) => [
                    'from' => $finished, 'to' => $ahc, 'karat' => $lot->karat, 'kind' => 'metal',
                    'gross' => $li->finishedItem->gross_wt, 'stone' => $li->finishedItem->stone_wt,
                    'purity' => $li->finishedItem->purity_pct, 'pieces' => 1,
                ])->all());

            $lot->items->each(fn ($li) => $li->finishedItem->forceFill(['status' => FinishedItem::AT_AHC])->save());
            $lot->forceFill([
                'status' => HallmarkLot::SENT, 'sent_at' => now(), 'send_transaction_id' => $tx->id,
                'manak_request_no' => $manakRequestNo ?? $lot->manak_request_no,
            ])->save();

            return $lot;
        });
    }

    /**
     * Record what came back. Passed pieces get their HUID; failed pieces return to
     * stock marked QC-failed (send them to rework). Weight lost to testing → loss.
     *
     * @param  list<array{finished_item_id: int, result: 'pass'|'fail', huid?: string|null, returned_wt: string, remarks?: string|null}>  $results
     */
    public function receive(HallmarkLot $lot, array $results, ?string $charges = null): HallmarkLot
    {
        if ($lot->status !== HallmarkLot::SENT) {
            throw new LedgerException("Lot {$lot->lot_no} is not at the AHC.");
        }

        $lot->load('items.finishedItem', 'ahc', 'karat');
        $byItem = collect($results)->keyBy('finished_item_id');

        if ($byItem->count() !== $lot->items->count() || $lot->items->contains(fn ($li) => ! $byItem->has($li->finished_item_id))) {
            throw new LedgerException('Enter a result for every piece in the lot.');
        }

        $huids = [];
        foreach ($byItem as $r) {
            if ($r['result'] === 'pass') {
                $huid = strtoupper(trim((string) ($r['huid'] ?? '')));
                if (! preg_match(self::HUID_PATTERN, $huid)) {
                    throw new LedgerException("HUID \"{$huid}\" is not valid: it must be 6 letters or digits.");
                }
                if (isset($huids[$huid]) || FinishedItem::where('huid', $huid)->exists()) {
                    throw new LedgerException("HUID {$huid} is already used.");
                }
                $huids[$huid] = true;
            }
        }

        return DB::transaction(function () use ($lot, $byItem, $charges) {
            $ahc = $this->ledger->ahc($lot->ahc);
            $finished = $this->ledger->of(MetalLocation::FINISHED);
            $lines = [];
            $loss = '0.000';

            foreach ($lot->items as $li) {
                $item = $li->finishedItem;
                $r = $byItem[$item->id];
                $back = Weight::round((string) $r['returned_wt']);

                if (Weight::compare($back, $item->gross_wt) > 0) {
                    throw new LedgerException("{$item->tag_no} came back heavier ({$back} g) than it went ({$item->gross_wt} g).");
                }

                $net = Weight::net($back, $item->stone_wt);
                $fineBack = Weight::fine($net, $item->purity_pct);
                $loss = Weight::add($loss, Weight::sub($item->fine_wt, $fineBack));

                $lines[] = ['from' => $ahc, 'to' => $finished, 'karat' => $lot->karat, 'kind' => 'work',
                    'gross' => $back, 'stone' => $item->stone_wt, 'purity' => $item->purity_pct, 'pieces' => 1];

                $pass = $r['result'] === 'pass';
                $item->forceFill([
                    'gross_wt' => $back, 'net_wt' => $net, 'fine_wt' => $fineBack,
                    'huid' => $pass ? strtoupper(trim($r['huid'])) : null,
                    'status' => FinishedItem::IN_STOCK,
                    'qc_status' => $pass ? 'passed' : 'failed',
                    'qc_remarks' => $pass ? $item->qc_remarks : 'Hallmark failed: '.($r['remarks'] ?? 'see AHC report'),
                ])->save();

                $li->forceFill([
                    'returned_wt' => $back, 'result' => $r['result'],
                    'huid' => $pass ? strtoupper(trim($r['huid'])) : null, 'remarks' => $r['remarks'] ?? null,
                ])->save();
            }

            if (Weight::isPositive($loss)) {
                $lines[] = ['from' => $ahc, 'to' => $this->ledger->of(MetalLocation::LOSS), 'karat' => $lot->karat, 'kind' => 'loss', 'fine' => $loss];
            }

            $tx = $this->ledger->post(MetalTransaction::HALLMARK_RETURN, [
                'party_name' => $lot->ahc->name, 'reference' => $lot->lot_no,
                'remarks' => "Hallmark lot {$lot->lot_no} returned; testing loss {$loss} g",
            ], $lines);

            $lot->forceFill([
                'status' => HallmarkLot::RETURNED, 'returned_at' => now(), 'return_transaction_id' => $tx->id,
                'charges' => $charges ?? bcmul((string) $lot->ahc->charge_per_piece, (string) $lot->items->count(), 2),
            ])->save();

            return $lot;
        });
    }

    /**
     * Parse the AHC's HUID list (CSV: tag_no, huid[, result][, returned_wt]) into
     * results for the return form. Nothing is saved; staff review and submit.
     *
     * @return list<array<string, mixed>>
     */
    public function parseHuidCsv(HallmarkLot $lot, string $csv): array
    {
        $lot->load('items.finishedItem');
        $byTag = $lot->items->keyBy(fn ($li) => strtoupper($li->finishedItem->tag_no));
        $rows = [];

        foreach (preg_split('/\r\n|\n|\r/', trim($csv)) as $n => $line) {
            $cols = array_map('trim', str_getcsv($line));
            if ($n === 0 && ! preg_match('/\d/', implode('', $cols))) {
                continue; // header row
            }
            $li = $byTag[strtoupper($cols[0] ?? '')] ?? null;
            if (! $li) {
                continue;
            }
            $huid = strtoupper($cols[1] ?? '');
            $rows[] = [
                'finished_item_id' => $li->finished_item_id,
                'tag_no' => $li->finishedItem->tag_no,
                'huid' => $huid,
                'result' => strtolower($cols[2] ?? '') === 'fail' || $huid === '' ? 'fail' : 'pass',
                'returned_wt' => isset($cols[3]) && is_numeric($cols[3]) ? Weight::round($cols[3]) : $li->sent_wt,
            ];
        }

        return $rows;
    }
}
