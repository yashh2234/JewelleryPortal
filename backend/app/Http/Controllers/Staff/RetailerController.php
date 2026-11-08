<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Retailer;
use App\Support\PlanLimits;
use App\Support\Tenancy\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RetailerController extends Controller
{
    private const GSTIN = 'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    public function index(Request $request): JsonResponse
    {
        $retailers = Retailer::query()
            ->with('makingSlab:id,name')
            ->withCount('users')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q
                ->where('shop_name', 'like', '%'.$request->string('search').'%')
                ->orWhere('city', 'like', '%'.$request->string('search').'%')
                ->orWhere('gstin', 'like', '%'.$request->string('search').'%')))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderBy('shop_name')
            ->paginate($request->integer('per_page', 50));

        return response()->json($retailers);
    }

    public function show(Retailer $retailer): JsonResponse
    {
        return response()->json(['data' => $retailer->load(['users', 'makingSlab'])]);
    }

    /** Created by the manufacturer: active straight away, with an owner login. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + [
            'shop_name' => ['required', 'string', 'max:150'],
            'login_name' => ['required', 'string', 'max:100'],
            'login_email' => ['required', 'email', TenantRules::unique('retailer_users', 'email')],
            'login_password' => ['required', Password::min(8)],
        ]);

        PlanLimits::ensureRoomFor('retailers');

        $retailer = DB::transaction(function () use ($data) {
            $retailer = Retailer::create($this->retailerFields($data) + ['status' => Retailer::STATUS_ACTIVE]);
            $retailer->forceFill(['approved_by' => Auth::guard('staff')->id(), 'approved_at' => now()])->save();

            $retailer->users()->create([
                'name' => $data['login_name'],
                'email' => strtolower($data['login_email']),
                'phone' => $data['phone'] ?? null,
                'password' => $data['login_password'],
                'role' => 'owner',
            ]);

            return $retailer;
        });

        return response()->json(['data' => $retailer->load('users')], 201);
    }

    public function update(Request $request, Retailer $retailer): JsonResponse
    {
        $data = $request->validate($this->rules() + ['shop_name' => ['sometimes', 'string', 'max:150']]);

        $retailer->update($this->retailerFields($data));

        return response()->json(['data' => $retailer->load('users')]);
    }

    public function approve(Retailer $retailer): JsonResponse
    {
        if (! $retailer->isActive()) {
            PlanLimits::ensureRoomFor('retailers');
        }

        return $this->setStatus($retailer, Retailer::STATUS_ACTIVE);
    }

    public function reject(Retailer $retailer): JsonResponse
    {
        abort_unless($retailer->status === Retailer::STATUS_PENDING, 422, 'Only pending retailers can be rejected.');

        return $this->setStatus($retailer, Retailer::STATUS_REJECTED);
    }

    public function suspend(Retailer $retailer): JsonResponse
    {
        return $this->setStatus($retailer, Retailer::STATUS_SUSPENDED);
    }

    private function setStatus(Retailer $retailer, string $status): JsonResponse
    {
        $retailer->status = $status;

        if ($status === Retailer::STATUS_ACTIVE && ! $retailer->approved_at) {
            $retailer->forceFill(['approved_by' => Auth::guard('staff')->id(), 'approved_at' => now()]);
        }

        $retailer->save();

        return response()->json(['data' => $retailer]);
    }

    private function rules(): array
    {
        return [
            'contact_name' => ['nullable', 'string', 'max:100'],
            'gstin' => ['nullable', 'string', self::GSTIN],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'credit_limit' => ['sometimes', 'decimal:0,2', 'min:0'],
            'making_slab_id' => ['nullable', TenantRules::exists('making_slabs')],
            'metal_account_enabled' => ['sometimes', 'boolean'],
        ];
    }

    private function retailerFields(array $data): array
    {
        $fields = Arr::only($data, [
            'shop_name', 'contact_name', 'gstin', 'city', 'address', 'phone', 'email',
            'credit_limit', 'making_slab_id', 'metal_account_enabled',
        ]);

        if (array_key_exists('gstin', $fields)) {
            $fields['state_code'] = $fields['gstin'] ? substr($fields['gstin'], 0, 2) : null;
        }

        return $fields;
    }
}
