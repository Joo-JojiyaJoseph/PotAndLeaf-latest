<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('product_name');
            $table->string('hsn_code')->nullable();

            $table->decimal('qty', 14, 3);
            $table->decimal('rate', 14, 2);
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('taxable_value', 14, 2);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);

            // Original landed unit cost — the value stock is reversed out at.
            $table->decimal('unit_cost', 14, 4)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
    }
};
