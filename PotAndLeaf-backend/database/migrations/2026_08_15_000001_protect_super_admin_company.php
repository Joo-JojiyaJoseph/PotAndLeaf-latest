<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The HO / super-admin company is a system record, not an operating branch, so
 * it shouldn't appear in the Companies management list. Mark it protected
 * (the flag the list now filters on). Matches the seeded HO codes and any
 * company explicitly named as the super-admin company.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('companies')
            ->whereIn('code', ['CHK-HO', 'POTLEAF'])
            ->orWhere('name', 'like', '%Super Admin%')
            ->update(['is_protected' => true]);
    }

    public function down(): void
    {
        // Leave protection in place on rollback — unprotecting could re-expose
        // the system company unintentionally.
    }
};
