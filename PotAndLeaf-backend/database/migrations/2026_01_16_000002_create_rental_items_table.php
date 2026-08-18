<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('rental_id')->constrained('rentals')->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name');
            $table->decimal('qty', 16, 3);
            $table->decimal('rate_per_cycle', 14, 2)->default(0);
            $table->decimal('returned_qty', 16, 3)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_items');
    }
};
