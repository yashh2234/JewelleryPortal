<?php

namespace App\Services\Accounting;

use App\Http\Controllers\Staff\GoldRateController;
use App\Models\Account;
use App\Models\Karat;
use App\Models\MetalLocation;
use App\Models\Party;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Services\Metal\Ledger;
use App\Support\Metal\Weight;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;

/** Books of account, all derived from voucher entries. */
class Reports
{
    public function __construct(private Books $books, private Ledger $ledger, private TenantContext $context) {}

    /** @return array<int, array{dr: string, cr: string, fdr: string, fcr: string}> per account id */
    private function sums(?string $from, ?string $to, ?callable $scope = null): array
    {
        $rows = VoucherEntry::query()
            ->whereHas('voucher', fn ($q) => $q
                ->when($from, fn ($q) => $q->where('date', '>=', $from))
                ->when($to, fn ($q) => $q->where('date', '<=', $to)))
            ->when($scope, $scope)
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(debit) as dr, SUM(credit) as cr, SUM(fine_dr) as fdr, SUM(fine_cr) as fcr')
            ->toBase()->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->account_id] = [
                'dr' => Money::fromDb($r->dr), 'cr' => Money::fromDb($r->cr),
                'fdr' => Weight::fromDb($r->fdr), 'fcr' => Weight::fromDb($r->fcr),
            ];
        }

        return $out;
    }

    public function trialBalance(string $asOf): array
    {
        $this->books->setup();
        $sums = $this->sums(null, $asOf);
        $rows = [];
        $tot = ['debit' => '0.00', 'credit' => '0.00', 'fine_dr' => '0.000', 'fine_cr' => '0.000'];

        foreach (Account::orderBy('code')->get() as $a) {
            $s = $sums[$a->id] ?? null;
            if (! $s) {
                continue;
            }
            $net = Money::sub($s['dr'], $s['cr']);
            $fine = Weight::sub($s['fdr'], $s['fcr']);
            $row = [
                'account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'group' => $a->group, 'nature' => $a->nature,
                'debit' => Money::compare($net, '0') > 0 ? $net : '0.00',
                'credit' => Money::compare($net, '0') < 0 ? Money::abs($net) : '0.00',
                'fine_dr' => Weight::compare($fine, '0') > 0 ? $fine : '0.000',
                'fine_cr' => Weight::compare($fine, '0') < 0 ? ltrim($fine, '-') : '0.000',
            ];
            foreach ($tot as $k => $v) {
                $tot[$k] = str_starts_with($k, 'fine') ? Weight::add($v, $row[$k]) : Money::add($v, $row[$k]);
            }
            $rows[] = $row;
        }

        return ['as_of' => $asOf, 'rows' => $rows, 'totals' => $tot,
            'balanced' => Money::compare($tot['debit'], $tot['credit']) === 0 && Weight::compare($tot['fine_dr'], $tot['fine_cr']) === 0];
    }

    public function profitAndLoss(string $from, string $to): array
    {
        $this->books->setup();
        $sums = $this->sums($from, $to);
        $income = $expense = [];
        $ti = $te = '0.00';

        foreach (Account::whereIn('nature', ['income', 'expense'])->orderBy('code')->get() as $a) {
            $s = $sums[$a->id] ?? null;
            if (! $s) {
                continue;
            }
            if ($a->nature === 'income') {
                $amt = Money::sub($s['cr'], $s['dr']);
                $income[] = ['code' => $a->code, 'name' => $a->name, 'group' => $a->group, 'amount' => $amt];
                $ti = Money::add($ti, $amt);
            } else {
                $amt = Money::sub($s['dr'], $s['cr']);
                $expense[] = ['code' => $a->code, 'name' => $a->name, 'group' => $a->group, 'amount' => $amt];
                $te = Money::add($te, $amt);
            }
        }

        return ['from' => $from, 'to' => $to, 'income' => $income, 'expense' => $expense,
            'total_income' => $ti, 'total_expense' => $te, 'net_profit' => Money::sub($ti, $te)];
    }

    /** Assets = liabilities + equity, with profit to date carried into equity (no closing entries needed). */
    public function balanceSheet(string $asOf): array
    {
        $this->books->setup();
        $sums = $this->sums(null, $asOf);
        $sections = ['asset' => [], 'liability' => [], 'equity' => []];
        $totals = ['asset' => '0.00', 'liability' => '0.00', 'equity' => '0.00'];
        $profit = '0.00';

        foreach (Account::orderBy('code')->get() as $a) {
            $s = $sums[$a->id] ?? null;
            if (! $s) {
                continue;
            }
            if (in_array($a->nature, ['income', 'expense'], true)) {
                $profit = Money::add($profit, Money::sub($s['cr'], $s['dr']));
                continue;
            }
            $amt = $a->nature === 'asset' ? Money::sub($s['dr'], $s['cr']) : Money::sub($s['cr'], $s['dr']);
            $sections[$a->nature][] = ['code' => $a->code, 'name' => $a->name, 'group' => $a->group, 'amount' => $amt,
                'fine' => Weight::sub($s['fdr'], $s['fcr'])];
            $totals[$a->nature] = Money::add($totals[$a->nature], $amt);
        }

        $sections['equity'][] = ['code' => '—', 'name' => 'Profit & loss to date', 'group' => 'Reserves', 'amount' => $profit, 'fine' => '0.000'];
        $totals['equity'] = Money::add($totals['equity'], $profit);

        return ['as_of' => $asOf, 'sections' => $sections, 'totals' => $totals,
            'balanced' => Money::compare($totals['asset'], Money::add($totals['liability'], $totals['equity'])) === 0];
    }

    /** Party ledger with running money and metal balances. Positive = party owes us. */
    public function partyLedger(Party $party, string $from, ?string $to = null): array
    {
        $opening = $this->books->partyBalance($party, date('Y-m-d', strtotime($from.' -1 day')));

        $entries = VoucherEntry::with('voucher:id,voucher_no,type,date,narration,status')
            ->where('party_id', $party->id)
            ->whereHas('voucher', fn ($q) => $q->where('date', '>=', $from)->when($to, fn ($q) => $q->where('date', '<=', $to)))
            ->get()
            ->sortBy(fn ($e) => [$e->voucher->date, $e->voucher_id, $e->id])
            ->values();

        $money = $opening['money'];
        $fine = $opening['fine'];
        $rows = [];
        foreach ($entries as $e) {
            $money = Money::add($money, Money::sub($e->debit, $e->credit));
            $fine = Weight::add($fine, Weight::sub($e->fine_dr, $e->fine_cr));
            $rows[] = [
                'date' => $e->voucher->date, 'voucher_id' => $e->voucher_id, 'voucher_no' => $e->voucher->voucher_no,
                'type' => $e->voucher->type, 'narration' => $e->voucher->narration, 'cancelled' => $e->voucher->status === 'cancelled',
                'debit' => $e->debit, 'credit' => $e->credit, 'fine_dr' => $e->fine_dr, 'fine_cr' => $e->fine_cr,
                'balance' => $money, 'fine_balance' => $fine,
            ];
        }

        $result = ['party' => $party->only(['id', 'name', 'type', 'gstin', 'phone']), 'from' => $from, 'to' => $to,
            'opening' => $opening, 'closing' => ['money' => $money, 'fine' => $fine], 'rows' => $rows];

        // Kaarigars' gold is tracked in the gold ledger (Module 1); show it alongside.
        if ($party->type === 'kaarigar' && ($k = \App\Models\Kaarigar::find($party->ref_id))) {
            $result['gold_in_hand'] = $this->ledger->balance($this->ledger->kaarigar($k))['fine'];
        }

        return $result;
    }

    /**
     * Outstanding by party with ageing (FIFO: receipts settle the oldest bills
     * first). For retailers: money they owe us; for others: what we owe them.
     */
    public function outstanding(string $type, string $asOf): array
    {
        $parties = Party::where('type', $type)->orderBy('name')->get();
        $rows = [];
        $owedToUs = $type === 'retailer';

        foreach ($parties as $party) {
            $bal = $this->books->partyBalance($party, $asOf);
            $amount = $owedToUs ? $bal['money'] : Money::sub('0', $bal['money']);
            if (Money::compare($amount, '0') === 0 && Weight::compare($bal['fine'], '0') === 0) {
                continue;
            }

            // Bills that increase what is owed, newest first; the outstanding amount sits in the newest ones.
            $bills = VoucherEntry::with('voucher:id,date')
                ->where('party_id', $party->id)
                ->where($owedToUs ? 'debit' : 'credit', '>', 0)
                ->whereHas('voucher', fn ($q) => $q->where('date', '<=', $asOf))
                ->get()
                ->sortByDesc(fn ($e) => [$e->voucher->date, $e->id]);

            $buckets = ['0_30' => '0.00', '31_60' => '0.00', '61_90' => '0.00', '90_plus' => '0.00'];
            $left = Money::compare($amount, '0') > 0 ? $amount : '0.00';
            foreach ($bills as $b) {
                if (Money::isZero($left)) {
                    break;
                }
                $billAmt = $owedToUs ? $b->debit : $b->credit;
                $take = Money::compare($billAmt, $left) < 0 ? $billAmt : $left;
                $days = (int) floor((strtotime($asOf) - strtotime($b->voucher->date)) / 86400);
                $key = $days <= 30 ? '0_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : '90_plus'));
                $buckets[$key] = Money::add($buckets[$key], $take);
                $left = Money::sub($left, $take);
            }

            $rows[] = ['party_id' => $party->id, 'name' => $party->name, 'amount' => $amount, 'fine' => $bal['fine'], 'ageing' => $buckets];
        }

        return ['type' => $type, 'as_of' => $asOf, 'rows' => $rows,
            'total' => Money::add('0', ...array_column($rows, 'amount'))];
    }

    /** Cash or bank book: every movement through the account with a running balance. */
    public function cashBook(string $systemKey, string $from, string $to): array
    {
        $account = $this->books->account($systemKey);
        $openingSums = $this->sums(null, date('Y-m-d', strtotime($from.' -1 day')), fn ($q) => $q->where('account_id', $account->id))[$account->id] ?? ['dr' => '0', 'cr' => '0'];
        $balance = Money::sub($openingSums['dr'], $openingSums['cr']);
        $opening = $balance;

        $entries = VoucherEntry::with(['voucher:id,voucher_no,type,date,narration,party_id', 'voucher.party:id,name'])
            ->where('account_id', $account->id)
            ->whereHas('voucher', fn ($q) => $q->whereBetween('date', [$from, $to]))
            ->get()->sortBy(fn ($e) => [$e->voucher->date, $e->voucher_id])->values();

        $rows = [];
        foreach ($entries as $e) {
            $balance = Money::add($balance, Money::sub($e->debit, $e->credit));
            $rows[] = ['date' => $e->voucher->date, 'voucher_no' => $e->voucher->voucher_no, 'type' => $e->voucher->type,
                'party' => $e->voucher->party?->name, 'narration' => $e->voucher->narration,
                'receipt' => $e->debit, 'payment' => $e->credit, 'balance' => $balance];
        }

        return ['account' => $account->only(['id', 'name']), 'from' => $from, 'to' => $to, 'opening' => $opening, 'closing' => $balance, 'rows' => $rows];
    }

    /** GSTR-1 summary: B2B invoices, B2C totals by place of supply and rate, HSN summary. */
    public function gstr1(string $from, string $to): array
    {
        $invoices = Voucher::with(['party', 'invoiceLines'])
            ->where('type', Voucher::SALES)->where('status', 'posted')
            ->whereBetween('date', [$from, $to])->orderBy('date')->get();

        $b2b = $b2c = $hsn = [];
        foreach ($invoices as $v) {
            $t = $v->meta['totals'] ?? [];
            $row = [
                'invoice_no' => $v->voucher_no, 'date' => $v->date, 'party' => $v->party?->name, 'gstin' => $v->party?->gstin,
                'place_of_supply' => $v->party?->state_code ?? $this->context->get()->state_code,
                'taxable' => $t['taxable'] ?? '0', 'cgst' => $t['cgst'] ?? '0', 'sgst' => $t['sgst'] ?? '0', 'igst' => $t['igst'] ?? '0',
                'total' => $t['total'] ?? $v->amount,
            ];

            if ($v->party?->gstin) {
                $b2b[] = $row;
            } else {
                $key = $row['place_of_supply'].'|'.($v->invoiceLines->first()?->gst_rate ?? '0');
                $b2c[$key] ??= ['place_of_supply' => $row['place_of_supply'], 'rate' => $v->invoiceLines->first()?->gst_rate, 'taxable' => '0', 'tax' => '0'];
                $b2c[$key]['taxable'] = Money::add($b2c[$key]['taxable'], $row['taxable']);
                $b2c[$key]['tax'] = Money::add($b2c[$key]['tax'], $row['cgst'], $row['sgst'], $row['igst']);
            }

            foreach ($v->invoiceLines as $l) {
                $hsn[$l->hsn] ??= ['hsn' => $l->hsn, 'qty' => 0, 'net_wt' => '0.000', 'taxable' => '0', 'cgst' => '0', 'sgst' => '0', 'igst' => '0'];
                $hsn[$l->hsn]['qty']++;
                $hsn[$l->hsn]['net_wt'] = Weight::add($hsn[$l->hsn]['net_wt'], $l->net_wt);
                foreach (['taxable', 'cgst', 'sgst', 'igst'] as $k) {
                    $hsn[$l->hsn][$k] = Money::add($hsn[$l->hsn][$k], $l->{$k});
                }
            }
        }

        return ['from' => $from, 'to' => $to, 'gstin' => $this->context->get()->gstin, 'b2b' => $b2b, 'b2cs' => array_values($b2c), 'hsn' => array_values($hsn)];
    }

    /** GSTR-3B summary: outward tax, input tax credit from purchases, net payable. */
    public function gstr3b(string $from, string $to): array
    {
        $sums = $this->sums($from, $to);
        $byKey = Account::whereNotNull('system_key')->pluck('id', 'system_key');
        $cr = fn ($k) => isset($sums[$byKey[$k] ?? 0]) ? Money::sub($sums[$byKey[$k]]['cr'], $sums[$byKey[$k]]['dr']) : '0.00';
        $dr = fn ($k) => isset($sums[$byKey[$k] ?? 0]) ? Money::sub($sums[$byKey[$k]]['dr'], $sums[$byKey[$k]]['cr']) : '0.00';

        $outward = ['cgst' => $cr('output_cgst'), 'sgst' => $cr('output_sgst'), 'igst' => $cr('output_igst')];
        $itc = ['cgst' => $dr('input_cgst'), 'sgst' => $dr('input_sgst'), 'igst' => $dr('input_igst')];
        $taxable = Voucher::where('type', Voucher::SALES)->where('status', 'posted')->whereBetween('date', [$from, $to])->get()
            ->reduce(fn ($s, $v) => Money::add($s, $v->meta['totals']['taxable'] ?? '0'), '0.00');

        return [
            'from' => $from, 'to' => $to, 'outward_taxable' => $taxable, 'outward_tax' => $outward, 'itc' => $itc,
            'net_payable' => array_map(fn ($k) => Money::sub($outward[$k], $itc[$k]), ['cgst' => 'cgst', 'sgst' => 'sgst', 'igst' => 'igst']),
        ];
    }

    /** Gold stock value: physical balances (Module 1) × today's rate per karat. */
    public function stockValuation(string $asOf): array
    {
        $rates = GoldRateController::effectiveRates($asOf)->pluck('rate_per_g', 'karat_id');
        $karats = Karat::pluck('label', 'id');
        $locations = MetalLocation::whereIn('type', MetalLocation::HELD)->get()->keyBy('id');
        $byType = [];

        foreach ($this->ledger->balanceRows($locations->keys()->all(), $asOf) as $r) {
            if (Weight::compare($r['gross'], '0') === 0 && Weight::compare($r['fine'], '0') === 0) {
                continue;
            }
            $type = $locations[$r['location_id']]->type;
            $rate = $rates[$r['karat_id']] ?? null;
            $key = $type.'|'.$r['karat_id'];
            $byType[$key] ??= ['location' => $type, 'karat' => $karats[$r['karat_id']] ?? '?', 'gross' => '0.000', 'fine' => '0.000', 'rate' => $rate ? Money::round((string) $rate) : null, 'value' => '0.00'];
            $byType[$key]['gross'] = Weight::add($byType[$key]['gross'], $r['gross']);
            $byType[$key]['fine'] = Weight::add($byType[$key]['fine'], $r['fine']);
            $byType[$key]['value'] = $rate ? Money::mul($byType[$key]['gross'], (string) $rate) : '0.00';
        }

        $rows = array_values($byType);

        return ['as_of' => $asOf, 'rows' => $rows, 'total_value' => Money::add('0', ...array_column($rows, 'value')),
            'missing_rates' => collect($rows)->whereNull('rate')->pluck('karat')->unique()->values()];
    }

    /** Tally-importable XML (vouchers with ledger entries). Party ledgers use the party name. */
    public function tallyXml(string $from, string $to): string
    {
        $tenant = $this->context->get();
        $vouchers = Voucher::with(['entries.account', 'entries.party'])->where('status', 'posted')
            ->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('id')->get();

        $typeMap = [
            'sales' => 'Sales', 'purchase' => 'Purchase', 'receipt' => 'Receipt', 'payment' => 'Payment',
            'contra' => 'Contra', 'credit_note' => 'Credit Note', 'debit_note' => 'Debit Note',
        ];
        $x = fn ($s) => htmlspecialchars((string) $s, ENT_XML1);

        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n<ENVELOPE>\n<HEADER><TALLYREQUEST>Import Data</TALLYREQUEST></HEADER>\n<BODY><IMPORTDATA>\n"
            .'<REQUESTDESC><REPORTNAME>Vouchers</REPORTNAME><STATICVARIABLES><SVCURRENTCOMPANY>'.$x($tenant->name)."</SVCURRENTCOMPANY></STATICVARIABLES></REQUESTDESC>\n<REQUESTDATA>\n";

        foreach ($vouchers as $v) {
            $moneyLines = $v->entries->filter(fn ($e) => ! Money::isZero($e->debit) || ! Money::isZero($e->credit));
            if ($moneyLines->isEmpty()) {
                continue; // metal-only vouchers have no Tally equivalent
            }
            $vt = $typeMap[$v->type] ?? 'Journal';
            $out .= '<TALLYMESSAGE xmlns:UDF="TallyUDF"><VOUCHER VCHTYPE="'.$x($vt).'" ACTION="Create">'
                .'<DATE>'.str_replace('-', '', $v->date).'</DATE><VOUCHERTYPENAME>'.$x($vt).'</VOUCHERTYPENAME>'
                .'<VOUCHERNUMBER>'.$x($v->voucher_no).'</VOUCHERNUMBER><NARRATION>'.$x($v->narration).'</NARRATION>';
            foreach ($moneyLines as $e) {
                $ledger = $e->party?->name ?? $e->account->name;
                $isDr = ! Money::isZero($e->debit);
                // Tally: debit amounts negative, ISDEEMEDPOSITIVE Yes.
                $amount = $isDr ? '-'.$e->debit : $e->credit;
                $out .= '<ALLLEDGERENTRIES.LIST><LEDGERNAME>'.$x($ledger).'</LEDGERNAME><ISDEEMEDPOSITIVE>'.($isDr ? 'Yes' : 'No')
                    .'</ISDEEMEDPOSITIVE><AMOUNT>'.$amount.'</AMOUNT></ALLLEDGERENTRIES.LIST>';
            }
            $out .= "</VOUCHER></TALLYMESSAGE>\n";
        }

        return $out."</REQUESTDATA>\n</IMPORTDATA></BODY>\n</ENVELOPE>\n";
    }
}
