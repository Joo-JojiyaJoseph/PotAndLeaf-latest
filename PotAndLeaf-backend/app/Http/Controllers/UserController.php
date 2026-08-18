<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Team members and their ERP role assignments. Full user provisioning
 * (create/invite/deactivate) is a follow-up; this focuses on making RBAC
 * usable end to end by assigning roles to existing members.
 */
class UserController extends Controller
{
    public function index(Request $request, Team $current_team): Response
    {
        abort_unless($request->user()->hasPermission('users.view'), 403);

        $members = $current_team->members()->orderBy('name')->get(['users.id', 'name', 'email']);

        $teamRoles = Role::forTeam($current_team->id)->orderBy('name')->get(['id', 'name']);

        $assignments = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('roles.team_id', $current_team->id)
            ->whereIn('role_user.user_id', $members->pluck('id'))
            ->get(['role_user.user_id', 'role_user.role_id'])
            ->groupBy('user_id');

        return Inertia::render('users/index', [
            'team'  => $current_team->slug,
            'users' => $members->map(fn ($u) => [
                'id'        => $u->id,
                'name'      => $u->name,
                'email'     => $u->email,
                'team_role' => $u->pivot->role ?? null,
                'role_ids'  => ($assignments[$u->id] ?? collect())->pluck('role_id')->values(),
            ]),
            'roles' => $teamRoles,
            'can'   => [
                'assign' => $request->user()->hasPermission('users.assign-roles'),
            ],
        ]);
    }

    public function updateRoles(Request $request, Team $current_team, User $user): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('users.assign-roles'), 403);
        abort_unless($current_team->members()->whereKey($user->id)->exists(), 404);

        $validated = $request->validate([
            'roles'   => ['nullable', 'array'],
            'roles.*' => ['uuid', Rule::exists('roles', 'id')->where('team_id', $current_team->id)],
        ]);

        // Swap only this team's roles; leave the user's roles in other teams alone.
        $teamRoleIds = Role::forTeam($current_team->id)->pluck('id')->all();
        $user->roles()->detach($teamRoleIds);
        $user->roles()->attach($validated['roles'] ?? []);

        return back()->with('success', 'Roles updated.');
    }
}
