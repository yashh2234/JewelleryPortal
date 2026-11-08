<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Save / remove this device's Web Push subscription for whoever is signed in. */
class PushSubscriptionController extends Controller
{
    public function key(): JsonResponse
    {
        return response()->json(['public_key' => config('services.webpush.public_key')]);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        [$type, $user] = $this->who();
        abort_unless($user, 401);

        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'max:20'],
        ]);

        DB::table('push_subscriptions')->updateOrInsert(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'tenant_id' => $context->id(), 'user_type' => $type, 'user_id' => $user->id,
                'endpoint' => $data['endpoint'], 'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['content_encoding'] ?? 'aes128gcm', 'updated_at' => now(), 'created_at' => now(),
            ],
        );

        return response()->json(['ok' => true], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $endpoint = $request->validate(['endpoint' => ['required', 'string']])['endpoint'];
        DB::table('push_subscriptions')->where('endpoint_hash', hash('sha256', $endpoint))->delete();

        return response()->json(['ok' => true]);
    }

    private function who(): array
    {
        foreach (['staff', 'retailer', 'kaarigar'] as $guard) {
            if ($user = Auth::guard($guard)->user()) {
                return [$guard, $user];
            }
        }

        return [null, null];
    }
}
