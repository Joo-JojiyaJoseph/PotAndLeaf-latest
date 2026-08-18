<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-location quantity on hand for each product. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_stock', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('qty', 16, 3)->default(0);
            $table->timestamps();

            $table->unique(['location_id', 'product_id']);
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_stock');
    }
};
