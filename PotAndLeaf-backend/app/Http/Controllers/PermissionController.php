<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Team;
use App\Support\Rbac\PermissionRegistry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only view of the permission catalog. Permissions are defined in code
 * (PermissionRegistry) and seeded — they aren't hand-created at runtime — so
 * this screen documents what exists and which role would grant it.
 */
class PermissionController extends Controller
{
    public function index(Request $request, Team $current_team): Response
    {
        $this->authorize('viewAny', Role::class);

        return Inertia::render('permissions/index', [
            'team'   => $current_team->slug,
            'groups' => PermissionRegistry::groups(),
        ]);
    }
}
