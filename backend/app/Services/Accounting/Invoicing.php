<?php

namespace App\Services\Accounting;

use App\Http\Controllers\Staff\GoldRateController;
use App\Models\Dispatch;
use App\Models\MakingSlab;
use App\Models\TaxSetting;
use App\Models\Voucher;
use App\Services\Metal\LedgerException;
use App\Support\Metal\Weight;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Sales invoice from a dispatch, using the pieces' actual weights:
 *   metal value = net weight × day rate of the karat
 *   making      = retailer's slab (else the design's charge): per gram / per piece / % of metal
 *   + stone + hallmark charges, + GST (CGST+SGST in-state, IGST inter-state)
 *
 * Terms "cash": the retailer owes the full amount in money.
 * Terms "metal": the retailer settles the metal in fine gold from their metal
 * account; they owe money for making, charges and the GST.
 */
class Invoicing
{
    public const HSN = '7113';

    public function __construct(private Books $books, private Vouchers $vouchers, private TenantContext $context) {}

    /**
     * @param  array{date?: string, terms?: string, rates?: array<int|string, string>, stone_charges?: array<int|string, string>, hallmark_per_piece?: string}  $opts
     * @return array{lines: list<array<string, mixed>>, totals: array<string, string>, terms: string, inter_state: bool, date: string}
     */
    public function preview(Dispatch $dispatch, array $opts = []): array
    {
        $dispatch->loadMissing(['retailer.makingSlab', 'lines.item.design', 'lines.item.karat']);
        $retailer = $dispatch->retailer;
        $date = $opts['date'] ?? today()->toDateString();
        $terms = $opts['terms'] ?? ($retailer->metal_account_enabled ? 'metal' : 'cash');

        if ($terms === 'metal' && ! $retailer->metal_account_enabled) {
            throw new LedgerException("{$retailer->shop_name} has no metal account; bill on cash terms.");
        }

        $rates = GoldRateController::effectiveRates($date)->pluck('rate_per_g', 'karat_id')->all();
        foreach ($opts['rates'] ?? [] as $karatId => $rate) {
            $rates[(int) $karatId] = Money::round($rate);
        }

        $gstRate = TaxSetting::rateFor(self::HSN, $date);
        $tenantState = $this->context->get()->state_code;
        $interState = $tenantState && $retailer->state_code && $tenantState !== $retailer->state_code;
        $slab = $retailer->makingSlab ?? MakingSlab::where('is_default', true)->first();
        $hmPerPiece = Money::round($opts['hallmark_per_piece'] ?? '0');

        $lines = [];
        foreach ($dispatch->lines as $dl) {
            $item = $dl->item;
            $rate = $rates[$item->karat_id] ?? null;
            if ($rate === null) {
                throw new LedgerException("Set the {$item->karat->label} gold rate for {$date} (Settings → Gold rate) before billing.");
            }

            $metal = Money::mul($item->net_wt, $rate);
            [$basis, $makingRate] = $slab && ! Money::isZero($slab->rate)
                ? [$slab->basis, (string) $slab->rate]
                : [$item->design->making_type ?? 'per_gram', (string) ($item->design->making_charge ?? '0')];
            $making = match ($basis) {
                'per_piece' => Money::round($makingRate),
                'percent' => Money::percent($metal, $makingRate),
                default => Money::mul($item->net_wt, $makingRate),
            };
            $stone = Money::round($opts['stone_charges'][$item->id] ?? '0');
            $taxable = Money::add($metal, $making, $stone, $hmPerPiece);
            $tax = $this->vouchers->gst($taxable, $gstRate, $interState);

            $lines[] = [
                'finished_item_id' => $item->id,
                'description' => trim("{$item->design->code} {$item->design->name} {$item->karat->label}".($item->size ? " size {$item->size}" : '')),
                'tag_no' => $item->tag_no,
                'hsn' => self::HSN,
                'huid' => $item->huid,
                'gross_wt' => $item->gross_wt, 'net_wt' => $item->net_wt, 'purity_pct' => $item->purity_pct, 'fine_wt' => $item->fine_wt,
                'rate' => Money::round($rate), 'metal_value' => $metal, 'making' => $making, 'stone_charge' => $stone,
                'hallmark_charge' => $hmPerPiece, 'taxable' => $taxable, 'gst_rate' => $gstRate,
                'cgst' => $tax['cgst'], 'sgst' => $tax['sgst'], 'igst' => $tax['igst'],
                'total' => Money::add($taxable, $tax['cgst'], $tax['sgst'], $tax['igst']),
            ];
        }

        $sum = fn (string $k) => Money::add(...array_column($lines, $k));
        $totals = [
            'metal_value' => $sum('metal_value'), 'making' => $sum('making'), 'stone_charge' => $sum('stone_charge'),
            'hallmark_charge' => $sum('hallmark_charge'), 'taxable' => $sum('taxable'),
            'cgst' => $sum('cgst'), 'sgst' => $sum('sgst'), 'igst' => $sum('igst'), 'total' => $sum('total'),
            'fine_wt' => Weight::add('0', ...array_column($lines, 'fine_wt')),
            'net_wt' => Weight::add('0', ...array_column($lines, 'net_wt')),
        ];
        // Money the retailer owes: metal terms take the metal value out (settled in gold).
        $totals['payable'] = $terms === 'metal' ? Money::sub($totals['total'], $totals['metal_value']) : $totals['total'];

        return ['lines' => $lines, 'totals' => $totals, 'terms' => $terms, 'inter_state' => (bool) $interState, 'date' => $date];
    }

    public function post(Dispatch $dispatch, array $opts = []): Voucher
    {
        if ($dispatch->invoice_voucher_id) {
            throw new LedgerException("Challan {$dispatch->challan_no} is already billed.");
        }

        $p = $this->preview($dispatch, $opts);
        $t = $p['totals'];
        $party = $this->books->party($dispatch->retailer);

        $entries = [
            ['party' => $party, 'debit' => $t['payable'], 'fine_dr' => $p['terms'] === 'metal' ? $t['fine_wt'] : '0'],
            ['account' => 'making_income', 'credit' => $t['making']],
            ['account' => 'stone_income', 'credit' => $t['stone_charge']],
            ['account' => 'hallmark_income', 'credit' => $t['hallmark_charge']],
            ['account' => 'output_cgst', 'credit' => $t['cgst']],
            ['account' => 'output_sgst', 'credit' => $t['sgst']],
            ['account' => 'output_igst', 'credit' => $t['igst']],
        ];
        $entries[] = $p['terms'] === 'metal'
            ? ['account' => 'metal_control', 'fine_cr' => $t['fine_wt']]
            : ['account' => 'sales_gold', 'credit' => $t['metal_value']];

        return DB::transaction(function () use ($dispatch, $p, $t, $party, $entries) {
            $voucher = $this->books->post(Voucher::SALES, $p['date'], $party, $entries, [
                'narration' => "Invoice for challan {$dispatch->challan_no}",
                'source_type' => 'dispatch',
                'source_id' => $dispatch->id,
                'amount' => $t['total'],
                'fine_wt' => $t['fine_wt'],
                'meta' => ['terms' => $p['terms'], 'inter_state' => $p['inter_state'], 'totals' => $t, 'challan_no' => $dispatch->challan_no],
            ]);

            foreach ($p['lines'] as $line) {
                unset($line['tag_no']);
                $voucher->invoiceLines()->create($line);
            }

            $dispatch->forceFill(['invoice_voucher_id' => $voucher->id])->save();

            return $voucher;
        });
    }
}
