<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

/**
 * Ability names map to permission strings ("suppliers.view", …).
 *
 * Company isolation is enforced at the query layer (every repository call is
 * scoped with ->forCompany(), and routes are team-prefixed), so a user can never
 * even load another team's row. That lets the policy stay focused on *what*
 * the user may do, not *whose* data it is.
 *
 * `hasPermission()` comes from the InteractsWithPermissions trait — replace it
 * with spatie/laravel-permission or company-scoped roles for real RBAC.
 */
class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('suppliers.view');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->hasPermission('suppliers.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('suppliers.create');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->hasPermission('suppliers.update');
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->hasPermission('suppliers.delete');
    }

    public function restore(User $user, Supplier $supplier): bool
    {
        return $user->hasPermission('suppliers.delete');
    }

    public function forceDelete(User $user, Supplier $supplier): bool
    {
        return $user->hasPermission('suppliers.force-delete');
    }
}
