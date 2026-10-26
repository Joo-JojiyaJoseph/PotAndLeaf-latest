<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pay the person who makes an item when the production is completed
 * (₹ per piece made), in addition to the existing bonus paid when the item
 * is sold or sent to another company.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('commission_rules', 'production_per_unit')) {
            Schema::table('commission_rules', function (Blueprint $table) {
                $table->decimal('production_per_unit', 12, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('commission_rules', 'production_per_unit')) {
            Schema::table('commission_rules', fn (Blueprint $t) => $t->dropColumn('production_per_unit'));
        }
    }
};
