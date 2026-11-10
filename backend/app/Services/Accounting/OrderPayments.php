<?php

namespace App\Services\Accounting;

use App\Models\Order;
use App\Models\Voucher;
use App\Support\Money;

/**
 * Money side of one order (spec §4: order value, advance, payment status). Kept
 * apart from the metal side: an order can be paid in money while the retailer's
 * gold account is settled separately.
 *
 *   paid      receipts posted against the order (advances and later payments)
 *   invoiced  sales invoices from the order's challans
 *   status    unpaid | advance (paid before any invoice) | partly_paid | paid
 */
class OrderPayments
{
    public function summary(Order $order): array
    {
        $paid = (string) Voucher::where('type', Voucher::RECEIPT)->where('status', 'posted')
            ->where('source_type', 'order')->where('source_id', $order->id)->sum('amount');
        $invoiced = (string) Voucher::where('type', Voucher::SALES)->where('status', 'posted')
            ->where('source_type', 'dispatch')->whereIn('source_id', $order->dispatches()->select('id'))->sum('amount');

        $paid = Money::round($paid);
        $invoiced = Money::round($invoiced);

        $status = match (true) {
            Money::isZero($paid) => 'unpaid',
            Money::isZero($invoiced) => 'advance',
            Money::compare($paid, $invoiced) >= 0 => 'paid',
            default => 'partly_paid',
        };

        $value = Money::isZero($invoiced) ? $order->estimated_value : $invoiced;

        return [
            'value' => $value,
            'value_is_estimate' => Money::isZero($invoiced),
            'invoiced' => $invoiced,
            'paid' => $paid,
            'balance' => $value !== null ? Money::sub((string) $value, $paid) : null,
            'status' => $status,
        ];
    }

    public function refresh(Order $order): Order
    {
        $s = $this->summary($order);
        $order->forceFill([
            'payment_status' => $s['status'],
            // Advance = what came in before the first invoice; afterwards it is frozen.
            'advance_amount' => Money::isZero($s['invoiced']) ? $s['paid'] : $order->advance_amount,
        ])->save();

        return $order;
    }
}
