<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production: setting (set-up / labour) charge and miscellaneous charge for the run, and the margin used to
 * suggest a selling price. Both charges are part of the cost of the finished goods:
 *   total cost = materials + setting charge + miscellaneous; unit cost = total cost / pieces made.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('production_orders', 'setting_charge')) {
            Schema::table('production_orders', function (Blueprint $table) {
                $table->decimal('setting_charge', 12, 2)->default(0);
                $table->decimal('misc_charge', 12, 2)->default(0);
                $table->string('margin_type', 10)->default('percent');   // percent | amount (per piece)
                $table->decimal('margin_value', 12, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('production_orders', 'setting_charge')) {
            Schema::table('production_orders', function (Blueprint $table) {
                $table->dropColumn(['setting_charge', 'misc_charge', 'margin_type', 'margin_value']);
            });
        }
    }
};
