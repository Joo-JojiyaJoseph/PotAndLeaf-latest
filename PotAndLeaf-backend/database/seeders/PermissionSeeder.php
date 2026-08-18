<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Support\Rbac\PermissionRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Idempotently syncs the permission catalog. Safe to run repeatedly and after
 * every deploy — it upserts new permissions and leaves existing ones intact.
 *
 *   php artisan db:seed --class=Database\\Seeders\\PermissionSeeder
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionRegistry::flat() as $row) {
            Permission::firstOrCreate(
                ['name' => $row['name']],
                ['id' => (string) Str::uuid(), 'module' => $row['module'], 'label' => $row['label']],
            );
        }
    }
}
