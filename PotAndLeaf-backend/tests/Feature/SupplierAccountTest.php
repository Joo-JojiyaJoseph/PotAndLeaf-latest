<?php

/** Supplier page: payments and advance shown separately, advance running balance matches the supplier's balance. */

use App\Actions\Purchases\ConfirmPurchase;
use App\Actions\Purchases\CreatePurchase;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

it('separates payments from advance and explains every rupee of advance', function () {
    $this->createCompanyWithUser(['suppliers.view', 'purchases.create', 'purchases.confirm', 'payments.create', 'products.create']);
    $sup = $this->createSupplier(['name' => 'Green Pots']);
    $product = $this->createProduct(['name' => 'Pot', 'cost_price' => 100]);
    $bill = function (float $rate) use ($sup, $product) {
        $p = app(CreatePurchase::class)->handle($this->company->id, ['supplier_id' => $sup->id, 'purchase_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => $rate]]], $this->user->id);
        return app(ConfirmPurchase::class)->handle($p, $this->user->id);
    };
    $b1 = $bill(1000);
    $b2 = $bill(500);
    $pay = app(PaymentService::class);

    // 1,200 paid against bill 1 (1,000) → 200 extra becomes advance.
    $pay->record($this->company->id, ['supplier_id' => $sup->id, 'payment_date' => now()->toDateString(), 'amount' => 1200, 'mode' => 'bank',
        'allocations' => [['purchase_id' => $b1->id, 'amount' => 1000]]], $this->user->id);
    // 300 paid as advance.
    $pay->record($this->company->id, ['supplier_id' => $sup->id, 'payment_date' => now()->toDateString(), 'amount' => 300, 'mode' => 'cash', 'is_advance' => true], $this->user->id);
    // 450 of the advance used on bill 2.
    $pay->applyAdvance($this->company->id, ['supplier_id' => $sup->id, 'purchase_id' => $b2->id, 'amount' => 450], $this->user->id);

    $acc = $this->getJson("/api/suppliers/{$sup->id}/account", $this->apiHeaders())->assertOk()->json('data');

    expect($acc['summary']['advance_available'])->toEqual(50)
        ->and($acc['summary']['paid_total'])->toEqual(1500)
        ->and($acc['summary']['you_owe'])->toEqual(50)
        ->and(count($acc['payments']))->toBe(2);   // the "used advance" is not shown as a payment
    $bank = collect($acc['payments'])->firstWhere('mode', 'bank');
    expect($bank['bills'][0]['amount'])->toEqual(1000)->and($bank['to_advance'])->toEqual(200);

    $kinds = collect($acc['advance'])->pluck('kind')->sort()->values()->all();
    expect($kinds)->toBe(['advance', 'extra', 'used'])
        ->and($acc['advance'][0]['balance'])->toEqual(50); // newest first, ends at the real balance

    // Advance that cannot be traced shows as one "earlier" line so the total still matches.
    $sup->refresh()->update(['advance_balance' => 470]);
    $acc = $this->getJson("/api/suppliers/{$sup->id}/account", $this->apiHeaders())->json('data');
    expect(collect($acc['advance'])->firstWhere('kind', 'earlier')['in'])->toEqual(420)
        ->and($acc['advance'][0]['balance'])->toEqual(470);
});
