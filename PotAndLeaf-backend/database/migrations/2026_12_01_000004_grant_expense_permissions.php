<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

/** Expenses permissions are new — add them to the catalog and give the standard roles access. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionRegistry::flat() as $row) {
            if (! str_starts_with($row['name'], 'expenses.')) {
                continue;
            }
            Permission::firstOrCreate(
                ['name' => $row['name']],
                ['id' => (string) Str::uuid(), 'module' => $row['module'], 'label' => $row['label']],
            );
        }

        $all = Permission::where('name', 'like', 'expenses.%')->pluck('id');
        $basic = Permission::whereIn('name', ['expenses.view', 'expenses.create'])->pluck('id');

        foreach (['manager', 'admin'] as $slug) {
            $role = Role::where('slug', $slug)->first();
            if ($role) {
                $role->permissions()->syncWithoutDetaching($all);
            }
        }
        $cashier = Role::where('slug', 'cashier')->first();
        if ($cashier) {
            $cashier->permissions()->syncWithoutDetaching($basic);
        }
    }

    public function down(): void
    {
        // Permissions stay in the catalog; role grants are not rolled back.
    }
};
