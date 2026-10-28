<?php

/**
 * Stock on hand, batches/barcodes and the sale screen always show the same number,
 * and a purchase order must be sent before it becomes a purchase.
 */

use App\Models\ProductBatch;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['po.view', 'po.create', 'po.send', 'po.convert', 'purchases.create', 'rental.view', 'rental.create']);
});

function batchSum(string $productId): float
{
    return (float) ProductBatch::where('product_id', $productId)->sum('remaining_qty');
}

it('puts stock that comes back without a batch into batches again', function () {
    $p = $this->createProduct(['current_stock' => 0, 'cost_price' => 40]);
    $inv = app(InventoryService::class);

    // Stock-count style gain with no batch at all → a new ADJ batch with a barcode.
    DB::transaction(function () use ($inv, $p) {
        $inv->post(product: $p, direction: 'in', qty: 12, unitCost: 40, referenceType: 'stock-count', note: 'count');
        $p->save();
    });
    $adj = ProductBatch::where('product_id', $p->id)->first();
    expect(batchSum($p->id))->toBe(12.0)->and($adj->batch_no)->toStartWith('ADJ-')->and($adj->barcode)->toStartWith('PLA');

    // 5 go out without touching batches (like a rental), then come back.
    DB::transaction(function () use ($inv, $p) {
        $p->refresh();
        $inv->post(product: $p, direction: 'out', qty: 5, unitCost: 40, referenceType: 'test');
        $p->save();
    });
    expect(batchSum($p->id))->toBe(7.0);
    DB::transaction(function () use ($inv, $p) {
        $p->refresh();
        $inv->post(product: $p, direction: 'in', qty: 5, unitCost: 40, referenceType: 'rental-cancel');
        $p->save();
    });
    expect((float) $p->fresh()->current_stock)->toBe(12.0)
        ->and(batchSum($p->id))->toBe(12.0)
        ->and(ProductBatch::where('product_id', $p->id)->count())->toBe(1);   // refilled, no extra batch
});

it('converts only a purchase order that was sent', function () {
    $s = $this->createSupplier();
    $product = $this->createProduct();
    $h = $this->apiHeaders();
    $po = $this->postJson('/api/purchase-orders', ['supplier_id' => $s->id, 'po_date' => now()->toDateString(),
        'items' => [['product_id' => $product->id, 'qty' => 5, 'rate' => 100]]], $h)->assertCreated();
    expect($po->json('data.can.convert'))->toBeFalse();
    $id = $po->json('data.id');
    $this->postJson("/api/purchase-orders/$id/convert", [], $h)->assertStatus(422);
    $this->postJson("/api/purchase-orders/$id/send", [], $h)->assertOk()->assertJsonPath('data.can.convert', true);
    $this->postJson("/api/purchase-orders/$id/convert", [], $h)->assertOk();
});
