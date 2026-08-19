<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionRegistry::flat() as $row) {
            Permission::firstOrCreate(
                ['name' => $row['name']],
                ['module' => $row['module'], 'label' => $row['label']],
            );
        }

        $names = ['loyalty.view', 'loyalty.manage', 'loyalty.adjust', 'whatsapp.templates'];
        $ids = Permission::whereIn('name', $names)->pluck('id');

        Role::whereIn('slug', ['manager'])->each(function (Role $role) use ($ids) {
            $role->permissions()->syncWithoutDetaching($ids);
        });

        Role::where('slug', 'cashier')->each(function (Role $role) {
            $viewId = Permission::where('name', 'loyalty.view')->value('id');
            if ($viewId) {
                $role->permissions()->syncWithoutDetaching([$viewId]);
            }
        });
    }

    public function down(): void
    {
        // Permissions retained on rollback — safe to leave registered.
    }
};
