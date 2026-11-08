<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a trial has ended, the workspace stays readable (nobody loses sight of their
 * gold) but writes are refused with 402 until the plan is activated.
 */
class EnsureWorkspaceWritable
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && $this->context->get()?->isTrialExpired()) {
            return response()->json([
                'message' => 'Your free trial has ended. The workspace is read-only until the plan is activated.',
                'code' => 'trial_expired',
            ], 402);
        }

        return $next($request);
    }
}
