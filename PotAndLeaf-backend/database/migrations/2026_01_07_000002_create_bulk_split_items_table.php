<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_split_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bulk_split_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');

            $table->decimal('qty', 14, 3);
            $table->decimal('weight', 10, 3)->default(1);   // relative size/value for cost sharing
            $table->decimal('cost_alloc', 14, 2);
            $table->decimal('unit_cost', 14, 4);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_split_items');
    }
};
