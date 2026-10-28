<?php

/**
 * Supplier: opening balance ₹500, bill ₹9,850, two "on account" payments of ₹1,000.
 * Owed = 500 + 9,850 − 2,000 = 8,350. The on-account money pays the ₹500 opening
 * balance first, then ₹1,500 of the bill → bill balance 8,350 (matches what is owed).
 */

use App\Models\Purchase;
use App\Models\SupplierPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['purchases.view', 'purchases.create', 'purchases.confirm', 'payments.view', 'payments.create', 'payments.delete', 'suppliers.view', 'suppliers.update']);
    $this->supplier = $this->createSupplier(['opening_balance' => 500, 'outstanding' => 500, 'credit_days' => 20]);
    $this->product = $this->createProduct(['gst_rate' => 0]);
});

function onAcctBill(object $t): Purchase
{
    $id = $t->postJson('/api/purchases', [
        'supplier_id' => $t->supplier->id, 'purchase_date' => now()->toDateString(), 'is_interstate' => false,
        'items' => [['product_id' => $t->product->id, 'qty' => 1, 'rate' => 9850, 'discount' => 0, 'gst_rate' => 0]],
    ], $t->apiHeaders())->assertCreated()->json('data.id');
    $t->postJson("/api/purchases/{$id}/confirm", [], $t->apiHeaders())->assertOk();

    return Purchase::findOrFail($id);
}

function onAcctPay(object $t, float $amount, ?string $purchaseId = null)
{
    return $t->postJson('/api/supplier-payments', array_filter([
        'supplier_id' => $t->supplier->id, 'amount' => $amount, 'mode' => 'cash',
        'payment_date' => now()->toDateString(), 'purchase_id' => $purchaseId,
    ]), $t->apiHeaders());
}

function onAcctBillBalance(object $t, string $id): float
{
    return (float) collect($t->getJson('/api/supplier-payments/payables?supplier_id='.$t->supplier->id, $t->apiHeaders())->json('data.payables'))
        ->firstWhere('id', $id)['balance'];
}

it('pays old dues first, then the oldest bill, and never lets you pay more than you owe', function () {
    $bill = onAcctBill($this);
    onAcctPay($this, 1000)->assertCreated();
    onAcctPay($this, 1000)->assertCreated();

    expect((float) $this->supplier->fresh()->outstanding)->toBe(8350.0)
        ->and(onAcctBillBalance($this, $bill->id))->toBe(8350.0)
        // First payment: ₹500 opening balance + ₹500 on the bill; second: ₹1,000 on the bill.
        ->and(collect($this->getJson('/api/suppliers/'.$this->supplier->id.'/account', $this->apiHeaders())->json('data.payments'))->pluck('to_old_dues')->sort()->values()->all())->toEqual([0, 500])
        ->and($bill->fresh()->paymentStatus())->toBe('partial');

    // The payment form shows how 8,350 is made up.
    $owed = $this->getJson('/api/supplier-payments/owed?supplier_id='.$this->supplier->id, $this->apiHeaders())->assertOk()->json('data');
    expect($owed['opening_balance'])->toEqual(500)->and($owed['bills_total'])->toEqual(9850)
        ->and($owed['paid'])->toEqual(2000)->and($owed['adjustment'])->toEqual(0)->and($owed['you_owe'])->toEqual(8350);

    // Paying the old bill figure (9,850) is refused: only 8,350 is owed.
    onAcctPay($this, 9850, $bill->id)->assertStatus(422);
    expect((float) $this->supplier->fresh()->outstanding)->toBe(8350.0);

    // Removing one on-account payment puts ₹1,000 back on the bill.
    $p2 = SupplierPayment::where('supplier_id', $this->supplier->id)->latest('created_at')->orderByDesc('payment_no')->first();
    $this->deleteJson("/api/supplier-payments/{$p2->id}", [], $this->apiHeaders())->assertOk();
    expect((float) $this->supplier->fresh()->outstanding)->toBe(9350.0)
        ->and(onAcctBillBalance($this, $bill->id))->toBe(9350.0);

    // Pay the bill's balance: everything is cleared.
    onAcctPay($this, 9350, $bill->id)->assertCreated();
    expect((float) $this->supplier->fresh()->outstanding)->toBe(0.0)
        ->and($bill->fresh()->paymentStatus())->toBe('paid');
});

it('a payment on account that only covers the opening balance leaves the bill untouched', function () {
    $bill = onAcctBill($this);
    onAcctPay($this, 500)->assertCreated();
    expect(onAcctBillBalance($this, $bill->id))->toBe(9850.0)
        ->and((float) $this->supplier->fresh()->outstanding)->toBe(9850.0);
});
