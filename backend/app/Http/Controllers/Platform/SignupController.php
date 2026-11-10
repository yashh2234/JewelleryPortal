<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Manufacturer self-signup on the central domain: creates a tenant on a 14-day trial. */
class SignupController extends Controller
{
    public function store(Request $request, TenantProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'subdomain' => [
                'required', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/',
                Rule::notIn(TenantProvisioner::RESERVED_SUBDOMAINS),
                Rule::unique('tenants', 'subdomain'),
            ],
            'gstin' => ['nullable', 'string', 'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'phone' => ['nullable', 'string', 'max:20'],
            'owner_name' => ['required', 'string', 'max:100'],
            'owner_email' => ['required', 'email', 'max:150'],
            'owner_phone' => ['nullable', 'string', 'max:20'],
            'owner_password' => ['required', Password::min(8)],
        ]);

        $tenant = $provisioner->provision($data);

        $central = config('tenancy.central_domain');
        $scheme = $request->getScheme();

        return response()->json([
            'tenant' => $tenant->only(['id', 'name', 'subdomain', 'status', 'trial_ends_at']),
            'app_url' => "{$scheme}://{$tenant->subdomain}.{$central}/",
        ], 201);
    }
}
