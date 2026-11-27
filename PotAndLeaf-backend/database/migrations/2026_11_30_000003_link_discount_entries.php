<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A discount entry points at the receipt / payment it was given with, so voiding one voids the other. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customer_receipts', 'supplier_payments'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'discount_for_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->uuid('discount_for_id')->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['customer_receipts', 'supplier_payments'] as $table) {
            if (Schema::hasColumn($table, 'discount_for_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('discount_for_id'));
            }
        }
    }
};
