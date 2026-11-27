<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A bill now has a chosen salesman (sales.created_by = who is credited with the sale and its
 * commission) and a separate record of who typed it in (sales.entered_by).
 * Old bills: the person who made them is both.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales', 'entered_by')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->unsignedBigInteger('entered_by')->nullable()->index();
            });
            DB::table('sales')->whereNull('entered_by')->update(['entered_by' => DB::raw('created_by')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales', 'entered_by')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('entered_by');
            });
        }
    }
};
