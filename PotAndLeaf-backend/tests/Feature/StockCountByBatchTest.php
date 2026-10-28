<?php

/**
 * Stock count batch by batch. Money Plant: batch A 30 left, batch B 20 left (stock 50).
 * Counted: A 28, B 21 → A −2, B +1, stock 49; each batch and the product match the count.
 */

use App\Models\ProductBatch;
use App\Models\StockLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['stock_verifications.view', 'stock_verifications.create', 'stock_verifications.approve']);
    $this->product = $this->createProduct(['name' => 'Money Plant', 'current_stock' => 50, 'cost_price' => 40]);
    $this->a = ProductBatch::create(['company_id' => $this->company->id, 'product_id' => $this->product->id, 'batch_no' => 'GRN-A', 'barcode' => 'GRN-A',
        'qty' => 30, 'remaining_qty' => 30, 'cost_price' => 40, 'status' => 'active', 'received_at' => now()->subDays(5)]);
    $this->b = ProductBatch::create(['company_id' => $this->company->id, 'product_id' => $this->product->id, 'batch_no' => 'GRN-B', 'barcode' => 'GRN-B',
        'qty' => 20, 'remaining_qty' => 20, 'cost_price' => 55, 'status' => 'active', 'received_at' => now()->subDay()]);
});

it('lists batches on the count sheet and adjusts each counted batch', function () {
    $batches = collect($this->getJson('/api/stock-verifications/form-data', $this->apiHeaders())->assertOk()->json('data.batches'))
        ->where('product_id', $this->product->id)->values();
    expect($batches->pluck('batch_no')->all())->toBe(['GRN-A', 'GRN-B'])->and($batches->pluck('system_qty')->all())->toEqual([30, 20]);

    $id = $this->postJson('/api/stock-verifications', [
        'count_date' => now()->toDateString(),
        'items' => [
            ['product_id' => $this->product->id, 'product_batch_id' => $this->a->id, 'counted_qty' => 28],
            ['product_id' => $this->product->id, 'product_batch_id' => $this->b->id, 'counted_qty' => 21],
        ],
    ], $this->apiHeaders())->assertCreated()->json('data.id');

    $items = collect($this->getJson("/api/stock-verifications/{$id}", $this->apiHeaders())->json('data.items'))->keyBy('batch_no');
    expect($items['GRN-A']['system_qty'])->toEqual(30)->and($items['GRN-A']['variance'])->toEqual(-2)
        ->and($items['GRN-B']['variance'])->toEqual(1)->and($items['GRN-B']['unit_cost'])->toEqual(55);

    $this->postJson("/api/stock-verifications/{$id}/submit", [], $this->apiHeaders())->assertOk();
    $this->postJson("/api/stock-verifications/{$id}/approve", [], $this->apiHeaders())->assertOk();

    expect((float) $this->a->fresh()->remaining_qty)->toBe(28.0)
        ->and((float) $this->b->fresh()->remaining_qty)->toBe(21.0)
        ->and((float) $this->product->fresh()->current_stock)->toBe(49.0)
        ->and(StockLedgerEntry::where('reference_id', $id)->pluck('product_batch_id')->sort()->values()->all())
            ->toEqual(collect([$this->a->id, $this->b->id])->sort()->values()->all());
});

it('refuses a batch of another product and the same batch twice', function () {
    $other = $this->createProduct(['name' => 'Areca']);
    $this->postJson('/api/stock-verifications', [
        'count_date' => now()->toDateString(),
        'items' => [
            ['product_id' => $other->id, 'product_batch_id' => $this->a->id, 'counted_qty' => 1],
            ['product_id' => $this->product->id, 'product_batch_id' => $this->b->id, 'counted_qty' => 1],
            ['product_id' => $this->product->id, 'product_batch_id' => $this->b->id, 'counted_qty' => 2],
        ],
    ], $this->apiHeaders())->assertStatus(422)->assertJsonValidationErrors(['items.0.product_batch_id', 'items.2.product_id']);
});
