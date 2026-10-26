<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "See everyone's sales / customers" permissions.
 *
 * Without them a user sees only the bills they made and the customers they
 * added (a salesman cannot see another salesman's customers or sales).
 * Every existing role that can view sales/customers keeps seeing everything,
 * except the Salesman role.
 */
return new class extends Migration
{
    private array $grants = [
        'sales.view_all'     => ['Sales', "See every staff member's sales (off = only their own bills)", 'sales.view'],
        'customers.view_all' => ['Customers', 'See every customer (off = only customers they added)', 'customers.view'],
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->grants as $name => [$module, $label, $viewPermission]) {
            $id = DB::table('permissions')->where('name', $name)->value('id');
            if (! $id) {
                $id = (string) Str::uuid();
                DB::table('permissions')->insert(['id' => $id, 'name' => $name, 'module' => $module, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]);
            }

            $viewId = DB::table('permissions')->where('name', $viewPermission)->value('id');
            if (! $viewId) {
                continue;
            }

            $roleIds = DB::table('permission_role')
                ->join('roles', 'roles.id', '=', 'permission_role.role_id')
                ->where('permission_role.permission_id', $viewId)
                ->where('roles.slug', 'not like', 'salesman%')
                ->pluck('roles.id');

            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys($this->grants))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
