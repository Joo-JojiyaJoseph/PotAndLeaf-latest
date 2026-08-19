<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('advance_balance', 14, 2)->default(0)->after('outstanding');
        });

        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->foreignUuid('advance_order_id')->nullable()->after('sale_id')
                ->constrained('advance_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('advance_order_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('advance_balance');
        });
    }
};
