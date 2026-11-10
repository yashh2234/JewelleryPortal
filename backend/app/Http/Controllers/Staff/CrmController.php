<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\CrmNote;
use App\Models\Order;
use App\Models\Retailer;
use App\Models\RetailerQuery;
use App\Models\Voucher;
use App\Services\Accounting\Books;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRM (spec §4): one page per retailer with contact, commercial terms, money and
 * metal position, purchase history, pending orders, repeat buying, salesperson
 * and the communication log.
 */
class CrmController extends Controller
{
    public function __construct(private Books $books) {}

    public function profile(Retailer $retailer): JsonResponse
    {
        $retailer->load(['salesperson:id,name,phone', 'makingSlab:id,name,basis,rate', 'users:id,retailer_id,name,email,phone,role,is_active']);
        $party = $this->books->party($retailer);
        $balance = $this->books->partyBalance($party);

        $orders = Order::where('retailer_id', $retailer->id)->withSum('lines as qty_total', 'qty')->latest('id')->get();
        $sales = Voucher::where('party_id', $party->id)->where('type', Voucher::SALES)->where('status', 'posted');
        $designs = \App\Models\OrderLine::whereIn('order_id', $orders->pluck('id'))->with('design:id,code,name')->get()
            ->groupBy('design_id')->map(fn ($g) => ['design' => $g->first()->design, 'orders' => $g->pluck('order_id')->unique()->count(), 'pieces' => $g->sum('qty')])
            ->sortByDesc('orders')->values();

        $open = [Order::NEW, Order::PENDING_APPROVAL, Order::APPROVED, ...Order::PRODUCTION, Order::PARTIALLY_DISPATCHED, Order::ON_HOLD];

        return response()->json([
            'retailer' => $retailer,
            'position' => [
                'outstanding' => $balance['money'],
                'metal' => $balance['fine'],
                'credit_limit' => $retailer->credit_limit,
                'credit_available' => Money::compare($retailer->credit_limit, '0') > 0 ? Money::sub($retailer->credit_limit, Money::compare($balance['money'], '0') > 0 ? $balance['money'] : '0') : null,
            ],
            'stats' => [
                'orders' => $orders->count(),
                'pending_orders' => $orders->whereIn('status', $open)->count(),
                'pieces' => (int) $orders->sum('qty_total'),
                'sales_total' => Money::round((string) (clone $sales)->sum('amount')),
                'sales_12m' => Money::round((string) (clone $sales)->where('date', '>=', today()->subYear()->toDateString())->sum('amount')),
                'first_order' => $orders->last()?->created_at,
                'last_order' => $orders->first()?->created_at,
                'repeat_buyer' => $orders->count() > 1,
                'avg_days_between_orders' => $orders->count() > 1
                    ? round($orders->first()->created_at->diffInDays($orders->last()->created_at) / ($orders->count() - 1), 1) : null,
            ],
            'orders' => $orders->take(50)->map->only(['id', 'order_no', 'status', 'source', 'due_date', 'created_at', 'qty_total', 'estimated_value', 'payment_status']),
            'repeat_designs' => $designs->filter(fn ($d) => $d['orders'] > 1)->take(10)->values(),
            'notes' => $retailer->notes()->with('user:id,name')->limit(100)->get(),
            'queries' => RetailerQuery::where('retailer_id', $retailer->id)->latest('id')->limit(20)->get(['id', 'ticket_no', 'type', 'subject', 'status', 'created_at', 'order_id']),
        ]);
    }

    public function addNote(Request $request, Retailer $retailer): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(CrmNote::CHANNELS)],
            'body' => ['required', 'string', 'max:2000'],
            'follow_up_on' => ['nullable', 'date'],
        ]);

        $note = $retailer->notes()->create($data + ['user_id' => $request->user('staff')->id]);

        return response()->json(['data' => $note->load('user:id,name')], 201);
    }

    public function deleteNote(Request $request, Retailer $retailer, CrmNote $note): JsonResponse
    {
        abort_unless($note->retailer_id === $retailer->id, 404);
        abort_unless($note->user_id === $request->user('staff')->id || $request->user('staff')->hasRole(\App\Support\Permissions::ROLE_OWNER), 403);
        $note->delete();

        return response()->json(['ok' => true]);
    }

    /** Follow-up done (clears the reminder; the note stays in the history). */
    public function followUpDone(Retailer $retailer, CrmNote $note): JsonResponse
    {
        abort_unless($note->retailer_id === $retailer->id, 404);
        $note->update(['follow_up_on' => null]);

        return response()->json(['data' => $note]);
    }

    /** Follow-ups due (today and overdue) for the signed-in salesperson, or everyone's for owners. */
    public function followUps(Request $request): JsonResponse
    {
        $mine = ! $request->user('staff')->hasRole(\App\Support\Permissions::ROLE_OWNER) || $request->boolean('mine');

        $notes = CrmNote::with(['retailer:id,shop_name,city,phone,salesperson_id', 'user:id,name'])
            ->whereNotNull('follow_up_on')->where('follow_up_on', '<=', today()->addDays(7)->toDateString())
            ->when($mine, fn ($q) => $q->where(fn ($q) => $q->where('user_id', $request->user('staff')->id)
                ->orWhereHas('retailer', fn ($r) => $r->where('salesperson_id', $request->user('staff')->id))))
            ->orderBy('follow_up_on')->limit(100)->get();

        return response()->json(['data' => $notes]);
    }
}
