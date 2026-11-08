<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Ahc;
use App\Models\FinancialYear;
use App\Models\Kaarigar;
use App\Models\Party;
use App\Models\Retailer;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Services\Metal\LedgerException;
use App\Support\FinancialYear as Fy;
use App\Support\Metal\Weight;
use App\Support\Money;
use App\Support\NumberSeries;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of the money books. Every voucher balances on two columns:
 * Σ debit = Σ credit (₹) and Σ fine_dr = Σ fine_cr (grams). Posted vouchers are
 * never edited; cancel() posts a mirror voucher. Dates in a locked period are refused.
 */
class Books
{
    /** Default chart of accounts for a jewellery manufacturer (editable afterwards). */
    public const CHART = [
        // code, name, nature, group, system_key
        ['1000', 'Cash in hand', 'asset', 'Cash', 'cash'],
        ['1010', 'Bank account', 'asset', 'Bank', 'bank'],
        ['1100', 'Sundry debtors (retailers)', 'asset', 'Debtors', 'debtors'],
        ['1200', 'Metal control (gold owed / held on account)', 'asset', 'Stock', 'metal_control'],
        ['1210', 'Input CGST', 'asset', 'Duties & taxes', 'input_cgst'],
        ['1211', 'Input SGST', 'asset', 'Duties & taxes', 'input_sgst'],
        ['1212', 'Input IGST', 'asset', 'Duties & taxes', 'input_igst'],
        ['2000', 'Sundry creditors (suppliers, refiners, AHC)', 'liability', 'Creditors', 'creditors'],
        ['2100', 'Kaarigar accounts', 'liability', 'Kaarigars', 'kaarigars'],
        ['2200', 'Output CGST', 'liability', 'Duties & taxes', 'output_cgst'],
        ['2201', 'Output SGST', 'liability', 'Duties & taxes', 'output_sgst'],
        ['2202', 'Output IGST', 'liability', 'Duties & taxes', 'output_igst'],
        ['3000', 'Capital account', 'equity', 'Capital', 'capital'],
        ['4000', 'Sales: gold jewellery', 'income', 'Sales', 'sales_gold'],
        ['4010', 'Making charges income', 'income', 'Sales', 'making_income'],
        ['4020', 'Stone & other charges income', 'income', 'Sales', 'stone_income'],
        ['4030', 'Hallmarking charges recovered', 'income', 'Sales', 'hallmark_income'],
        ['4040', 'Wastage recovered from kaarigars', 'income', 'Other income', 'wastage_recovery'],
        ['4050', 'Job-work income', 'income', 'Sales', 'jobwork_income'],
        ['5000', 'Purchases: gold & bullion', 'expense', 'Purchases', 'purchases'],
        ['5010', 'Kaarigar labour', 'expense', 'Direct expenses', 'labour'],
        ['5020', 'Refining charges', 'expense', 'Direct expenses', 'refining'],
        ['5030', 'Hallmarking charges', 'expense', 'Direct expenses', 'hallmarking'],
        ['5040', 'Wastage / metal loss', 'expense', 'Direct expenses', 'wastage'],
        ['5050', 'Salaries', 'expense', 'Indirect expenses', 'salaries'],
        ['5060', 'Rent', 'expense', 'Indirect expenses', 'rent'],
        ['5070', 'Other expenses', 'expense', 'Indirect expenses', 'other_expenses'],
    ];

    /** Create the chart of accounts and GST default for a tenant (idempotent). */
    public function setup(): void
    {
        if (Account::exists()) {
            return;
        }

        foreach (self::CHART as [$code, $name, $nature, $group, $key]) {
            Account::create(['code' => $code, 'name' => $name, 'nature' => $nature, 'group' => $group, 'system_key' => $key]);
        }

        \App\Models\TaxSetting::create([
            'hsn' => '7113', 'description' => 'Articles of jewellery of precious metal (gold)', 'gst_rate' => '3.00', 'effective_from' => '2017-07-01',
        ]);
    }

    public function account(string $systemKey): Account
    {
        $this->setup();

        return Account::where('system_key', $systemKey)->firstOr(fn () => throw new LedgerException("Account [{$systemKey}] is missing from the chart of accounts."));
    }

    /** The accounting party for a retailer, kaarigar or AHC (created on first use). */
    public function party(Model $ref): Party
    {
        $type = match (true) {
            $ref instanceof Retailer => 'retailer',
            $ref instanceof Kaarigar => 'kaarigar',
            $ref instanceof Ahc => 'ahc',
            default => throw new LedgerException('Unsupported party.'),
        };

        return Party::firstOrCreate(
            ['ref_type' => $ref->getMorphClass(), 'ref_id' => $ref->getKey()],
            [
                'type' => $type,
                'name' => $ref->shop_name ?? $ref->name,
                'gstin' => $ref->gstin ?? null,
                'state_code' => $ref->state_code ?? null,
                'phone' => $ref->phone ?? null,
                'address' => $ref->address ?? null,
            ],
        );
    }

    public function controlAccount(Party $party): Account
    {
        return $this->account(Party::CONTROL[$party->type] ?? 'creditors');
    }

    public function financialYear(string $date): FinancialYear
    {
        $label = Fy::label($date);

        return FinancialYear::firstOrCreate(['label' => $label], [
            'start_date' => Fy::start($date)->toDateString(),
            'end_date' => Fy::end($date)->toDateString(),
        ]);
    }

    /**
     * @param  list<array{account: Account|string, party?: Party|null, debit?: string, credit?: string, fine_dr?: string, fine_cr?: string, memo?: string|null}>  $entries
     *         `account` may be a system key; a party line with no account uses the party's control account.
     */
    public function post(string $type, string $date, ?Party $party, array $entries, array $extra = []): Voucher
    {
        $this->setup();
        $this->assertOpen($date);

        $rows = [];
        foreach ($entries as $e) {
            $account = $e['account'] ?? null;
            if ($account === null && isset($e['party'])) {
                $account = $this->controlAccount($e['party']);
            } elseif (is_string($account)) {
                $account = $this->account($account);
            }

            $row = [
                'account_id' => $account->id,
                'party_id' => ($e['party'] ?? null)?->id,
                'debit' => Money::round($e['debit'] ?? '0'),
                'credit' => Money::round($e['credit'] ?? '0'),
                'fine_dr' => Weight::round((string) ($e['fine_dr'] ?? '0')),
                'fine_cr' => Weight::round((string) ($e['fine_cr'] ?? '0')),
                'memo' => $e['memo'] ?? null,
            ];

            foreach (['debit', 'credit'] as $k) {
                if (Money::compare($row[$k], '0') < 0) {
                    throw new LedgerException('Voucher amounts cannot be negative.');
                }
            }
            foreach (['fine_dr', 'fine_cr'] as $k) {
                if (Weight::compare($row[$k], '0') < 0) {
                    throw new LedgerException('Metal amounts cannot be negative.');
                }
            }

            if (Money::isZero($row['debit']) && Money::isZero($row['credit']) && Weight::compare($row['fine_dr'], '0') === 0 && Weight::compare($row['fine_cr'], '0') === 0) {
                continue;
            }
            $rows[] = $row;
        }

        if ($rows === []) {
            throw new LedgerException('Nothing to post.');
        }

        $dr = Money::add(...array_column($rows, 'debit'));
        $cr = Money::add(...array_column($rows, 'credit'));
        $fdr = Weight::add('0', ...array_column($rows, 'fine_dr'));
        $fcr = Weight::add('0', ...array_column($rows, 'fine_cr'));

        if (Money::compare($dr, $cr) !== 0) {
            throw new LedgerException("Voucher does not balance: debit ₹{$dr} ≠ credit ₹{$cr}.");
        }
        if (Weight::compare($fdr, $fcr) !== 0) {
            throw new LedgerException("Voucher does not balance in metal: {$fdr} g ≠ {$fcr} g.");
        }

        return DB::transaction(function () use ($type, $date, $party, $rows, $extra, $dr, $fdr) {
            $voucher = Voucher::create([
                'voucher_no' => NumberSeries::next(Voucher::SERIES[$type], $date),
                'type' => $type,
                'date' => $date,
                'financial_year_id' => $this->financialYear($date)->id,
                'party_id' => $party?->id,
                'amount' => $extra['amount'] ?? $dr,
                'fine_wt' => $extra['fine_wt'] ?? $fdr,
            ] + array_intersect_key($extra, array_flip(['narration', 'source_type', 'source_id', 'reverses_id', 'meta'])));

            foreach ($rows as $row) {
                $voucher->entries()->create($row);
            }

            return $voucher->load('entries');
        });
    }

    /** Cancel by posting the mirror image. The original keeps its number and is marked cancelled. */
    public function cancel(Voucher $voucher, string $reason): Voucher
    {
        if ($voucher->isCancelled() || $voucher->type === Voucher::REVERSAL) {
            throw new LedgerException('This voucher is already cancelled or is itself a reversal.');
        }
        $this->assertOpen($voucher->date);

        return DB::transaction(function () use ($voucher, $reason) {
            $mirror = $voucher->entries->map(fn (VoucherEntry $e) => [
                'account' => $e->account, 'party' => $e->party,
                'debit' => $e->credit, 'credit' => $e->debit, 'fine_dr' => $e->fine_cr, 'fine_cr' => $e->fine_dr,
                'memo' => $e->memo,
            ])->all();

            $date = max($voucher->date, today()->toDateString());
            $reversal = $this->post(Voucher::REVERSAL, $date, $voucher->party, $mirror, [
                'narration' => "Cancels {$voucher->voucher_no}: {$reason}",
                'reverses_id' => $voucher->id,
                'amount' => $voucher->amount,
                'fine_wt' => $voucher->fine_wt,
            ]);

            $voucher->forceFill(['status' => 'cancelled', 'cancelled_by_id' => $reversal->id])->save();

            return $reversal;
        });
    }

    /** @return array{money: string, fine: string} positive = party owes us (Dr), negative = we owe the party (Cr) */
    public function partyBalance(Party $party, ?string $asOf = null): array
    {
        $row = VoucherEntry::query()
            ->where('party_id', $party->id)
            ->when($asOf, fn ($q) => $q->whereHas('voucher', fn ($v) => $v->where('date', '<=', $asOf)))
            ->selectRaw('SUM(debit) as dr, SUM(credit) as cr, SUM(fine_dr) as fdr, SUM(fine_cr) as fcr')
            ->toBase()->first();

        return [
            'money' => Money::sub(Money::fromDb($row->dr), Money::fromDb($row->cr)),
            'fine' => Weight::sub(Weight::fromDb($row->fdr), Weight::fromDb($row->fcr)),
        ];
    }

    public function assertOpen(string $date): void
    {
        if ($date > today()->toDateString()) {
            throw new LedgerException('Vouchers cannot be dated in the future.');
        }

        $locked = FinancialYear::max('locked_until');
        if ($locked && $date <= $locked) {
            throw new LedgerException("The books are locked up to {$locked}.");
        }
    }
}
