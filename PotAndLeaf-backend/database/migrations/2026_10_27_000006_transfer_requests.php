<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock requests between companies:
 *  - who asked for the stock (requested_by);
 *  - the batch (and barcode) made at the receiving company when it arrives.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stock_transfers', 'requested_by')) {
            Schema::table('stock_transfers', fn (Blueprint $t) => $t->unsignedBigInteger('requested_by')->nullable());
        }
        if (! Schema::hasColumn('stock_transfer_items', 'received_batch_id')) {
            Schema::table('stock_transfer_items', function (Blueprint $t) {
                $t->uuid('received_batch_id')->nullable();
                $t->uuid('received_product_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stock_transfers', 'requested_by')) {
            Schema::table('stock_transfers', fn (Blueprint $t) => $t->dropColumn('requested_by'));
        }
        if (Schema::hasColumn('stock_transfer_items', 'received_batch_id')) {
            Schema::table('stock_transfer_items', fn (Blueprint $t) => $t->dropColumn(['received_batch_id', 'received_product_id']));
        }
    }
};
