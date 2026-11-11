<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Super-admin (you): see every workspace, change plan/status, extend trials. */
class AdminController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        if (! Auth::guard('platform')->attempt(['email' => strtolower($data['email']), 'password' => $data['password']])) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();

        return response()->json(['admin' => Auth::guard('platform')->user()->only(['id', 'name', 'email'])]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['admin' => $request->user('platform')->only(['id', 'name', 'email'])]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function tenants(Request $request): JsonResponse
    {
        // Counting tenant-owned rows across all tenants needs the scope off.
        $tenants = $this->context->runCentral(fn () => Tenant::query()
            ->with('plan:id,code,name')
            ->withCount(['users', 'retailers'])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('subdomain', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 50)));

        return response()->json($tenants);
    }

    public function plans(): JsonResponse
    {
        return response()->json(['data' => Plan::orderBy('price')->get()]);
    }

    public function updateTenant(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in([Tenant::STATUS_TRIAL, Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED, Tenant::STATUS_CANCELLED])],
            'plan_id' => ['sometimes', 'exists:plans,id'],
            'trial_ends_at' => ['sometimes', 'nullable', 'date'],
        ]);

        DB::transaction(function () use ($tenant, $data) {
            $tenant->update($data);

            if (isset($data['plan_id']) || isset($data['status'])) {
                Subscription::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'cancelled_at' => null],
                    [
                        'plan_id' => $tenant->plan_id,
                        'status' => match ($tenant->status) {
                            Tenant::STATUS_ACTIVE => 'active',
                            Tenant::STATUS_TRIAL => 'trialing',
                            default => 'cancelled',
                        },
                        'current_period_start' => now()->toDateString(),
                    ],
                );
            }
        });

        return response()->json(['data' => $tenant->fresh('plan')]);
    }
}
