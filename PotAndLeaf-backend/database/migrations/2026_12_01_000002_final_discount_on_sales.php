<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A discount taken off the FINAL bill amount (after GST and all other calculations).
 * Kept apart from the older `bill_discount`, which was spread over the lines before GST.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales') && ! Schema::hasColumn('sales', 'final_discount')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->decimal('final_discount', 12, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales', 'final_discount')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('final_discount');
            });
        }
    }
};
