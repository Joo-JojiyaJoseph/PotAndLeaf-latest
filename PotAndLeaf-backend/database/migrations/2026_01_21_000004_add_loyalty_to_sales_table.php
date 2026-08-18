<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedInteger('loyalty_points_redeemed')->default(0)->after('amount_paid');
            $table->decimal('loyalty_discount', 12, 2)->default(0)->after('loyalty_points_redeemed');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['loyalty_points_redeemed', 'loyalty_discount']);
        });
    }
};
