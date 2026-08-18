<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Rental flag + daily rate on products for plant-rental module. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_rental')->default(false)->after('status');
            $table->decimal('rental_daily_rate', 12, 2)->nullable()->after('is_rental');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_rental', 'rental_daily_rate']);
        });
    }
};
