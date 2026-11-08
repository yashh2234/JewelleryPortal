<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Kaarigar;
use App\Models\KaarigarUser;
use App\Support\Tenancy\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class KaarigarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $kaarigars = Kaarigar::query()
            ->withCount('appUsers')
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('phone', 'like', '%'.$request->string('search').'%')))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return response()->json($kaarigars);
    }

    public function show(Kaarigar $kaarigar): JsonResponse
    {
        return response()->json(['data' => $kaarigar->load('appUsers')]);
    }

    /** Creates the kaarigar and, if app_access is on, a phone login for the kaarigar app. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'regex:/^[6-9]\d{9}$/'],
            'type' => ['required', Rule::in([Kaarigar::TYPE_IN_HOUSE, Kaarigar::TYPE_OUTSIDE])],
            'address' => ['nullable', 'string', 'max:500'],
            'app_access' => ['boolean'],
            'language' => ['nullable', Rule::in(['hi', 'en', 'bn', 'gu'])],
        ]);

        if (($data['app_access'] ?? false)) {
            $request->validate([
                'phone' => ['required', TenantRules::unique('kaarigar_users', 'phone')],
            ]);
        }

        $kaarigar = DB::transaction(function () use ($data) {
            $kaarigar = Kaarigar::create(Arr::only($data, ['name', 'phone', 'type', 'address']));

            if ($data['app_access'] ?? false) {
                $kaarigar->appUsers()->create([
                    'name' => $data['name'],
                    'phone' => $data['phone'],
                    'is_master' => true,
                    'language' => $data['language'] ?? 'hi',
                ]);
            }

            return $kaarigar;
        });

        return response()->json(['data' => $kaarigar->load('appUsers')], 201);
    }

    public function update(Request $request, Kaarigar $kaarigar): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'regex:/^[6-9]\d{9}$/'],
            'type' => ['sometimes', Rule::in([Kaarigar::TYPE_IN_HOUSE, Kaarigar::TYPE_OUTSIDE])],
            'address' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($kaarigar, $data) {
            $kaarigar->update($data);

            // Deactivating a kaarigar also blocks app login.
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                $kaarigar->appUsers()->get()->each->update(['is_active' => false]);
            }
        });

        return response()->json(['data' => $kaarigar->load('appUsers')]);
    }

    /** Add a phone login (e.g. a worker under an outside karkhana's master login). */
    public function addAppUser(Request $request, Kaarigar $kaarigar): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'regex:/^[6-9]\d{9}$/', TenantRules::unique('kaarigar_users', 'phone')],
            'is_master' => ['boolean'],
            'language' => ['nullable', Rule::in(['hi', 'en', 'bn', 'gu'])],
        ]);

        $user = $kaarigar->appUsers()->create(['language' => $data['language'] ?? 'hi'] + $data);

        return response()->json(['data' => $user], 201);
    }

    public function updateAppUser(Request $request, Kaarigar $kaarigar, KaarigarUser $appUser): JsonResponse
    {
        abort_unless($appUser->kaarigar_id === $kaarigar->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'phone' => ['sometimes', 'regex:/^[6-9]\d{9}$/', TenantRules::unique('kaarigar_users', 'phone')->ignore($appUser->id)],
            'language' => ['sometimes', Rule::in(['hi', 'en', 'bn', 'gu'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $appUser->update($data);

        return response()->json(['data' => $appUser]);
    }
}
