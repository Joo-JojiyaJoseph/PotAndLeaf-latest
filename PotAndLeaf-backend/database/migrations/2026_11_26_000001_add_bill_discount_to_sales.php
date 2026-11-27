<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A discount typed as a rupee amount on the whole bill (already spread over the lines). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales', 'bill_discount')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->decimal('bill_discount', 12, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales', 'bill_discount')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('bill_discount');
            });
        }
    }
};
