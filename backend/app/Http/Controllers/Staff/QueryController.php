<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\RetailerQuery;
use App\Services\Push\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Retailer queries, customisation requests and complaints, answered by staff. */
class QueryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(RetailerQuery::with(['retailer:id,shop_name,city', 'order:id,order_no'])
            ->withCount('messages')
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->string('status'))))
            ->when($request->filled('retailer_id'), fn ($q) => $q->where('retailer_id', $request->integer('retailer_id')))
            ->orderByRaw("case when status = 'open' then 0 when status = 'answered' then 1 else 2 end")
            ->latest('id')->paginate(50));
    }

    public function show(RetailerQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->load(['retailer:id,shop_name,city,phone', 'order:id,order_no,status', 'messages'])]);
    }

    public function reply(Request $request, RetailerQuery $query, Notify $notify): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'close' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user('staff');

        $query->messages()->create(['author_type' => 'staff', 'author_id' => $user->id, 'author_name' => $user->name, 'body' => $data['body']]);
        $query->update(['status' => ($data['close'] ?? false) ? 'closed' : 'answered']);

        $notify->retailer($query->retailer_id, "Reply on {$query->ticket_no}", mb_substr($data['body'], 0, 140), "/portal/queries/{$query->id}", 'query.answered', whatsapp: true);

        return response()->json(['data' => $query->load('messages')]);
    }

    public function setStatus(Request $request, RetailerQuery $query): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(RetailerQuery::STATUSES)]]);
        $query->update($data);

        return response()->json(['data' => $query]);
    }
}
