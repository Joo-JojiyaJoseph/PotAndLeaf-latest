<?php

namespace App\Models\Concerns;

/**
 * Minimal permission stub so policies work out of the box.
 *
 * Add `use InteractsWithPermissions;` to App\Models\User. Replace the body
 * with your real RBAC once roles/permissions land in Milestone 1:
 *   - spatie/laravel-permission:  return $this->can($permission);  // or hasPermissionTo
 *   - kit TeamPermission enum:     resolve from the current membership
 *
 * For now every authenticated user passes, so you can build and click through
 * the UI before the permission matrix exists.
 */
trait InteractsWithPermissions
{
    public function hasPermission(string $permission): bool
    {
        // TODO: replace with real permission resolution.
        return true;
    }
}
