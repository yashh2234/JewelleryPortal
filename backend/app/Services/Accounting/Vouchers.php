<?php

namespace App\Services\Accounting;

use App\Models\Party;
use App\Models\Voucher;
use App\Services\Metal\LedgerException;
use App\Support\Metal\Weight;
use App\Support\Money;

/**
 * Everyday vouchers. Money moves between cash/bank and a party; metal moves
 * between the metal control account and a party.
 */
class Vouchers
{
    public function __construct(private Books $books) {}

    /** Money received from a party into cash or bank. */
    public function receipt(Party $party, string $amount, string $mode, string $date, ?string $narration = null, array $extra = []): Voucher
    {
        return $this->books->post(Voucher::RECEIPT, $date, $party, [
            ['account' => $this->cashOrBank($mode), 'debit' => $amount],
            ['party' => $party, 'credit' => $amount],
        ], ['narration' => $narration, 'meta' => ['mode' => $mode]] + $extra);
    }

    /** Money paid to a party (incl. kaarigar advances and weekly payments). */
    public function payment(Party $party, string $amount, string $mode, string $date, ?string $narration = null, array $extra = []): Voucher
    {
        return $this->books->post(Voucher::PAYMENT, $date, $party, [
            ['party' => $party, 'debit' => $amount],
            ['account' => $this->cashOrBank($mode), 'credit' => $amount],
        ], ['narration' => $narration, 'meta' => ['mode' => $mode]] + $extra);
    }

    /** Gold received from a party on account (e.g. a retailer's deposit). */
    public function metalReceipt(Party $party, string $fine, string $date, ?string $narration = null, array $extra = []): Voucher
    {
        return $this->books->post(Voucher::METAL_RECEIPT, $date, $party, [
            ['account' => 'metal_control', 'fine_dr' => $fine],
            ['party' => $party, 'fine_cr' => $fine],
        ], ['narration' => $narration, 'amount' => '0', 'fine_wt' => $fine] + $extra);
    }

    /** Gold given to a party on account (e.g. returning a retailer's metal, paying a supplier in metal). */
    public function metalPayment(Party $party, string $fine, string $date, ?string $narration = null): Voucher
    {
        return $this->books->post(Voucher::METAL_PAYMENT, $date, $party, [
            ['party' => $party, 'fine_dr' => $fine],
            ['account' => 'metal_control', 'fine_cr' => $fine],
        ], ['narration' => $narration, 'amount' => '0', 'fine_wt' => $fine]);
    }

    /**
     * Purchase from a supplier.
     *  - terms "rate": bought for money: Dr Purchases + input GST, Cr supplier.
     *  - terms "metal": bought on metal terms: supplier is owed fine gold; any
     *    money part (charges) is billed as above.
     */
    public function purchase(Party $supplier, string $date, string $terms, string $fine, string $taxable, string $gstRate, bool $interState, ?string $narration = null, ?string $billNo = null): Voucher
    {
        $entries = [];
        if ($terms === 'metal' && Weight::isPositive(Weight::round($fine))) {
            $entries[] = ['account' => 'metal_control', 'fine_dr' => $fine];
            $entries[] = ['party' => $supplier, 'fine_cr' => $fine];
        }

        $tax = $this->gst($taxable, $gstRate, $interState);
        $total = Money::add($taxable, $tax['cgst'], $tax['sgst'], $tax['igst']);

        if (! Money::isZero($total)) {
            $entries[] = ['account' => 'purchases', 'debit' => $taxable];
            $entries[] = ['account' => 'input_cgst', 'debit' => $tax['cgst']];
            $entries[] = ['account' => 'input_sgst', 'debit' => $tax['sgst']];
            $entries[] = ['account' => 'input_igst', 'debit' => $tax['igst']];
            $entries[] = ['party' => $supplier, 'credit' => $total];
        }

        return $this->books->post(Voucher::PURCHASE, $date, $supplier, $entries, [
            'narration' => $narration,
            'amount' => $total,
            'fine_wt' => $terms === 'metal' ? $fine : '0',
            'meta' => ['terms' => $terms, 'bill_no' => $billNo, 'taxable' => Money::round($taxable), 'gst_rate' => $gstRate] + $tax,
        ]);
    }

    /**
     * Rate-cut (fixing): convert part of a party's metal balance into money at an
     * agreed rate. If the party owes us gold, they now owe money (a sale); if we owe
     * them gold, we now owe money (a purchase).
     */
    public function rateCut(Party $party, string $fine, string $ratePerGram, string $date, ?string $narration = null): Voucher
    {
        $fine = Weight::round($fine);
        $balance = $this->books->partyBalance($party)['fine'];

        if (! Weight::isPositive($fine) || Weight::compare($fine, ltrim($balance, '-')) > 0) {
            throw new LedgerException("{$party->name}'s metal balance is {$balance} g; you can fix up to that much.");
        }

        $amount = Money::mul($fine, $ratePerGram);
        $theyOweMetal = Weight::compare($balance, '0') > 0;

        $entries = $theyOweMetal
            ? [
                ['party' => $party, 'fine_cr' => $fine, 'debit' => $amount],
                ['account' => 'metal_control', 'fine_dr' => $fine],
                ['account' => 'sales_gold', 'credit' => $amount],
            ]
            : [
                ['party' => $party, 'fine_dr' => $fine, 'credit' => $amount],
                ['account' => 'metal_control', 'fine_cr' => $fine],
                ['account' => 'purchases', 'debit' => $amount],
            ];

        return $this->books->post(Voucher::RATE_CUT, $date, $party, $entries, [
            'narration' => $narration ?? "Rate-cut {$fine} g @ ₹{$ratePerGram}/g",
            'amount' => $amount,
            'fine_wt' => $fine,
            'meta' => ['rate' => Money::round($ratePerGram), 'direction' => $theyOweMetal ? 'sale' : 'purchase'],
        ]);
    }

    /**
     * Manual journal / contra / credit note / debit note.
     *
     * @param  list<array{account_id: int, party_id?: int|null, debit?: string, credit?: string, fine_dr?: string, fine_cr?: string, memo?: string|null}>  $lines
     */
    public function journal(string $type, string $date, array $lines, ?string $narration = null): Voucher
    {
        $accounts = \App\Models\Account::whereIn('id', array_column($lines, 'account_id'))->get()->keyBy('id');
        $parties = Party::whereIn('id', array_filter(array_column($lines, 'party_id')))->get()->keyBy('id');

        $entries = array_map(fn ($l) => [
            'account' => $accounts[$l['account_id']] ?? throw new LedgerException('Unknown account.'),
            'party' => isset($l['party_id']) ? ($parties[$l['party_id']] ?? null) : null,
            'debit' => $l['debit'] ?? '0', 'credit' => $l['credit'] ?? '0',
            'fine_dr' => $l['fine_dr'] ?? '0', 'fine_cr' => $l['fine_cr'] ?? '0',
            'memo' => $l['memo'] ?? null,
        ], $lines);

        $party = collect($entries)->pluck('party')->filter()->first();

        return $this->books->post($type, $date, $party, $entries, ['narration' => $narration]);
    }

    /** @return array{cgst: string, sgst: string, igst: string} */
    public function gst(string $taxable, string $rate, bool $interState): array
    {
        if ($interState) {
            return ['cgst' => '0.00', 'sgst' => '0.00', 'igst' => Money::percent($taxable, $rate)];
        }
        $half = bcdiv($rate, '2', 4);

        return ['cgst' => Money::percent($taxable, $half), 'sgst' => Money::percent($taxable, $half), 'igst' => '0.00'];
    }

    private function cashOrBank(string $mode): string
    {
        return $mode === 'cash' ? 'cash' : 'bank';   // bank, upi, cheque, neft → bank
    }
}
