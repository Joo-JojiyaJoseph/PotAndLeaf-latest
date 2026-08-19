<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

/** Backorder permissions were added after initial role seed — grant them to standard roles. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionRegistry::flat() as $row) {
            if (! str_starts_with($row['name'], 'backorder.')) {
                continue;
            }
            Permission::firstOrCreate(
                ['name' => $row['name']],
                ['id' => (string) Str::uuid(), 'module' => $row['module'], 'label' => $row['label']],
            );
        }

        $backorderIds = Permission::where('name', 'like', 'backorder.%')->pluck('id');

        $manager = Role::where('slug', 'manager')->first();
        if ($manager) {
            $manager->permissions()->syncWithoutDetaching($backorderIds);
        }

        $salesman = Role::where('slug', 'salesman')->first();
        if ($salesman) {
            $salesman->permissions()->syncWithoutDetaching(
                Permission::whereIn('name', [
                    'backorder.view', 'backorder.create', 'backorder.fulfill',
                ])->pluck('id'),
            );
        }

        $cashier = Role::where('slug', 'cashier')->first();
        if ($cashier) {
            $cashier->permissions()->syncWithoutDetaching(
                Permission::where('name', 'backorder.view')->pluck('id'),
            );
        }
    }

    public function down(): void
    {
        // Permissions remain in catalog; no rollback of role grants.
    }
};
