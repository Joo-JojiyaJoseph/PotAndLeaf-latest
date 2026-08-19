<?php

use App\Models\Company;
use App\Models\ProductBatch;
use App\Models\StockTransfer;
use App\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'transfers.view',
        'transfers.create',
        'transfers.dispatch',
        'transfers.delete',
        'transfers.receive',
    ]);
});

it('restores source batch quantity when cancelling in-transit transfer', function () {
    $dest = Company::create([
        'name' => 'Branch B',
        'code' => 'BR'.Str::upper(Str::random(3)),
        'is_active' => true,
    ]);

    $product = $this->createProduct(['current_stock' => 100, 'cost_price' => 50]);
    $batch = ProductBatch::create([
        'company_id' => $product->company_id,
        'product_id' => $product->id,
        'batch_no' => 'BATCH-001',
        'barcode' => 'PLTTEST001',
        'qty' => 100,
        'remaining_qty' => 100,
        'cost_price' => 50,
        'status' => 'active',
        'received_at' => now(),
    ]);

    $transfer = StockTransfer::create([
        'company_id' => $this->company->id,
        'to_company_id' => $dest->id,
        'transfer_no' => 'TRF-000001',
        'transfer_date' => now()->toDateString(),
        'status' => 'draft',
    ]);
    $transfer->items()->create([
        'product_id' => $product->id,
        'product_batch_id' => $batch->id,
        'product_name' => $product->name,
        'qty' => 20,
        'received_qty' => 0,
    ]);

    app(TransferService::class)->dispatch($transfer->fresh(), $this->user->id);

    expect((float) $batch->fresh()->remaining_qty)->toBe(80.0);

    app(TransferService::class)->cancel($transfer->fresh(), $this->user->id);

    expect($transfer->fresh()->status)->toBe('cancelled');
    expect((float) $batch->fresh()->remaining_qty)->toBe(100.0);
    expect($batch->fresh()->status)->toBe('active');
});
