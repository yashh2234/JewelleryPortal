<?php

namespace App\Services\Metal;

use App\Models\Kaarigar;
use App\Models\Karat;
use App\Models\MetalClosing;
use App\Models\MetalLocation;
use App\Models\MetalTransaction;
use App\Models\MetalTransactionLine;
use App\Support\Metal\Weight;
use App\Support\NumberSeries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only place gold is written. Every posting:
 *  - runs in one DB transaction and locks the source locations,
 *  - computes net and fine from gross / stone / findings / purity with bcmath,
 *  - refuses to take any stock location (vault, kaarigar, scrap…) below zero,
 *  - refuses dates in a closed day or in the future,
 *  - gets a gap-free voucher number per type and financial year.
 */
class Ledger
{
    // ── Locations ────────────────────────────────────────────────────────────

    public function location(string $type, ?Model $ref = null, ?string $name = null): MetalLocation
    {
        return MetalLocation::firstOrCreate(
            ['type' => $type, 'ref_type' => $ref ? $ref->getMorphClass() : null, 'ref_id' => $ref?->getKey()],
            ['name' => $name ?? ($ref->name ?? ucfirst($type))],
        );
    }

    public function vault(): MetalLocation
    {
        return $this->location(MetalLocation::VAULT, name: 'Vault');
    }

    public function kaarigar(Kaarigar $kaarigar): MetalLocation
    {
        return $this->location(MetalLocation::KAARIGAR, $kaarigar, $kaarigar->name);
    }

    public function workOrder(\App\Models\WorkOrder $wo): MetalLocation
    {
        return $this->location(MetalLocation::WO, $wo, $wo->wo_no);
    }

    public function ahc(\App\Models\Ahc $ahc): MetalLocation
    {
        return $this->location(MetalLocation::AHC, $ahc, 'AHC · '.$ahc->name);
    }

    public function of(string $type): MetalLocation
    {
        return $this->location($type, name: match ($type) {
            MetalLocation::SCRAP => 'Scrap & dust pool',
            MetalLocation::FINISHED => 'Finished goods',
            MetalLocation::LOSS => 'Loss (wastage)',
            MetalLocation::OUTSIDE => 'Outside',
            MetalLocation::MELT => 'Melting',
            MetalLocation::REFINER => 'At refiner',
            default => ucfirst($type),
        });
    }

    // ── Posting ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $header  date, job_card_id, kaarigar_id, process_id, retailer_id, party_name, reference, remarks, over_limit, reverses_id
     * @param  list<array{from: MetalLocation, to: MetalLocation, karat: Karat, gross?: string, stone?: string, findings?: string, purity?: string, fine?: string, pieces?: int|null, kind?: string}>  $lines
     *         Pass `fine` instead of `gross` for loss lines (gross is then derived from the karat purity).
     */
    public function post(string $type, array $header, array $lines): MetalTransaction
    {
        if ($lines === []) {
            throw new LedgerException('Nothing to post.');
        }

        $date = CarbonImmutable::parse($header['date'] ?? today())->toDateString();
        $this->assertDateOpen($date);

        return DB::transaction(function () use ($type, $header, $lines, $date) {
            $rows = array_map(fn (array $line) => $this->normalise($line), $lines);

            $this->assertStockStaysPositive($rows);

            $tx = MetalTransaction::create(['type' => $type, 'date' => $date] + $header + [
                'voucher_no' => NumberSeries::next(MetalTransaction::SERIES[$type], $date),
            ]);

            foreach ($rows as $row) {
                $tx->lines()->create($row);
            }

            return $tx->load('lines');
        });
    }

    /** Post the mirror image of a transaction. The original stays, linked both ways. */
    public function reverse(MetalTransaction $tx, string $reason): MetalTransaction
    {
        return DB::transaction(function () use ($tx, $reason) {
            $tx = MetalTransaction::whereKey($tx->id)->lockForUpdate()->firstOrFail();

            if ($tx->isReversed() || $tx->type === MetalTransaction::REVERSAL) {
                throw new LedgerException('This entry is already reversed or is itself a reversal.');
            }

            if ($tx->jobCard && ! $tx->jobCard->isOpen()) {
                throw new LedgerException('The job card for this entry is closed; it can no longer be reversed.');
            }

            $lines = $tx->lines()->with(['from', 'to', 'karat'])->get()->map(fn (MetalTransactionLine $l) => [
                'from' => $l->to, 'to' => $l->from, 'karat' => $l->karat, 'kind' => $l->kind,
                'gross' => $l->gross_wt, 'stone' => $l->stone_wt, 'findings' => $l->findings_wt,
                'purity' => $l->purity_pct, 'pieces' => $l->pieces,
            ])->all();

            $reversal = $this->post(MetalTransaction::REVERSAL, [
                'date' => today()->toDateString(),
                'job_card_id' => $tx->job_card_id,
                'kaarigar_id' => $tx->kaarigar_id,
                'process_id' => $tx->process_id,
                'retailer_id' => $tx->retailer_id,
                'reverses_id' => $tx->id,
                'remarks' => "Reversal of {$tx->voucher_no}: {$reason}",
            ], $lines);

            $tx->reversed_by_id = $reversal->id;
            $tx->save();

            return $reversal;
        });
    }

    // ── Balances (always derived from lines) ────────────────────────────────

    /** @return array{fine: string, gross: string} */
    public function balance(MetalLocation|int $location, ?int $karatId = null, ?string $asOf = null): array
    {
        $id = $location instanceof MetalLocation ? $location->id : $location;
        $rows = $this->balanceRows([$id], $asOf, $karatId);

        $fine = $gross = '0.000';
        foreach ($rows as $r) {
            $fine = Weight::add($fine, $r['fine']);
            $gross = Weight::add($gross, $r['gross']);
        }

        return ['fine' => $fine, 'gross' => $gross];
    }

    /**
     * Balances per location and karat.
     *
     * @param  list<int>|null  $locationIds  null = all locations
     * @return list<array{location_id: int, karat_id: int, fine: string, gross: string}>
     */
    public function balanceRows(?array $locationIds = null, ?string $asOf = null, ?int $karatId = null): array
    {
        $sum = function (string $column) use ($locationIds, $asOf, $karatId) {
            return MetalTransactionLine::query()
                ->select([$column.' as location_id', 'karat_id'])
                ->selectRaw('SUM(fine_wt) as fine, SUM(gross_wt) as gross')
                ->when($locationIds !== null, fn ($q) => $q->whereIn($column, $locationIds))
                ->when($karatId, fn ($q) => $q->where('karat_id', $karatId))
                ->when($asOf, fn ($q) => $q->whereHas('transaction', fn ($t) => $t->where('date', '<=', $asOf)))
                ->groupBy($column, 'karat_id')
                ->toBase()
                ->get();
        };

        $acc = [];
        foreach ([[$sum('to_location_id'), false], [$sum('from_location_id'), true]] as [$rows, $out]) {
            foreach ($rows as $r) {
                $key = $r->location_id.':'.$r->karat_id;
                $acc[$key] ??= ['location_id' => (int) $r->location_id, 'karat_id' => (int) $r->karat_id, 'fine' => '0.000', 'gross' => '0.000'];
                $fine = Weight::fromDb($r->fine);
                $gross = Weight::fromDb($r->gross);
                $acc[$key]['fine'] = $out ? Weight::sub($acc[$key]['fine'], $fine) : Weight::add($acc[$key]['fine'], $fine);
                $acc[$key]['gross'] = $out ? Weight::sub($acc[$key]['gross'], $gross) : Weight::add($acc[$key]['gross'], $gross);
            }
        }

        return array_values($acc);
    }

    /** Total fine per location type (vault, kaarigar, scrap…). */
    public function totalsByType(?string $asOf = null, ?int $karatId = null): array
    {
        $types = MetalLocation::pluck('type', 'id');
        $totals = [];

        foreach ($this->balanceRows(null, $asOf, $karatId) as $row) {
            $type = $types[$row['location_id']] ?? 'unknown';
            $totals[$type] = Weight::add($totals[$type] ?? '0.000', $row['fine']);
        }

        return $totals;
    }

    public function lastClosedDate(): ?string
    {
        return MetalClosing::max('date');
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function assertDateOpen(string $date): void
    {
        if ($date > today()->toDateString()) {
            throw new LedgerException('Entries cannot be dated in the future.');
        }

        $closed = $this->lastClosedDate();
        if ($closed !== null && $date <= $closed) {
            throw new LedgerException("The books are closed up to {$closed}. Post this entry with a later date.");
        }
    }

    private function normalise(array $line): array
    {
        /** @var Karat $karat */
        $karat = $line['karat'];
        $purity = (string) ($line['purity'] ?? $karat->purity_pct);

        if (isset($line['fine'])) {
            // Loss lines: we know the fine; gross is derived for the per-karat gross view.
            $fine = Weight::round((string) $line['fine']);
            $gross = bccomp($purity, '0', 3) > 0
                ? Weight::round(bcdiv(bcmul($fine, '100', 10), $purity, 10))
                : $fine;
            $stone = $findings = '0.000';
            $net = $gross;
        } else {
            $gross = Weight::round((string) $line['gross']);
            $stone = Weight::round((string) ($line['stone'] ?? '0'));
            $findings = Weight::round((string) ($line['findings'] ?? '0'));
            $net = Weight::net($gross, $stone, $findings);
            $fine = Weight::fine($net, $purity);
        }

        if (Weight::compare($gross, '0') < 0 || Weight::compare($net, '0') < 0) {
            throw new LedgerException('Weights cannot be negative (net gold = gross − stones − findings).');
        }

        if (bccomp($purity, '0', 3) < 0 || bccomp($purity, '100', 3) > 0) {
            throw new LedgerException('Purity must be between 0 and 100 %.');
        }

        if ($line['from']->id === $line['to']->id) {
            throw new LedgerException('A line cannot move gold to the same place it came from.');
        }

        return [
            'from_location_id' => $line['from']->id,
            'to_location_id' => $line['to']->id,
            'karat_id' => $karat->id,
            'kind' => $line['kind'] ?? 'metal',
            'gross_wt' => $gross,
            'stone_wt' => $stone,
            'findings_wt' => $findings,
            'net_wt' => $net,
            'purity_pct' => Weight::round($purity),
            'fine_wt' => $fine,
            'pieces' => $line['pieces'] ?? null,
        ];
    }

    /**
     * Vault, scrap and finished goods are checked per karat (you can't issue 22K you
     * don't have); kaarigars, work orders and the AHC hold mixed gold, so their total
     * fine is checked.
     */
    private function assertStockStaysPositive(array $rows): void
    {
        $outgoing = [];
        foreach ($rows as $r) {
            $outgoing[$r['from_location_id']][$r['karat_id']] = Weight::add($outgoing[$r['from_location_id']][$r['karat_id']] ?? '0', $r['fine_wt']);
        }
        $incoming = [];
        foreach ($rows as $r) {
            $incoming[$r['to_location_id']][$r['karat_id']] = Weight::add($incoming[$r['to_location_id']][$r['karat_id']] ?? '0', $r['fine_wt']);
        }

        $locations = MetalLocation::whereIn('id', array_keys($outgoing))->lockForUpdate()->get()->keyBy('id');

        foreach ($outgoing as $locationId => $byKarat) {
            $location = $locations[$locationId];
            if (! $location->isStock()) {
                continue;
            }

            $perKarat = in_array($location->type, [MetalLocation::VAULT, MetalLocation::SCRAP, MetalLocation::FINISHED], true);
            $checks = $perKarat ? array_keys($byKarat) : [null];

            foreach ($checks as $karatId) {
                $have = $this->balance($location, $karatId)['fine'];
                $moving = $karatId ? $byKarat[$karatId] : Weight::add(...array_values($byKarat));
                $back = $karatId ? ($incoming[$locationId][$karatId] ?? '0') : Weight::add('0', ...array_values($incoming[$locationId] ?? []));
                $after = Weight::sub(Weight::add($have, $back), $moving);

                if (Weight::compare($after, '0') < 0) {
                    $what = $karatId ? Karat::find($karatId)?->label.' ' : '';
                    throw new LedgerException(sprintf(
                        '%s has only %s g fine %sgold; this needs %s g.',
                        $location->name, $have, $what, $moving,
                    ));
                }
            }
        }
    }
}
