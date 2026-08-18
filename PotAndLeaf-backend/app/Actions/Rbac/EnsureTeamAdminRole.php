<?php

namespace App\Actions\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Guarantees a team has a protected "Administrator" role holding full access,
 * and optionally assigns it to a user (typically the team owner).
 *
 * Call this when a team is created, and once over existing teams after the
 * first deploy. It is idempotent.
 */
class EnsureTeamAdminRole
{
    public function handle(Team $team, ?User $assignTo = null): Role
    {
        return DB::transaction(function () use ($team, $assignTo) {
            $role = Role::withTrashed()->firstOrCreate(
                ['team_id' => $team->id, 'slug' => 'administrator'],
                ['name' => 'Administrator', 'description' => 'Full access to every module.', 'is_system' => true],
            );

            if ($role->trashed()) {
                $role->restore();
            }

            $wildcard = Permission::where('name', '*')->first();
            if ($wildcard) {
                $role->permissions()->syncWithoutDetaching([$wildcard->id]);
            }

            if ($assignTo) {
                $role->users()->syncWithoutDetaching([$assignTo->id]);
            }

            return $role;
        });
    }
}
