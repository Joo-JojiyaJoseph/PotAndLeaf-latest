<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles become application-wide; company context moves to role_user.company_id
 * so the same role can be assigned per company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });

        if (Schema::hasColumn('roles', 'company_id')) {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('UPDATE role_user SET company_id = (SELECT company_id FROM roles WHERE roles.id = role_user.role_id)');
            } else {
                DB::statement('UPDATE role_user ru INNER JOIN roles r ON r.id = ru.role_id SET ru.company_id = r.company_id');
            }
        }

        $this->consolidateRolesBySlug();

        // One role assignment per user per company.
        $dupes = DB::table('role_user')
            ->select('user_id', 'company_id', DB::raw('MIN(role_id) as keep_role_id'))
            ->groupBy('user_id', 'company_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupes as $row) {
            DB::table('role_user')
                ->where('user_id', $row->user_id)
                ->where('company_id', $row->company_id)
                ->where('role_id', '!=', $row->keep_role_id)
                ->delete();
        }

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropPrimary(['role_id', 'user_id']);
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable(false)->change();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['user_id', 'company_id']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropUnique(['company_id', 'slug']);
            $table->dropColumn('company_id');
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // Best-effort: attach all roles to the first company.
        $firstCompanyId = DB::table('companies')->orderBy('id')->value('id');
        if ($firstCompanyId) {
            DB::table('roles')->update(['company_id' => $firstCompanyId]);
        }

        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable(false)->change();
            $table->unique(['company_id', 'slug']);
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropPrimary(['user_id', 'company_id']);
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
            $table->primary(['role_id', 'user_id']);
        });
    }

    private function consolidateRolesBySlug(): void
    {
        $slugs = DB::table('roles')->distinct()->pluck('slug');

        foreach ($slugs as $slug) {
            $roles = DB::table('roles')
                ->where('slug', $slug)
                ->orderByDesc('is_system')
                ->orderBy('created_at')
                ->get();

            $canonical = $roles->first();
            if (! $canonical) {
                continue;
            }

            foreach ($roles->skip(1) as $duplicate) {
                DB::table('role_user')
                    ->where('role_id', $duplicate->id)
                    ->update(['role_id' => $canonical->id]);

                $permIds = DB::table('permission_role')
                    ->where('role_id', $duplicate->id)
                    ->pluck('permission_id');

                foreach ($permIds as $permId) {
                    DB::table('permission_role')->insertOrIgnore([
                        'permission_id' => $permId,
                        'role_id'       => $canonical->id,
                    ]);
                }

                DB::table('permission_role')->where('role_id', $duplicate->id)->delete();
                DB::table('roles')->where('id', $duplicate->id)->delete();
            }
        }
    }
};
