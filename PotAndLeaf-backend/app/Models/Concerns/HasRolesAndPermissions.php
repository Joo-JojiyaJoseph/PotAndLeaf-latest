<?php

namespace App\Models\Concerns;

use App\Models\Location;
use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company-scoped RBAC for the User model. Roles are global; role_user.company_id
 * records which role applies in each company. Permission checks resolve against
 * a company id (from X-Company-Id). Wildcards: "*" or "products.*".
 */
trait HasRolesAndPermissions
{
    /** Per-request memo of permission names keyed by company id. */
    protected array $permissionCache = [];

    /** Per-request memo of the user's location id per company. */
    protected array $locationIdCache = [];

    /** Per-request memo: is the user based at a godown, per company. */
    protected array $godownCache = [];

    /** A godown user can only work with stock transfers. */
    public const GODOWN_MODULES = ['transfers'];

    public const GODOWN_EXTRA = [];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->withPivot('company_id')
            ->withTimestamps();
    }

    public function hasPermission(string $permission, int|string|null $companyId = null): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        $companyId ??= $this->defaultCompany()?->id;

        if ($companyId === null) {
            return false;
        }

        $names = $this->permissionNamesForCompany($companyId);

        if ($names->contains('*')) {
            return true;
        }

        $module = explode('.', $permission)[0];

        return $names->contains($permission) || $names->contains("{$module}.*");
    }

    /**
     * True when this user may see only their own records of a module
     * (e.g. a salesman: own bills, customers they added). Anyone with
     * "<module>.view_all" — or full access — sees everyone's.
     */
    public function seesOnlyOwn(string $module, int|string|null $companyId = null): bool
    {
        return ! $this->hasPermission("{$module}.view_all", $companyId);
    }

    /** @param array<int,string> $permissions */
    public function hasAnyPermission(array $permissions, int|string|null $companyId = null): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission, $companyId)) {
                return true;
            }
        }

        return false;
    }

    public function hasRole(string $slug, int|string|null $companyId = null): bool
    {
        return $this->roles()
            // `when` hands over the plain query builder, which has no wherePivot():
            // name the pivot column directly.
            ->when($companyId !== null, fn ($q) => $q->where('role_user.company_id', $companyId))
            ->where('slug', $slug)
            ->exists();
    }

    /** Distinct permission names granted to this user within a company. */
    public function permissionNamesForCompany(int|string $companyId): Collection
    {
        return $this->permissionCache[$companyId] ??= $this->limitForLocation($companyId, Role::query()
            ->whereHas('users', fn ($q) => $q
                ->whereKey($this->getKey())
                ->where('role_user.company_id', $companyId))
            ->with('permissions:id,name')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            // Extra permissions given to this user alone (approved access requests).
            ->merge($this->extraPermissionNames($companyId))
            ->unique()
            ->values());
    }

    /** The branch or godown this user works at in a company, set on the user (company membership). */
    public function locationIdFor(int|string $companyId): ?string
    {
        if (array_key_exists($companyId, $this->locationIdCache)) {
            return $this->locationIdCache[$companyId];
        }

        // Before "php artisan migrate" adds the column, nobody has a location.
        $id = Schema::hasColumn('company_user', 'location_id')
            ? DB::table('company_user')->where('user_id', $this->getKey())->where('company_id', $companyId)->value('location_id')
            : null;

        return $this->locationIdCache[$companyId] = $id ? (string) $id : null;
    }

    /** True when the user is based at a godown in this company: stock transfers only. */
    public function isGodownUser(int|string $companyId): bool
    {
        if ($this->is_super_admin) {
            return false;
        }
        if (array_key_exists($companyId, $this->godownCache)) {
            return $this->godownCache[$companyId];
        }
        $location = $this->locationIdFor($companyId);

        return $this->godownCache[$companyId] = $location !== null
            && Location::whereKey($location)->where('type', 'godown')->exists();
    }

    /**
     * Branch users keep everything their role allows. A godown user is cut down to stock
     * transfers, whatever their role says.
     */
    protected function limitForLocation(int|string $companyId, Collection $names): Collection
    {
        if (! $this->isGodownUser($companyId)) {
            return $names;
        }

        $all = $names->contains('*');
        $kept = collect();
        foreach (self::GODOWN_MODULES as $module) {
            if ($all || $names->contains("{$module}.*")) {
                $kept->push("{$module}.*");
            } else {
                $kept = $kept->merge($names->filter(fn ($n) => str_starts_with($n, "{$module}.")));
            }
        }
        foreach (self::GODOWN_EXTRA as $permission) {
            $module = explode('.', $permission)[0];
            if ($all || $names->contains($permission) || $names->contains("{$module}.*")) {
                $kept->push($permission);
            }
        }

        return $kept->unique()->values();
    }

    /** Permissions given to this one user in a company, on top of their role. */
    public function extraPermissionNames(int|string $companyId): Collection
    {
        // Before "php artisan migrate" adds the table, act as if there are none,
        // so roles keep working instead of every permission check failing.
        if (! \App\Support\Rbac\AccessRequestTables::ready()) {
            return collect();
        }

        return \Illuminate\Support\Facades\DB::table('user_permissions')
            ->join('permissions', 'permissions.id', '=', 'user_permissions.permission_id')
            ->where('user_permissions.user_id', $this->getKey())
            ->where('user_permissions.company_id', $companyId)
            ->pluck('permissions.name');
    }

    /** Forget cached permission names (after a grant or removal). */
    public function forgetPermissionCache(): void
    {
        $this->permissionCache = [];
        $this->locationIdCache = [];
        $this->godownCache = [];
    }
}
