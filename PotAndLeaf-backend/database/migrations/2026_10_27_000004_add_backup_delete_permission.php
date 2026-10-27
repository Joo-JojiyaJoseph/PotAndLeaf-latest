<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** "Delete database backups" — given to every role that can already restore backups. */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('permissions')->where('name', 'backup.delete')->value('id');
        if (! $id) {
            $id = (string) Str::uuid();
            DB::table('permissions')->insert(['id' => $id, 'name' => 'backup.delete', 'module' => 'Backups',
                'label' => 'Delete database backups', 'created_at' => now(), 'updated_at' => now()]);
        }
        $restoreId = DB::table('permissions')->where('name', 'backup.restore')->value('id');
        if ($restoreId) {
            foreach (DB::table('permission_role')->where('permission_id', $restoreId)->pluck('role_id') as $roleId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'backup.delete')->value('id');
        if ($id) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
