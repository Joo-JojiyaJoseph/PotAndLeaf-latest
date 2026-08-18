<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bom_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bom_id')->constrained('boms')->cascadeOnDelete();
            $table->foreignUuid('component_product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('qty', 16, 3); // per recipe (per output_qty units)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_items');
    }
};
