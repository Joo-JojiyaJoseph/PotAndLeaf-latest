<?php

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['po.view', 'po.create', 'po.send', 'po.convert', 'po.delete']);
});

function phaseDSupplier(object $test, string $name): Supplier
{
    return Supplier::create([
        'company_id' => $test->company->id,
        'supplier_code' => 'SUP-'.strtoupper(substr($name, 0, 3)),
        'name' => $name,
        'status' => 'active',
        'outstanding' => 0,
    ]);
}

function phaseDProduct(object $test, Supplier $supplier, array $overrides = [])
{
    $product = $test->createProduct(array_merge([
        'current_stock' => 5,
        'reorder_level' => 20,
        'cost_price' => 100,
        'gst_rate' => 5,
    ], $overrides));

    $product->suppliers()->attach($supplier->id, ['supplier_price' => 100, 'is_primary' => true]);

    return $product;
}

it('returns reorder report grouped by supplier', function () {
    $s1 = phaseDSupplier($this, 'Green Valley');
    $s2 = phaseDSupplier($this, 'Soil Works');
    phaseDProduct($this, $s1, ['sku' => 'LOW-1', 'current_stock' => 3]);
    phaseDProduct($this, $s1, ['sku' => 'LOW-2', 'current_stock' => 10]);
    phaseDProduct($this, $s2, ['sku' => 'LOW-3', 'current_stock' => 0]);
    $this->createProduct(['sku' => 'OK-1', 'current_stock' => 50, 'reorder_level' => 10]);
    $this->createProduct(['sku' => 'NO-SUP', 'current_stock' => 2, 'reorder_level' => 15]);

    $res = $this->getJson('/api/purchase-orders/reorder-report', $this->apiHeaders())
        ->assertOk();

    expect($res->json('data.summary.product_count'))->toBe(4);
    expect($res->json('data.summary.supplier_count'))->toBe(2);
    expect($res->json('data.summary.unassigned_count'))->toBe(1);
    expect(collect($res->json('data.suppliers'))->pluck('supplier_name')->sort()->values()->all())
        ->toBe(['Green Valley', 'Soil Works']);
    expect($res->json('data.suppliers.0.items'))->toHaveCount(2);
});

it('creates separate draft POs per supplier from batch endpoint', function () {
    $s1 = phaseDSupplier($this, 'Green Valley');
    $s2 = phaseDSupplier($this, 'Soil Works');
    $p1 = phaseDProduct($this, $s1, ['sku' => 'BATCH-1']);
    $p2 = phaseDProduct($this, $s2, ['sku' => 'BATCH-2']);

    $res = $this->postJson('/api/purchase-orders/batch-from-reorder', [
        'po_date' => now()->toDateString(),
        'orders' => [
            [
                'supplier_id' => $s1->id,
                'items' => [
                    ['product_id' => $p1->id, 'qty' => 10, 'rate' => 100, 'gst_rate' => 5],
                ],
            ],
            [
                'supplier_id' => $s2->id,
                'items' => [
                    ['product_id' => $p2->id, 'qty' => 25, 'rate' => 100, 'gst_rate' => 5],
                ],
            ],
        ],
    ], $this->apiHeaders())->assertCreated();

    expect($res->json('data'))->toHaveCount(2);
    expect(PurchaseOrder::count())->toBe(2);
    expect((float) PurchaseOrder::where('supplier_id', $s1->id)->first()
        ->items->first()->qty)->toBe(10.0);
});

it('rejects batch when no lines have quantity', function () {
    $s1 = phaseDSupplier($this, 'Green Valley');
    $p1 = phaseDProduct($this, $s1);

    $this->postJson('/api/purchase-orders/batch-from-reorder', [
        'po_date' => now()->toDateString(),
        'orders' => [
            ['supplier_id' => $s1->id, 'items' => [['product_id' => $p1->id, 'qty' => 0, 'rate' => 100]]],
        ],
    ], $this->apiHeaders())->assertStatus(422);
});

it('includes shortfall in flat suggestions', function () {
    $s1 = phaseDSupplier($this, 'Green Valley');
    phaseDProduct($this, $s1, ['current_stock' => 8, 'reorder_level' => 20]);

    $res = $this->getJson('/api/purchase-orders/suggestions', $this->apiHeaders())->assertOk();

    expect((float) $res->json('data.suggestions.0.shortfall'))->toBe(12.0);
    expect((float) $res->json('data.suggestions.0.suggested_qty'))->toBe(32.0);
});
