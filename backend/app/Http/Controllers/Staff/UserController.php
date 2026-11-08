<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Permissions;
use App\Support\PlanLimits;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::with('roles:id,name')->orderBy('name')->get()
            ->map(fn (User $u) => $u->only(['id', 'name', 'email', 'phone', 'is_active']) + [
                'roles' => $u->roles->pluck('name'),
            ]);

        return response()->json(['data' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', TenantRules::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', Password::min(8)],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [$this->roleRule()],
        ]);

        PlanLimits::ensureRoomFor('users');

        $user = DB::transaction(function () use ($data) {
            $user = User::create(['email' => strtolower($data['email'])] + Arr::only($data, ['name', 'phone', 'password']));
            $user->syncRoles($data['roles']);

            return $user;
        });

        return response()->json(['data' => $user->toAuthPayload()], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'email', 'max:150', TenantRules::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['sometimes', Password::min(8)],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => [$this->roleRule()],
        ]);

        $this->guardLastOwner($request, $user, $data);

        DB::transaction(function () use ($user, $data) {
            $user->update(Arr::except($data, ['roles']));
            if (isset($data['roles'])) {
                $user->syncRoles($data['roles']);
            }
        });

        return response()->json(['data' => $user->fresh()->toAuthPayload()]);
    }

    private function roleRule(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('roles', 'name')
            ->where('guard_name', Permissions::GUARD)
            ->where('tenant_id', app(TenantContext::class)->id());
    }

    /** A workspace must always keep at least one active owner. */
    private function guardLastOwner(Request $request, User $user, array $data): void
    {
        if (! $user->hasRole(Permissions::ROLE_OWNER)) {
            return;
        }

        $losingOwner = (isset($data['roles']) && ! in_array(Permissions::ROLE_OWNER, $data['roles'], true))
            || (array_key_exists('is_active', $data) && ! $data['is_active']);

        if ($losingOwner && User::role(Permissions::ROLE_OWNER)->where('is_active', true)->count() <= 1) {
            abort(422, 'The workspace needs at least one active owner.');
        }
    }
}
