<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A company can have several branches and godowns.
 *
 *  - company_user.location_id: the branch or godown a user works at in that company.
 *    A godown user can only work with stock transfers.
 *  - The company's main (default) location no longer keeps its own stock rows: it holds the
 *    company total minus what the other locations hold, so old stock stays correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('company_user', 'location_id')) {
            Schema::table('company_user', function (Blueprint $table) {
                $table->uuid('location_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('location_stock') && Schema::hasTable('locations')) {
            $mainIds = DB::table('locations')->where('is_default', true)->pluck('id');
            if ($mainIds->isNotEmpty()) {
                DB::table('location_stock')->whereIn('location_id', $mainIds)->delete();
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_user', 'location_id')) {
            Schema::table('company_user', function (Blueprint $table) {
                $table->dropColumn('location_id');
            });
        }
    }
};
