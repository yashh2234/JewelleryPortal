<?php

namespace App\Http\Controllers;

use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** In-app notifications (the bell) for whoever is signed in: staff, retailer or kaarigar. */
class NotificationController extends Controller
{
    /** The guard comes from the route (staff / retailer / kaarigar area), never guessed. */
    private function query(Request $request)
    {
        $guard = $request->route('guard');
        $user = in_array($guard, ['staff', 'retailer', 'kaarigar'], true) ? $request->user($guard) : null;
        abort_unless($user, 401);

        return DB::table('app_notifications')
            ->where('tenant_id', app(TenantContext::class)->id())
            ->where('user_type', $guard)->where('user_id', $user->id);
    }

    public function index(Request $request): JsonResponse
    {
        $items = $this->query($request)
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('id')->limit(min(100, max(1, $request->integer('limit', 30))))
            ->get(['id', 'event', 'title', 'body', 'url', 'read_at', 'created_at']);

        return response()->json(['data' => $items, 'unread' => $this->query($request)->whereNull('read_at')->count()]);
    }

    public function read(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['integer']]);

        $this->query($request)->whereNull('read_at')
            ->when($request->filled('ids'), fn ($q) => $q->whereIn('id', $request->input('ids')))
            ->update(['read_at' => now()]);

        return response()->json(['unread' => $this->query($request)->whereNull('read_at')->count()]);
    }
}
