<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

/** Accounts book permissions were added after initial role seed — grant them to standard roles. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionRegistry::flat() as $row) {
            if (! str_starts_with($row['name'], 'accounts.')) {
                continue;
            }
            Permission::firstOrCreate(
                ['name' => $row['name']],
                ['id' => (string) Str::uuid(), 'module' => $row['module'], 'label' => $row['label']],
            );
        }

        $allIds = Permission::where('name', 'like', 'accounts.%')->pluck('id');

        $manager = Role::where('slug', 'manager')->first();
        if ($manager) {
            $manager->permissions()->syncWithoutDetaching($allIds);
        }

        $cashier = Role::where('slug', 'cashier')->first();
        if ($cashier) {
            $cashier->permissions()->syncWithoutDetaching(
                Permission::whereIn('name', ['accounts.view', 'accounts.create'])->pluck('id'),
            );
        }
    }

    public function down(): void
    {
        // Permissions remain in catalog; no rollback of role grants.
    }
};
