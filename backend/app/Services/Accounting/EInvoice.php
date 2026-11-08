<?php

namespace App\Services\Accounting;

use App\Models\Voucher;
use App\Services\Metal\LedgerException;
use App\Support\Tenancy\TenantContext;

/**
 * GST e-invoice (IRN + QR) for tenants whose turnover requires it.
 *
 * payload() builds the IRP JSON (schema 1.1) from a posted sales invoice; it can be
 * uploaded through the GST portal's offline tool today. generate() sends it through
 * a GSP; plug a provider in by binding GspClient (none is configured yet).
 */
class EInvoice
{
    public function __construct(private TenantContext $context, private GspClient $gsp) {}

    public function payload(Voucher $invoice): array
    {
        if ($invoice->type !== Voucher::SALES) {
            throw new LedgerException('Only sales invoices can be e-invoiced.');
        }

        $invoice->loadMissing(['party', 'invoiceLines']);
        $t = $invoice->meta['totals'] ?? [];
        $tenant = $this->context->get();
        $buyer = $invoice->party;

        if (! $tenant->gstin || ! $buyer?->gstin) {
            throw new LedgerException('E-invoices need the GSTIN of both seller and buyer (B2B only).');
        }

        return [
            'Version' => '1.1',
            'TranDtls' => ['TaxSch' => 'GST', 'SupTyp' => 'B2B'],
            'DocDtls' => ['Typ' => 'INV', 'No' => $invoice->voucher_no, 'Dt' => date('d/m/Y', strtotime($invoice->date))],
            'SellerDtls' => ['Gstin' => $tenant->gstin, 'LglNm' => $tenant->name, 'Addr1' => (string) $tenant->address, 'Loc' => '', 'Pin' => null, 'Stcd' => $tenant->state_code],
            'BuyerDtls' => ['Gstin' => $buyer->gstin, 'LglNm' => $buyer->name, 'Pos' => $buyer->state_code, 'Addr1' => (string) $buyer->address, 'Loc' => '', 'Pin' => null, 'Stcd' => $buyer->state_code],
            'ItemList' => $invoice->invoiceLines->values()->map(fn ($l, $i) => [
                'SlNo' => (string) ($i + 1),
                'PrdDesc' => $l->description.($l->huid ? " HUID {$l->huid}" : ''),
                'IsServc' => 'N',
                'HsnCd' => $l->hsn,
                'Qty' => (float) $l->net_wt,
                'Unit' => 'GMS',
                'UnitPrice' => round((float) $l->taxable / max((float) $l->net_wt, 0.001), 2),
                'TotAmt' => (float) $l->taxable,
                'AssAmt' => (float) $l->taxable,
                'GstRt' => (float) $l->gst_rate,
                'CgstAmt' => (float) $l->cgst,
                'SgstAmt' => (float) $l->sgst,
                'IgstAmt' => (float) $l->igst,
                'TotItemVal' => (float) $l->total,
            ])->all(),
            'ValDtls' => [
                'AssVal' => (float) ($t['taxable'] ?? 0), 'CgstVal' => (float) ($t['cgst'] ?? 0),
                'SgstVal' => (float) ($t['sgst'] ?? 0), 'IgstVal' => (float) ($t['igst'] ?? 0),
                'TotInvVal' => (float) ($t['total'] ?? $invoice->amount),
            ],
        ];
    }

    public function generate(Voucher $invoice): Voucher
    {
        if (! empty($invoice->meta['einvoice']['irn'])) {
            throw new LedgerException('This invoice already has an IRN.');
        }

        $result = $this->gsp->generateIrn($this->payload($invoice));
        $invoice->forceFill(['meta' => array_merge($invoice->meta ?? [], ['einvoice' => $result])])->save();

        return $invoice;
    }
}
