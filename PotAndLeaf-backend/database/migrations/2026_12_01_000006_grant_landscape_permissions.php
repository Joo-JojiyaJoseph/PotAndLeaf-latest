<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

/** Landscape permissions are new — add them to the catalog and give admin / manager access. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionRegistry::flat() as $row) {
            if (! str_starts_with($row['name'], 'landscape.')) {
                continue;
            }
            Permission::firstOrCreate(
                ['name' => $row['name']],
                ['id' => (string) Str::uuid(), 'module' => $row['module'], 'label' => $row['label']],
            );
        }
        $ids = Permission::where('name', 'like', 'landscape.%')->pluck('id');
        foreach (['manager', 'admin'] as $slug) {
            $role = Role::where('slug', $slug)->first();
            if ($role) {
                $role->permissions()->syncWithoutDetaching($ids);
            }
        }
    }

    public function down(): void
    {
        // Permissions stay in the catalog; role grants are not rolled back.
    }
};
