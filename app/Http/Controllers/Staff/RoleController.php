<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Support\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/** Roles are per tenant. The owner role always has every permission and can't be edited. */
class RoleController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->query()->with('permissions:id,name')->orderBy('name')->get()
                ->map(fn (Role $r) => ['id' => $r->id, 'name' => $r->name, 'permissions' => $r->permissions->pluck('name')]),
            'permissions' => Permissions::all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('roles', 'name')->where('tenant_id', $this->context->id())->where('guard_name', Permissions::GUARD)],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(Permissions::all())],
        ]);

        $role = Role::create(['name' => $data['name'], 'guard_name' => Permissions::GUARD]);
        $role->syncPermissions($data['permissions'] ?? []);

        return response()->json(['data' => ['id' => $role->id, 'name' => $role->name, 'permissions' => $role->permissions->pluck('name')]], 201);
    }

    public function update(Request $request, int $role): JsonResponse
    {
        $role = $this->query()->findOrFail($role);
        abort_if($role->name === Permissions::ROLE_OWNER, 422, 'The owner role cannot be changed.');

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => [Rule::in(Permissions::all())],
        ]);

        $role->syncPermissions($data['permissions']);

        return response()->json(['data' => ['id' => $role->id, 'name' => $role->name, 'permissions' => $role->permissions()->pluck('name')]]);
    }

    private function query()
    {
        return Role::query()->where('tenant_id', $this->context->id())->where('guard_name', Permissions::GUARD);
    }
}
