<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Retailer;
use App\Support\Tenancy\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/** Login/logout live in Auth\SessionController (one login for everyone). */
class AuthController extends Controller
{
    /** Self-signup. Creates a pending retailer that the manufacturer approves. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shop_name' => ['required', 'string', 'max:150'],
            'contact_name' => ['required', 'string', 'max:100'],
            'gstin' => ['nullable', 'string', 'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'city' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:150', TenantRules::unique('retailer_users', 'email')],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $retailer = DB::transaction(function () use ($data) {
            $retailer = Retailer::create([
                'shop_name' => $data['shop_name'],
                'contact_name' => $data['contact_name'],
                'gstin' => $data['gstin'] ?? null,
                'state_code' => isset($data['gstin']) ? substr($data['gstin'], 0, 2) : null,
                'city' => $data['city'],
                'phone' => $data['phone'],
                'email' => strtolower($data['email']),
                'status' => Retailer::STATUS_PENDING,
            ]);

            $retailer->users()->create([
                'name' => $data['contact_name'],
                'email' => strtolower($data['email']),
                'phone' => $data['phone'],
                'password' => $data['password'],
                'role' => 'owner',
            ]);

            return $retailer;
        });

        return response()->json([
            'message' => 'Thanks! Your account will be active once the manufacturer approves it.',
            'retailer' => $retailer->only(['id', 'shop_name', 'status']),
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user('retailer')->toAuthPayload()]);
    }
}
