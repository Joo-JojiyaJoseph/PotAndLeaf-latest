<?php

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Models\CustomerReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

it('creates a customer receipt immediately when a POS sale is confirmed', function () {
    $this->createCompanyWithUser();
    $customer = $this->createCustomer();
    $product = $this->createProduct(['current_stock' => 50, 'retail_price' => 100, 'gst_rate' => 0]);

    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [
            ['product_id' => $product->id, 'qty' => 2, 'rate' => 100, 'gst_rate' => 0, 'discount' => 0],
        ],
    ], $this->user->id);

    expect($sale->status)->toBe('draft');
    expect(CustomerReceipt::forCompany($this->company->id)->count())->toBe(0);

    $confirmed = app(ConfirmSale::class)->handle($sale, $this->user->id);

    expect($confirmed->status)->toBe('confirmed');
    expect(CustomerReceipt::forCompany($this->company->id)->count())->toBe(1);

    $receipt = CustomerReceipt::forCompany($this->company->id)->first();
    expect($receipt->sale_id)->toBe($confirmed->id);
    expect($receipt->customer_id)->toBe($customer->id);
    expect((float) $receipt->amount)->toBe((float) $confirmed->amount_paid);
    expect($receipt->mode)->toBe('cash');
    expect((float) $customer->fresh()->outstanding)->toBe(0.0);
});

it('records upi tender as a receipt on confirm via the sales API', function () {
    $this->createCompanyWithUser();
    $customer = $this->createCustomer();
    $product = $this->createProduct(['current_stock' => 20, 'gst_rate' => 0]);

    $create = $this->postJson('/api/sales', [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'upi',
        'is_interstate' => false,
        'items' => [
            ['product_id' => $product->id, 'qty' => 1, 'rate' => 150, 'gst_rate' => 0],
        ],
    ], $this->apiHeaders())->assertCreated();

    $saleId = $create->json('data.id');

    $this->postJson("/api/sales/{$saleId}/confirm", [], $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');

    $this->assertDatabaseHas('customer_receipts', [
        'company_id' => $this->company->id,
        'sale_id' => $saleId,
        'customer_id' => $customer->id,
        'mode' => 'upi',
    ]);
});

it('does not create a receipt for unpaid credit sales on confirm', function () {
    $this->createCompanyWithUser();
    $customer = $this->createCustomer();
    $product = $this->createProduct(['current_stock' => 20, 'gst_rate' => 0]);

    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'credit',
        'amount_paid' => 0,
        'is_interstate' => false,
        'items' => [
            ['product_id' => $product->id, 'qty' => 1, 'rate' => 200, 'gst_rate' => 0],
        ],
    ], $this->user->id);

    app(ConfirmSale::class)->handle($sale, $this->user->id);

    expect(CustomerReceipt::forCompany($this->company->id)->count())->toBe(0);
    expect((float) $customer->fresh()->outstanding)->toBe((float) $sale->fresh()->grand_total);
});
