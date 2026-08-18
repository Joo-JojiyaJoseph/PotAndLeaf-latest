<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_verification_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_verification_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');

            $table->decimal('system_qty', 14, 3);   // snapshot of current_stock at count time
            $table->decimal('counted_qty', 14, 3);   // physically counted
            $table->decimal('variance', 14, 3);       // counted - system (at count time)
            $table->decimal('unit_cost', 14, 4)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_verification_items');
    }
};
