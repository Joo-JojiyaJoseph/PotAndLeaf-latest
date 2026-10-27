<?php

namespace App\Models\Concerns;

use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * Company-scoped RBAC for the User model. Roles are global; role_user.company_id
 * records which role applies in each company. Permission checks resolve against
 * a company id (from X-Company-Id). Wildcards: "*" or "products.*".
 */
trait HasRolesAndPermissions
{
    /** Per-request memo of permission names keyed by company id. */
    protected array $permissionCache = [];

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
        return $this->permissionCache[$companyId] ??= Role::query()
            ->whereHas('users', fn ($q) => $q
                ->whereKey($this->getKey())
                ->where('role_user.company_id', $companyId))
            ->with('permissions:id,name')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->values();
    }
}
