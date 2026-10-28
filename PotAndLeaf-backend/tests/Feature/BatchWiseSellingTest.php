<?php

/**
 * 1. A new purchase at a new price changes only that batch's cost, not the product's.
 * 2. The sale form lists each product's batches (oldest first) so a line can be sold from one.
 * 3. "Request shortage" is offered only when an item is short of stock.
 */

use App\Models\ProductBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['sales.view', 'sales.create', 'purchases.view', 'purchases.create', 'purchases.confirm', 'products.view_cost']);
    $this->supplier = $this->createSupplier();
    $this->product = $this->createProduct(['name' => 'Money Plant', 'cost_price' => 50, 'retail_price' => 70, 'current_stock' => 0, 'gst_rate' => 0]);
});

function bwsBuy(object $t, float $rate, float $qty): void
{
    $id = $t->postJson('/api/purchases', [
        'supplier_id' => $t->supplier->id, 'purchase_date' => now()->toDateString(), 'is_interstate' => false,
        'items' => [['product_id' => $t->product->id, 'qty' => $qty, 'rate' => $rate, 'discount' => 0, 'gst_rate' => 0]],
    ], $t->apiHeaders())->assertCreated()->json('data.id');
    $t->postJson("/api/purchases/{$id}/confirm", [], $t->apiHeaders())->assertOk();
}

it('keeps the new price on the new batch only and lists batches for selling', function () {
    bwsBuy($this, 50, 10);
    bwsBuy($this, 65, 5);

    $p = $this->product->fresh();
    expect((float) $p->cost_price)->toBe(50.0)          // not changed to 65
        ->and((float) $p->retail_price)->toBe(70.0)
        ->and(ProductBatch::where('product_id', $p->id)->orderBy('created_at')->pluck('cost_price')->map(fn ($c) => (float) $c)->all())->toBe([50.0, 65.0]);

    $batches = collect($this->getJson('/api/sales/form-data', $this->apiHeaders())->assertOk()->json('data.batches'))->where('product_id', $p->id)->values();
    expect($batches)->toHaveCount(2)
        ->and($batches[0]['remaining_qty'])->toEqual(10)->and($batches[1]['cost_price'])->toEqual(65);
});

it('a product with no cost yet takes the first purchase cost', function () {
    $this->product->update(['cost_price' => 0]);
    bwsBuy($this, 40, 3);
    expect((float) $this->product->fresh()->cost_price)->toBe(40.0);
});

it('offers Request shortage only when an item is short', function () {
    bwsBuy($this, 50, 10);
    $customer = $this->createCustomer();
    $make = fn ($qty) => $this->postJson('/api/sales', [
        'customer_id' => $customer->id, 'sale_date' => now()->toDateString(), 'payment_mode' => 'cash', 'is_interstate' => false,
        'items' => [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => 70, 'gst_rate' => 0]],
    ], $this->apiHeaders())->assertCreated()->json('data.id');

    $enough = $this->getJson('/api/sales/'.$make(10), $this->apiHeaders())->json('data');
    expect($enough['can']['request_shortage'])->toBeFalse()->and($enough['short_items'])->toBe([]);

    $short = $this->getJson('/api/sales/'.$make(12), $this->apiHeaders())->json('data');
    expect($short['can']['request_shortage'])->toBeTrue()->and(array_values($short['short_items']))->toEqual([2]);
});
