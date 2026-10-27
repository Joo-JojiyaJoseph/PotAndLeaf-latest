<?php

use App\Models\ProductBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

it('keeps the opening batch received figure equal to opening stock when it is edited down and up', function () {
    $this->createCompanyWithUser(['products.view', 'products.create', 'products.edit', 'categories.create']);
    $svc = app(\App\Services\InventoryService::class);
    $product = $this->createProduct(['sku' => 'SKU-00001', 'name' => 'Money Plant', 'current_stock' => 150, 'opening_stock' => 150]);
    $svc->adjustOpeningBatch($product, 150);   // created with 150
    $svc->adjustOpeningBatch($product, -150);  // edited to 0
    $svc->adjustOpeningBatch($product, 150);   // back to 150
    $svc->adjustOpeningBatch($product, -150);
    $svc->adjustOpeningBatch($product, 150);

    $b = ProductBatch::where('batch_no', 'OPEN-SKU-00001')->first();
    expect((float) $b->qty)->toBe(150.0);
    expect((float) $b->remaining_qty)->toBe(150.0);
});

it('takes a decrease beyond the opening batch from other batches, oldest first', function () {
    $this->createCompanyWithUser(['products.view']);
    $svc = app(\App\Services\InventoryService::class);
    $product = $this->createProduct(['sku' => 'S-2']);
    $svc->adjustOpeningBatch($product, 10);
    $open = ProductBatch::where('batch_no', 'OPEN-S-2')->first();
    $open->update(['remaining_qty' => 4]); // 6 already sold
    $other = ProductBatch::create(['company_id' => $this->company->id, 'product_id' => $product->id, 'batch_no' => 'P-1', 'barcode' => 'X1', 'qty' => 20, 'remaining_qty' => 20, 'cost_price' => 1, 'status' => 'active', 'received_at' => now()]);

    $svc->adjustOpeningBatch($product, -7);

    expect((float) $open->fresh()->remaining_qty)->toBe(0.0);
    expect((float) $open->fresh()->qty)->toBe(6.0);
    expect((float) $other->fresh()->remaining_qty)->toBe(17.0);
});
