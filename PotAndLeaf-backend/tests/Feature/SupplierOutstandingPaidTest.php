<?php

/**
 * Supplier "Outstanding (from before)" goes down when it is paid.
 * Outstanding 10,000, bill 5,000. Pay 3,000 "on account" → 7,000 of the old
 * outstanding is left; the bill is still 5,000. Editing the supplier shows 7,000;
 * saving 6,000 means the supplier is now owed 6,000 old + 5,000 bill = 11,000.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['purchases.view', 'purchases.create', 'purchases.confirm', 'payments.view', 'payments.create', 'suppliers.view', 'suppliers.update']);
    $this->supplier = $this->createSupplier(['opening_balance' => 10000, 'outstanding' => 10000]);
    $this->product = $this->createProduct(['gst_rate' => 0]);
});

it('lowers the outstanding from before as it is paid, and keeps it right when edited', function () {
    $id = $this->postJson('/api/purchases', [
        'supplier_id' => $this->supplier->id, 'purchase_date' => now()->toDateString(), 'is_interstate' => false,
        'items' => [['product_id' => $this->product->id, 'qty' => 1, 'rate' => 5000, 'discount' => 0, 'gst_rate' => 0]],
    ], $this->apiHeaders())->assertCreated()->json('data.id');
    $this->postJson("/api/purchases/{$id}/confirm", [], $this->apiHeaders())->assertOk();

    $show = fn () => $this->getJson('/api/suppliers/'.$this->supplier->id, $this->apiHeaders())->assertOk()->json('data');
    expect($show()['opening_left'])->toEqual(10000)->and($show()['opening_paid'])->toEqual(0);

    $this->postJson('/api/supplier-payments', [
        'supplier_id' => $this->supplier->id, 'amount' => 3000, 'mode' => 'cash', 'payment_date' => now()->toDateString(),
    ], $this->apiHeaders())->assertCreated();

    $s = $show();
    expect($s['opening_left'])->toEqual(7000)->and($s['opening_paid'])->toEqual(3000)
        ->and($s['outstanding'])->toEqual(12000)
        ->and($this->getJson('/api/supplier-payments/owed?supplier_id='.$this->supplier->id, $this->apiHeaders())->json('data.opening_left'))->toEqual(7000);

    // Saving the form without changing the box (7,000) changes nothing.
    $this->putJson('/api/suppliers/'.$this->supplier->id, ['name' => $this->supplier->name, 'status' => 'active', 'address' => 'Main road', 'opening_left' => 7000], $this->apiHeaders())->assertOk();
    expect((float) $this->supplier->fresh()->outstanding)->toBe(12000.0)->and((float) $this->supplier->fresh()->opening_balance)->toBe(10000.0);

    // Correct it to 6,000 still to pay.
    $this->putJson('/api/suppliers/'.$this->supplier->id, ['name' => $this->supplier->name, 'status' => 'active', 'address' => 'Main road', 'opening_left' => 6000], $this->apiHeaders())->assertOk();
    $s = $show();
    expect($s['opening_left'])->toEqual(6000)->and($s['outstanding'])->toEqual(11000);

    // Paying more than the old outstanding: the rest goes to the bill; nothing old is left.
    $this->postJson('/api/supplier-payments', [
        'supplier_id' => $this->supplier->id, 'amount' => 8000, 'mode' => 'cash', 'payment_date' => now()->toDateString(),
    ], $this->apiHeaders())->assertCreated();
    $s = $show();
    expect($s['opening_left'])->toEqual(0)->and($s['outstanding'])->toEqual(3000);
});
