<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\RoleService;
use App\Support\Api\ApiResponse;
use App\Support\Rbac\PermissionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RoleService $roles) {}

    /** Global role catalogue — readable by anyone with roles.view. */
    public function index(Request $request): JsonResponse
    {
        $this->allowView($request);

        return $this->ok(RoleResource::collection($this->roles->list($request->only(['search', 'per_page', 'sort', 'dir']))));
    }

    /** Permission matrix for role forms — super admin only. */
    public function formData(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        return $this->ok(['permission_groups' => PermissionRegistry::groups()]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);
        $role = $this->roles->create($request->validated());

        return $this->created(new RoleResource($role->load('permissions')), 'Role created.');
    }

    public function show(Request $request, Role $role): JsonResponse
    {
        $this->allowView($request);

        return $this->ok(new RoleResource($role->load('permissions')));
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $this->ensureSuperAdmin($request);
        $updated = $this->roles->update($role, $request->validated());

        return $this->ok(new RoleResource($updated->load('permissions')), 'Role updated.');
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->ensureSuperAdmin($request);
        abort_if($role->is_system, 403, 'System roles cannot be deleted.');
        $this->roles->delete($role);

        return $this->message('Role deleted.');
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allowView(Request $request): void
    {
        abort_unless($request->user()->hasPermission('roles.view', $this->company($request)->id), 403);
    }

    private function ensureSuperAdmin(Request $request): void
    {
        abort_unless($request->user()->is_super_admin, 403, 'Only the super admin can manage roles.');
    }
}
