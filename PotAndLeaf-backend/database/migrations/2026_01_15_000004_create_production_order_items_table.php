<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Snapshot of the input products consumed by a production order. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignUuid('component_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name');
            $table->decimal('qty', 16, 3);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('line_cost', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_items');
    }
};
