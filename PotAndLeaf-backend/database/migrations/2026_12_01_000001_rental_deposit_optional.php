<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The security deposit is optional: a blank deposit is stored as NULL (it no longer defaults to the retail price). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rentals', 'deposit')) {
            Schema::table('rentals', function (Blueprint $table) {
                $table->decimal('deposit', 14, 2)->nullable()->default(null)->change();
            });
            DB::table('rentals')->where('deposit', '<=', 0)->update(['deposit' => null]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rentals', 'deposit')) {
            DB::table('rentals')->whereNull('deposit')->update(['deposit' => 0]);
            Schema::table('rentals', function (Blueprint $table) {
                $table->decimal('deposit', 14, 2)->default(0)->nullable(false)->change();
            });
        }
    }
};
