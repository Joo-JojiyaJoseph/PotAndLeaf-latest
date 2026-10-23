<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\StandardRolesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

/**
 * Adds the "Finance" role named in the project proposal (HO Admin, Finance,
 * Branch Manager, Cashier, Godown Staff …) to databases that were seeded
 * before it existed. Same permissions as StandardRolesSeeder::FINANCE.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = Role::firstOrCreate(
            ['slug' => 'finance'],
            ['name' => 'Finance', 'is_system' => false],
        );

        $ids = Permission::query()->pluck('id', 'name')->filter(function ($id, $name) {
            foreach (StandardRolesSeeder::FINANCE as $p) {
                if (str_ends_with($p, '.') ? str_starts_with($name, $p) : $name === $p) {
                    return true;
                }
            }

            return false;
        })->values()->all();

        $role->permissions()->syncWithoutDetaching($ids);
    }

    public function down(): void
    {
        Role::where('slug', 'finance')->first()?->delete();
    }
};
