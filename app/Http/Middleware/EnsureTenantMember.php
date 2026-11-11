<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:{guard}. Defence in depth on top of the tenant scope: the logged-in
 * actor must belong to the resolved tenant and be active (retailers: approved).
 */
class EnsureTenantMember
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $guard): Response
    {
        $user = Auth::guard($guard)->user();

        if (! $user || (int) $user->tenant_id !== $this->context->id()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $inactive = ! $user->is_active
            || ($guard === 'retailer' && ! $user->retailer?->isActive());

        if ($inactive) {
            Auth::guard($guard)->logout();

            return response()->json(['message' => 'Your account is not active.'], 403);
        }

        return $next($request);
    }
}
