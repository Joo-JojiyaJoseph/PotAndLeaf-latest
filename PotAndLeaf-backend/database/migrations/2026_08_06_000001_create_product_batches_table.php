<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A batch is a received lot of one product from one purchase line. It carries
 * its own barcode, so stock is labelled batch-wise at the time of purchase
 * rather than once per product at creation. Phase 1 uses batches for labelling
 * and traceability; qty/status fields are here so batch-level costing can be
 * switched on later without another schema change.
 *
 * Only company_id and product_id are hard foreign keys (both proven elsewhere);
 * the provenance columns are plain indexed UUIDs to keep this migration robust
 * across environments (no cross-table charset/collation surprises on MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_batches')) {
            return;
        }

        Schema::create('product_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();

            // Provenance — plain UUIDs, related at the application layer.
            $table->uuid('purchase_id')->nullable();
            $table->uuid('purchase_item_id')->nullable();
            $table->uuid('supplier_id')->nullable();
            $table->uuid('location_id')->nullable();   // reserved for Phase 2

            $table->string('batch_no');
            $table->string('barcode');

            $table->decimal('qty', 14, 3);                        // received quantity
            $table->decimal('remaining_qty', 14, 3)->default(0);  // maintained in Phase 2
            $table->decimal('cost_price', 14, 4)->default(0);     // landed unit cost

            $table->string('status')->default('active');          // active | depleted | archived
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'barcode']);
            $table->index(['company_id', 'product_id']);
            $table->index('purchase_id');
            $table->index('barcode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_batches');
    }
};
