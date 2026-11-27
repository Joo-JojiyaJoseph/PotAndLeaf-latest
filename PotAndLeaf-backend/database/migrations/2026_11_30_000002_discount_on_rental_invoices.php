<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A discount given on the final amount when a rent bill is paid. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_invoices') && ! Schema::hasColumn('rental_invoices', 'discount_amount')) {
            Schema::table('rental_invoices', function (Blueprint $table) {
                $table->decimal('discount_amount', 12, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_invoices', 'discount_amount')) {
            Schema::table('rental_invoices', function (Blueprint $table) {
                $table->dropColumn('discount_amount');
            });
        }
    }
};
