<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\RetailerQuery;
use App\Services\Push\Notify;
use App\Support\NumberSeries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The retailer raises queries / customisation requests / complaints and follows the replies. */
class QueryController extends Controller
{
    private function own(Request $request, RetailerQuery $query): void
    {
        abort_unless($query->retailer_id === $request->user('retailer')->retailer_id, 404);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(RetailerQuery::where('retailer_id', $request->user('retailer')->retailer_id)
            ->with('order:id,order_no')->withCount('messages')->latest('id')->paginate(30));
    }

    public function show(Request $request, RetailerQuery $query): JsonResponse
    {
        $this->own($request, $query);

        return response()->json(['data' => $query->load(['order:id,order_no', 'messages'])]);
    }

    public function store(Request $request, Notify $notify): JsonResponse
    {
        $user = $request->user('retailer');
        $data = $request->validate([
            'type' => ['required', Rule::in(RetailerQuery::TYPES)],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:4000'],
            'order_id' => ['nullable', 'integer'],
        ]);

        if (isset($data['order_id'])) {
            abort_unless(Order::whereKey($data['order_id'])->where('retailer_id', $user->retailer_id)->exists(), 422, 'That order is not yours.');
        }

        $query = DB::transaction(function () use ($data, $user) {
            $q = RetailerQuery::create([
                'ticket_no' => NumberSeries::next('TKT'),
                'retailer_id' => $user->retailer_id, 'retailer_user_id' => $user->id,
                'order_id' => $data['order_id'] ?? null, 'type' => $data['type'], 'subject' => $data['subject'],
            ]);
            $q->messages()->create(['author_type' => 'retailer', 'author_id' => $user->id, 'author_name' => $user->name, 'body' => $data['body']]);

            return $q;
        });

        $shop = $user->retailer->shop_name;
        $notify->staffWith('retailers.manage', "{$shop}: ".ucfirst($data['type']), $data['subject'], "/staff/queries/{$query->id}", 'query.new');
        $notify->staffUser($user->retailer->salesperson_id, "{$shop}: ".ucfirst($data['type']), $data['subject'], "/staff/queries/{$query->id}", 'query.new');

        return response()->json(['data' => $query->load('messages')], 201);
    }

    public function reply(Request $request, RetailerQuery $query, Notify $notify): JsonResponse
    {
        $this->own($request, $query);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $user = $request->user('retailer');

        $query->messages()->create(['author_type' => 'retailer', 'author_id' => $user->id, 'author_name' => $user->name, 'body' => $data['body']]);
        $query->update(['status' => 'open']);
        $notify->staffWith('retailers.manage', "Reply on {$query->ticket_no}", mb_substr($data['body'], 0, 140), "/staff/queries/{$query->id}", 'query.reply');

        return response()->json(['data' => $query->load('messages')]);
    }
}
