<?php

namespace App\Http\Controllers;

use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Models\Team;
use App\Services\RoleService;
use App\Support\Rbac\PermissionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(Request $request, Team $current_team): Response
    {
        $this->authorize('viewAny', Role::class);

        $filters = $request->only(['search', 'sort', 'dir', 'per_page']);

        return Inertia::render('roles/index', [
            'team'    => $current_team->slug,
            'roles'   => RoleResource::collection($this->roles->list($filters)),
            'filters' => $filters,
        ]);
    }

    public function create(Team $current_team): Response
    {
        $this->authorize('create', Role::class);

        return Inertia::render('roles/form', [
            'team'              => $current_team->slug,
            'permissionGroups'  => PermissionRegistry::groups(),
        ]);
    }

    public function store(StoreRoleRequest $request, Team $current_team): RedirectResponse
    {
        $role = $this->roles->create($request->validated());

        return to_route('roles.index', ['current_team' => $current_team])
            ->with('success', "Role {$role->name} created.");
    }

    public function edit(Team $current_team, Role $role): Response
    {
        $this->authorize('update', $role);

        return Inertia::render('roles/form', [
            'team'             => $current_team->slug,
            'role'             => new RoleResource($role->load('permissions')),
            'permissionGroups' => PermissionRegistry::groups(),
        ]);
    }

    public function update(UpdateRoleRequest $request, Team $current_team, Role $role): RedirectResponse
    {
        $this->roles->update($role, $request->validated());

        return to_route('roles.index', ['current_team' => $current_team])
            ->with('success', "Role {$role->name} updated.");
    }

    public function destroy(Team $current_team, Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);
        $this->roles->delete($role);

        return back()->with('success', 'Role moved to trash.');
    }

    public function restore(Team $current_team, string $role): RedirectResponse
    {
        $restored = $this->roles->restore($role);
        abort_if($restored === null, 404);
        $this->authorize('restore', $restored);

        return back()->with('success', 'Role restored.');
    }
}
