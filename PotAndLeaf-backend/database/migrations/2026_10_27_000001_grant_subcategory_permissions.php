<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Roles could manage categories but were never given the matching
 * subcategory permissions, so the Subcategories tab was hidden for
 * everyone except the super admin. Every role with categories.X now also
 * gets subcategories.X.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (['view', 'create', 'update', 'delete'] as $action) {
            $catId = DB::table('permissions')->where('name', "categories.{$action}")->value('id');
            if (! $catId) {
                continue;
            }
            $subId = DB::table('permissions')->where('name', "subcategories.{$action}")->value('id');
            if (! $subId) {
                $subId = (string) Str::uuid();
                DB::table('permissions')->insert([
                    'id' => $subId, 'name' => "subcategories.{$action}", 'module' => 'Subcategories',
                    'label' => ucfirst($action).' subcategories', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            foreach (DB::table('permission_role')->where('permission_id', $catId)->pluck('role_id') as $roleId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $subId, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        // Leaves the grants in place: removing them would hide the tab again.
    }
};
