<?php

/**
 * A purchase made from a purchase order keeps that order's supplier;
 * a direct purchase can pick any existing supplier.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['purchases.view', 'purchases.create', 'purchases.update', 'po.view', 'po.create', 'po.update', 'po.send', 'po.convert', 'products.create', 'suppliers.view', 'suppliers.create']);
});

it('locks the supplier on a purchase made from a purchase order', function () {
    $h = $this->apiHeaders();
    $a = $this->createSupplier(['name' => 'Order Supplier']);
    $b = $this->createSupplier(['name' => 'Other Supplier']);
    $product = $this->createProduct(['name' => 'Pot']);

    $po = $this->postJson('/api/purchase-orders', ['supplier_id' => $a->id, 'po_date' => now()->toDateString(),
        'items' => [['product_id' => $product->id, 'qty' => 5, 'rate' => 100]]], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/purchase-orders/$po/send", [], $h)->assertOk();
    $purchaseId = $this->postJson("/api/purchase-orders/$po/convert", [], $h)->assertOk()->json('data.purchase_id');

    $show = $this->getJson("/api/purchases/$purchaseId", $h)->assertOk();
    expect($show->json('data.purchase_order.po_no'))->toStartWith('PO-');

    $edit = ['purchase_date' => now()->toDateString(), 'items' => [['product_id' => $product->id, 'qty' => 6, 'rate' => 100]]];
    $this->putJson("/api/purchases/$purchaseId", $edit + ['supplier_id' => $b->id], $h)
        ->assertStatus(422)->assertJsonPath('errors.supplier_id.0', fn ($m) => str_contains($m, 'cannot be changed'));
    $this->putJson("/api/purchases/$purchaseId", $edit + ['supplier_id' => $a->id], $h)->assertOk();
});

it('lets a direct purchase choose and change to any existing supplier', function () {
    $h = $this->apiHeaders();
    $a = $this->createSupplier(['name' => 'First']);
    $b = $this->createSupplier(['name' => 'Second']);
    $product = $this->createProduct(['name' => 'Pot']);
    $id = $this->postJson('/api/purchases', ['supplier_id' => $a->id, 'purchase_date' => now()->toDateString(),
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 50]]], $h)->assertCreated()->json('data.id');
    expect($this->getJson("/api/purchases/$id", $h)->json('data.purchase_order'))->toBeNull();
    $this->putJson("/api/purchases/$id", ['supplier_id' => $b->id, 'purchase_date' => now()->toDateString(),
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 50]]], $h)->assertOk();
    expect($this->getJson("/api/purchases/$id", $h)->json('data.supplier.id'))->toBe($b->id);
    // A supplier that does not exist is refused.
    $this->postJson('/api/purchases', ['supplier_id' => (string) \Illuminate\Support\Str::uuid(), 'purchase_date' => now()->toDateString(),
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 50]]], $h)->assertStatus(422);
});
