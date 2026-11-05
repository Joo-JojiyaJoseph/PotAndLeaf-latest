<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Update 63 — a bill can be paid (fully or in part) from the customer's
 * advance, e.g. money from a sales return. use_advance is the amount asked
 * for when the bill was made; it is taken from the advance on confirm.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales', 'use_advance')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->decimal('use_advance', 14, 2)->default(0)->after('amount_paid');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales', 'use_advance')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('use_advance');
            });
        }
    }
};
