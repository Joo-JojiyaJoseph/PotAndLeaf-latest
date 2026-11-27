<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

/** landscape.finance (cost, profit, commission of a project) is new — add it and give admin / manager access. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionRegistry::flat() as $row) {
            if ($row['name'] !== 'landscape.finance') {
                continue;
            }
            Permission::firstOrCreate(
                ['name' => $row['name']],
                ['id' => (string) Str::uuid(), 'module' => $row['module'], 'label' => $row['label']],
            );
        }
        $ids = Permission::where('name', 'landscape.finance')->pluck('id');
        foreach (['manager', 'admin'] as $slug) {
            Role::where('slug', $slug)->first()?->permissions()->syncWithoutDetaching($ids);
        }
    }

    public function down(): void
    {
    }
};
