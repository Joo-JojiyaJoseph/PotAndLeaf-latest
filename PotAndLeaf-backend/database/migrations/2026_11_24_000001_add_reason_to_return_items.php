<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A reason for each product on a purchase return and a sales return. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['purchase_return_items', 'sales_return_items'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'reason')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('reason', 255)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['purchase_return_items', 'sales_return_items'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'reason')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('reason');
                });
            }
        }
    }
};
