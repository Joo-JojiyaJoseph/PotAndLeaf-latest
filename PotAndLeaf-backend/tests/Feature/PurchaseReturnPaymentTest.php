<?php

/**
 * Update 64: an unpaid purchase that had goods sent back — paying it asks
 * only for what is left after the return.
 */

use App\Actions\PurchaseReturns\ConfirmPurchaseReturn;
use App\Actions\PurchaseReturns\CreatePurchaseReturn;
use App\Actions\Purchases\ConfirmPurchase;
use App\Actions\Purchases\CreatePurchase;
use App\Http\Resources\PurchaseResource;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['payments.view', 'payments.create', 'purchases.view']);
    $this->supplier = $this->createSupplier(['outstanding' => 0, 'advance_balance' => 0]);
    $this->pot = $this->createProduct(['current_stock' => 0, 'gst_rate' => 0]);
    $this->purchase = app(ConfirmPurchase::class)->handle(app(CreatePurchase::class)->handle($this->company->id, [
        'supplier_id' => $this->supplier->id, 'purchase_date' => now()->toDateString(), 'is_interstate' => false,
        'items' => [['product_id' => $this->pot->id, 'qty' => 10, 'rate' => 100, 'gst_rate' => 0]],
    ], $this->user->id), $this->user->id); // ₹1,000, unpaid
    $ret = app(CreatePurchaseReturn::class)->handle($this->company->id, [
        'purchase_id' => $this->purchase->id, 'return_date' => now()->toDateString(),
        'items' => [['purchase_item_id' => $this->purchase->items->first()->id, 'qty' => 4]],
    ], $this->user->id);
    app(ConfirmPurchaseReturn::class)->handle($ret, $this->user->id); // ₹400 back
});

it('shows the balance after the return on the bill and in payables', function () {
    $res = (new PurchaseResource($this->purchase->fresh()))->toArray(request());
    expect($res['returned'])->toBe(400.0)->and($res['balance'])->toBe(600.0)->and($res['payment_status'])->toBe('unpaid');

    $row = collect(app(PaymentService::class)->payables($this->company->id, $this->supplier->id))->firstWhere('id', $this->purchase->id);
    expect($row['returned'])->toBe(400.0)->and($row['balance'])->toBe(600.0);
    expect((float) $this->supplier->fresh()->outstanding)->toBe(600.0);
});

it('pays only what is left; then the bill is paid', function () {
    $this->postJson('/api/supplier-payments', [
        'supplier_id' => $this->supplier->id, 'purchase_id' => $this->purchase->id, 'amount' => 1000,
        'payment_date' => now()->toDateString(), 'mode' => 'cash',
    ], $this->apiHeaders())->assertStatus(422)->assertJsonValidationErrors('amount');

    $this->postJson('/api/supplier-payments', [
        'supplier_id' => $this->supplier->id, 'purchase_id' => $this->purchase->id, 'amount' => 600,
        'payment_date' => now()->toDateString(), 'mode' => 'cash',
    ], $this->apiHeaders())->assertCreated();

    $res = (new PurchaseResource($this->purchase->fresh()))->toArray(request());
    expect($res['balance'])->toBe(0.0)->and($res['payment_status'])->toBe('paid');
    expect((float) $this->supplier->fresh()->outstanding)->toBe(0.0);
});
