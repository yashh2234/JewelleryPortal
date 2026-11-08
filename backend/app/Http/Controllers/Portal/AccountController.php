<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Dispatch;
use App\Models\Retailer;
use App\Models\RetailerUser;
use App\Models\Voucher;
use App\Services\Accounting\Books;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRules;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Retailer's account: money and metal balance, invoices and challans, their own team. */
class AccountController extends Controller
{
    public function __construct(private Books $books) {}

    private function retailer(Request $request): Retailer
    {
        return $request->user('retailer')->retailer;
    }

    public function show(Request $request): JsonResponse
    {
        $retailer = $this->retailer($request);
        $party = $this->books->party($retailer);
        $balance = $this->books->partyBalance($party);

        $invoices = Voucher::where('party_id', $party->id)->where('type', Voucher::SALES)->latest('date')->limit(50)
            ->get(['id', 'voucher_no', 'date', 'amount', 'fine_wt', 'status', 'meta'])
            ->map(fn ($v) => $v->only(['id', 'voucher_no', 'date', 'amount', 'fine_wt', 'status']) + ['payable' => $v->meta['totals']['payable'] ?? $v->amount]);

        $payments = Voucher::where('party_id', $party->id)->whereIn('type', [Voucher::RECEIPT, Voucher::METAL_RECEIPT])->where('status', 'posted')
            ->latest('date')->limit(20)->get(['id', 'voucher_no', 'type', 'date', 'amount', 'fine_wt']);

        return response()->json([
            'retailer' => $retailer->only(['shop_name', 'gstin', 'city', 'credit_limit', 'metal_account_enabled']),
            // From the retailer's side: positive outstanding = they owe; positive metal = gold they hold with us.
            'outstanding' => $balance['money'],
            'metal_balance' => \App\Support\Metal\Weight::negate($balance['fine']),
            'credit_available' => Money::compare($retailer->credit_limit, '0') > 0 ? Money::sub($retailer->credit_limit, Money::compare($balance['money'], '0') > 0 ? $balance['money'] : '0') : null,
            'invoices' => $invoices,
            'payments' => $payments,
            'challans' => Dispatch::where('retailer_id', $retailer->id)->latest('date')->limit(50)->get(['id', 'challan_no', 'date', 'pieces', 'net_wt', 'invoice_voucher_id']),
        ]);
    }

    public function invoicePdf(Request $request, Voucher $voucher, TenantContext $context): Response
    {
        $party = $this->books->party($this->retailer($request));
        abort_unless($voucher->type === Voucher::SALES && $voucher->party_id === $party->id, 404);

        return Pdf::loadView('pdf.invoice', ['v' => $voucher->load(['party', 'invoiceLines']), 'tenant' => $context->get()])
            ->stream(str_replace('/', '-', $voucher->voucher_no).'.pdf');
    }

    public function challanPdf(Request $request, Dispatch $dispatch, TenantContext $context): Response
    {
        abort_unless($dispatch->retailer_id === $this->retailer($request)->id, 404);

        return Pdf::loadView('pdf.challan', ['d' => $dispatch->load(['retailer', 'order', 'lines.item.design', 'lines.item.karat']), 'tenant' => $context->get()])
            ->stream(str_replace('/', '-', $dispatch->challan_no).'.pdf');
    }

    // ── Team: the shop owner adds staff logins ──────────────────────────────

    public function team(Request $request): JsonResponse
    {
        return response()->json(['data' => RetailerUser::where('retailer_id', $this->retailer($request)->id)
            ->orderBy('name')->get(['id', 'name', 'email', 'phone', 'role', 'is_active'])]);
    }

    public function addUser(Request $request): JsonResponse
    {
        $me = $request->user('retailer');
        abort_unless($me->role === 'owner', 403, 'Only the shop owner can add users.');

        $d = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', TenantRules::unique('retailer_users', 'email')],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', Password::min(8)],
            'role' => ['nullable', Rule::in(['owner', 'staff'])],
        ]);

        $user = RetailerUser::create(['retailer_id' => $me->retailer_id, 'email' => strtolower($d['email']), 'role' => $d['role'] ?? 'staff'] + collect($d)->only(['name', 'phone', 'password'])->all());

        return response()->json(['data' => $user->only(['id', 'name', 'email', 'role'])], 201);
    }

    public function updateUser(Request $request, RetailerUser $user): JsonResponse
    {
        $me = $request->user('retailer');
        abort_unless($me->role === 'owner' && $user->retailer_id === $me->retailer_id, 403);
        abort_if($user->id === $me->id, 422, 'You cannot deactivate yourself.');

        $user->update($request->validate(['is_active' => ['required', 'boolean']]));

        return response()->json(['data' => $user->only(['id', 'name', 'is_active'])]);
    }
}
