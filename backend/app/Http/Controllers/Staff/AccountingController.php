<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Dispatch;
use App\Models\FinancialYear;
use App\Models\LabourRate;
use App\Models\Party;
use App\Models\TaxSetting;
use App\Models\Voucher;
use App\Services\Accounting\Books;
use App\Services\Accounting\EInvoice;
use App\Services\Accounting\Invoicing;
use App\Services\Accounting\Reports;
use App\Services\Accounting\Vouchers;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRules;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Module 4 endpoints. Posting rules live in the Accounting services. */
class AccountingController extends Controller
{
    public function __construct(
        private Books $books,
        private Vouchers $vouchers,
        private Invoicing $invoicing,
        private Reports $reports,
    ) {}

    // ── Masters ─────────────────────────────────────────────────────────────

    public function accounts(): JsonResponse
    {
        $this->books->setup();

        return response()->json(['data' => Account::orderBy('code')->get()]);
    }

    public function saveAccount(Request $request, ?Account $account = null): JsonResponse
    {
        $data = $request->validate([
            'code' => [$account ? 'sometimes' : 'required', 'string', 'max:10', TenantRules::unique('accounts', 'code')->ignore($account?->id)],
            'name' => [$account ? 'sometimes' : 'required', 'string', 'max:150'],
            'nature' => [$account ? 'prohibited' : 'required', Rule::in(Account::NATURES)],
            'group' => [$account ? 'sometimes' : 'required', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($account) {
            $account->update($data);
        } else {
            $account = Account::create($data);
        }

        return response()->json(['data' => $account], $request->isMethod('post') ? 201 : 200);
    }

    public function parties(Request $request): JsonResponse
    {
        $parties = Party::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 100));

        $parties->getCollection()->transform(fn (Party $p) => $p->toArray() + ['balance' => $this->books->partyBalance($p)]);

        return response()->json($parties);
    }

    /** Suppliers, refiners and others are created here; retailers, kaarigars and AHCs appear automatically. */
    public function saveParty(Request $request, ?Party $party = null): JsonResponse
    {
        $data = $request->validate([
            'type' => [$party ? 'prohibited' : 'required', Rule::in(['supplier', 'refiner', 'other'])],
            'name' => [$party ? 'sometimes' : 'required', 'string', 'max:150'],
            'gstin' => ['nullable', 'string', 'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);
        if (array_key_exists('gstin', $data)) {
            $data['state_code'] = $data['gstin'] ? substr($data['gstin'], 0, 2) : null;
        }

        if ($party) {
            $party->update($data);
        } else {
            $party = Party::create($data);
        }

        return response()->json(['data' => $party], $request->isMethod('post') ? 201 : 200);
    }

    public function partyLedger(Request $request, Party $party): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return response()->json($this->reports->partyLedger($party, $data['from'] ?? $this->fyStart(), $data['to'] ?? null));
    }

    public function taxSettings(): JsonResponse
    {
        $this->books->setup();

        return response()->json(['data' => TaxSetting::orderBy('hsn')->orderByDesc('effective_from')->get()]);
    }

    public function saveTaxSetting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hsn' => ['required', 'string', 'max:10'],
            'description' => ['required', 'string', 'max:255'],
            'gst_rate' => ['required', 'decimal:0,2', 'min:0', 'max:28'],
            'effective_from' => ['required', 'date'],
        ]);

        return response()->json(['data' => TaxSetting::create($data)], 201);
    }

    public function labourRates(): JsonResponse
    {
        return response()->json(['data' => LabourRate::with(['process:id,name', 'kaarigar:id,name'])->get()]);
    }

    public function saveLabourRate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'process_id' => ['required', TenantRules::exists('processes')],
            'kaarigar_id' => ['nullable', TenantRules::exists('kaarigars')],
            'basis' => ['required', Rule::in(['per_gram', 'per_piece'])],
            'rate' => ['required', 'decimal:0,2', 'min:0'],
        ]);

        $rate = LabourRate::updateOrCreate(
            ['process_id' => $data['process_id'], 'kaarigar_id' => $data['kaarigar_id'] ?? null],
            ['basis' => $data['basis'], 'rate' => $data['rate']],
        );

        return response()->json(['data' => $rate->load(['process:id,name', 'kaarigar:id,name'])], 201);
    }

    public function deleteLabourRate(LabourRate $labourRate): JsonResponse
    {
        $labourRate->delete();

        return response()->json(['ok' => true]);
    }

    public function financialYears(): JsonResponse
    {
        $this->books->financialYear(today()->toDateString());

        return response()->json(['data' => FinancialYear::orderByDesc('start_date')->get()]);
    }

    /** Period lock: no voucher can be posted or cancelled on or before this date. */
    public function lock(Request $request, FinancialYear $financialYear): JsonResponse
    {
        $data = $request->validate(['locked_until' => ['nullable', 'date', 'before_or_equal:today']]);
        $financialYear->forceFill(['locked_until' => $data['locked_until'] ?? null])->save();

        return response()->json(['data' => $financialYear]);
    }

    // ── Vouchers ────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $rows = Voucher::with('party:id,name,type')
            ->when($request->filled('type'), fn ($q) => $q->whereIn('type', explode(',', $request->string('type'))))
            ->when($request->filled('party_id'), fn ($q) => $q->where('party_id', $request->integer('party_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('date', '>=', $request->date('from')->toDateString()))
            ->when($request->filled('to'), fn ($q) => $q->where('date', '<=', $request->date('to')->toDateString()))
            ->when($request->filled('search'), fn ($q) => $q->where('voucher_no', 'like', '%'.$request->string('search').'%'))
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate($request->integer('per_page', 50));

        return response()->json($rows);
    }

    public function show(Voucher $voucher): JsonResponse
    {
        return response()->json(['data' => $voucher->load(['party', 'entries.account:id,code,name', 'entries.party:id,name', 'invoiceLines', 'creator:id,name'])]);
    }

    public function cancel(Request $request, Voucher $voucher): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];
        $voucher->load(['entries.account', 'entries.party', 'party']);

        if ($voucher->source_type) {
            abort(422, "This voucher was posted automatically from {$voucher->source_type}; reverse it at the source.");
        }

        return response()->json(['data' => $this->books->cancel($voucher, $reason)], 201);
    }

    public function receipt(Request $request): JsonResponse
    {
        $d = $this->moneyRules($request);

        return $this->made($this->vouchers->receipt(Party::findOrFail($d['party_id']), $d['amount'], $d['mode'], $d['date'] ?? today()->toDateString(), $d['narration'] ?? null));
    }

    public function payment(Request $request): JsonResponse
    {
        $d = $this->moneyRules($request);

        return $this->made($this->vouchers->payment(Party::findOrFail($d['party_id']), $d['amount'], $d['mode'], $d['date'] ?? today()->toDateString(), $d['narration'] ?? null));
    }

    public function metal(Request $request, string $direction): JsonResponse
    {
        $d = $request->validate([
            'party_id' => ['required', TenantRules::exists('parties')],
            'fine' => ['required', 'decimal:0,3', 'gt:0'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);
        $party = Party::findOrFail($d['party_id']);
        $date = $d['date'] ?? today()->toDateString();

        return $this->made($direction === 'receipt'
            ? $this->vouchers->metalReceipt($party, (string) $d['fine'], $date, $d['narration'] ?? null)
            : $this->vouchers->metalPayment($party, (string) $d['fine'], $date, $d['narration'] ?? null));
    }

    public function purchase(Request $request, TenantContext $context): JsonResponse
    {
        $d = $request->validate([
            'party_id' => ['required', TenantRules::exists('parties')],
            'terms' => ['required', Rule::in(['rate', 'metal'])],
            'fine' => ['nullable', 'decimal:0,3', 'min:0'],
            'taxable' => ['required', 'decimal:0,2', 'min:0'],
            'gst_rate' => ['nullable', 'decimal:0,2', 'min:0', 'max:28'],
            'bill_no' => ['nullable', 'string', 'max:50'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);
        $party = Party::findOrFail($d['party_id']);
        $tenantState = $context->get()->state_code;
        $inter = $tenantState && $party->state_code && $tenantState !== $party->state_code;

        return $this->made($this->vouchers->purchase(
            $party, $d['date'] ?? today()->toDateString(), $d['terms'], (string) ($d['fine'] ?? '0'),
            (string) $d['taxable'], (string) ($d['gst_rate'] ?? '3'), (bool) $inter, $d['narration'] ?? null, $d['bill_no'] ?? null,
        ));
    }

    public function rateCut(Request $request): JsonResponse
    {
        $d = $request->validate([
            'party_id' => ['required', TenantRules::exists('parties')],
            'fine' => ['required', 'decimal:0,3', 'gt:0'],
            'rate' => ['required', 'decimal:0,2', 'gt:0'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->made($this->vouchers->rateCut(Party::findOrFail($d['party_id']), (string) $d['fine'], (string) $d['rate'], $d['date'] ?? today()->toDateString(), $d['narration'] ?? null));
    }

    public function journal(Request $request): JsonResponse
    {
        $d = $request->validate([
            'type' => ['required', Rule::in([Voucher::JOURNAL, Voucher::CONTRA, Voucher::CREDIT_NOTE, Voucher::DEBIT_NOTE])],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account_id' => ['required', TenantRules::exists('accounts')],
            'lines.*.party_id' => ['nullable', TenantRules::exists('parties')],
            'lines.*.debit' => ['nullable', 'decimal:0,2', 'min:0'],
            'lines.*.credit' => ['nullable', 'decimal:0,2', 'min:0'],
            'lines.*.fine_dr' => ['nullable', 'decimal:0,3', 'min:0'],
            'lines.*.fine_cr' => ['nullable', 'decimal:0,3', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->made($this->vouchers->journal($d['type'], $d['date'] ?? today()->toDateString(), $d['lines'], $d['narration'] ?? null));
    }

    // ── Invoices ────────────────────────────────────────────────────────────

    public function invoicePreview(Request $request, Dispatch $dispatch): JsonResponse
    {
        return response()->json($this->invoicing->preview($dispatch, $this->invoiceOpts($request)));
    }

    public function invoiceFromDispatch(Request $request, Dispatch $dispatch): JsonResponse
    {
        return $this->made($this->invoicing->post($dispatch, $this->invoiceOpts($request)));
    }

    public function invoicePdf(Voucher $voucher, TenantContext $context): Response
    {
        abort_unless($voucher->type === Voucher::SALES, 404);
        $voucher->load(['party', 'invoiceLines']);

        return Pdf::loadView('pdf.invoice', ['v' => $voucher, 'tenant' => $context->get()])->stream(str_replace('/', '-', $voucher->voucher_no).'.pdf');
    }

    public function einvoiceJson(Voucher $voucher, EInvoice $einvoice): JsonResponse
    {
        return response()->json($einvoice->payload($voucher))
            ->header('Content-Disposition', 'attachment; filename="'.str_replace('/', '-', $voucher->voucher_no).'-einvoice.json"');
    }

    public function einvoice(Voucher $voucher, EInvoice $einvoice): JsonResponse
    {
        return response()->json(['data' => $einvoice->generate($voucher)]);
    }

    // ── Reports ─────────────────────────────────────────────────────────────

    public function report(Request $request, string $name): JsonResponse|Response
    {
        $d = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'as_of' => ['nullable', 'date'],
            'type' => ['nullable', Rule::in(['retailer', 'supplier', 'kaarigar', 'refiner', 'ahc', 'other'])],
            'account' => ['nullable', Rule::in(['cash', 'bank'])],
            'date' => ['nullable', 'date'],
        ]);
        $from = $d['from'] ?? $this->fyStart();
        $to = $d['to'] ?? today()->toDateString();
        $asOf = $d['as_of'] ?? today()->toDateString();

        return match ($name) {
            'trial-balance' => response()->json($this->reports->trialBalance($asOf)),
            'pnl' => response()->json($this->reports->profitAndLoss($from, $to)),
            'balance-sheet' => response()->json($this->reports->balanceSheet($asOf)),
            'outstanding' => response()->json($this->reports->outstanding($d['type'] ?? 'retailer', $asOf)),
            'cash-book' => response()->json($this->reports->cashBook($d['account'] ?? 'cash', $from, $to)),
            'gstr1' => response()->json($this->reports->gstr1($from, $to)),
            'gstr3b' => response()->json($this->reports->gstr3b($from, $to)),
            'stock-valuation' => response()->json($this->reports->stockValuation($asOf)),
            'daybook' => response()->json(['date' => $d['date'] ?? $to, 'data' => Voucher::with(['party:id,name', 'entries.account:id,name'])
                ->where('date', $d['date'] ?? $to)->orderBy('id')->get()]),
            'tally' => response($this->reports->tallyXml($from, $to), 200, [
                'Content-Type' => 'application/xml',
                'Content-Disposition' => "attachment; filename=\"tally-{$from}-{$to}.xml\"",
            ]),
            default => abort(404),
        };
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function moneyRules(Request $request): array
    {
        return $request->validate([
            'party_id' => ['required', TenantRules::exists('parties')],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'mode' => ['required', Rule::in(['cash', 'bank', 'upi', 'cheque', 'neft'])],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function invoiceOpts(Request $request): array
    {
        $d = $request->validate([
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'terms' => ['nullable', Rule::in(['cash', 'metal'])],
            'rates' => ['nullable', 'array'],
            'rates.*' => ['decimal:0,2', 'gt:0'],
            'stone_charges' => ['nullable', 'array'],
            'stone_charges.*' => ['decimal:0,2', 'min:0'],
            'hallmark_per_piece' => ['nullable', 'decimal:0,2', 'min:0'],
        ]);

        return array_filter($d, fn ($v) => $v !== null);
    }

    private function made(Voucher $v): JsonResponse
    {
        return response()->json(['data' => $v->load(['party:id,name', 'entries.account:id,code,name'])], 201);
    }

    private function fyStart(): string
    {
        return \App\Support\FinancialYear::start()->toDateString();
    }
}
