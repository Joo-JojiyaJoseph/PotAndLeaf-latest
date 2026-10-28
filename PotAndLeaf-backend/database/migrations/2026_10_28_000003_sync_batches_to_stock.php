<?php

use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Database\Migrations\Migration;

/**
 * One-time correction: make every product's batches add up to its stock on
 * hand (Stock Levels = Batches & Barcodes = sale screen). Stock that came back
 * without a batch (rental cancel, bulk-split cancel, stock-count gains…) is
 * put back on its batch or into a new ADJ batch; batches showing more than is
 * on hand are brought down, oldest first. Stock on hand itself is not changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $inventory = app(InventoryService::class);
        Product::query()->where(fn ($q) => $q->whereNull('pool_role')->orWhere('pool_role', '!=', 'set'))
            ->orderBy('id')->pluck('id')
            ->each(fn ($id) => $inventory->syncBatchesToStock((string) $id));
    }

    public function down(): void
    {
        // Nothing to undo: only batch quantities were corrected.
    }
};
