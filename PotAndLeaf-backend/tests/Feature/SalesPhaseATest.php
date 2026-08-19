<?php

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Models\CompanySetting;
use App\Models\CustomerReceipt;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'sales.view', 'sales.create', 'sales.confirm', 'sales.delete',
        'sales.cancel_request', 'sales.cancel_approve', 'sales.whatsapp',
    ]);
});

function phaseASale(object $test, array $overrides = [], array $itemOverrides = []): array
{
    $product = $test->createProduct(['current_stock' => 50, 'retail_price' => 100, 'gst_rate' => 0]);
    $customer = $test->createCustomer(['phone' => '9876543210']);

    $payload = array_merge([
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'bill_kind' => 'tax_invoice',
        'items' => [
            array_merge([
                'product_id' => $product->id,
                'qty' => 2,
                'rate' => 100,
                'gst_rate' => 0,
                'discount' => 0,
            ], $itemOverrides),
        ],
    ], $overrides);

    $sale = app(CreateSale::class)->handle($test->company->id, $payload, $test->user->id);

    return compact('sale', 'product', 'customer', 'payload');
}

function phaseASetting(object $test, string $key, string $value): void
{
    CompanySetting::query()->updateOrCreate(
        ['company_id' => $test->company->id, 'key' => $key],
        ['value' => $value],
    );
}

it('issues proforma without reducing stock', function () {
    ['sale' => $sale, 'product' => $product] = phaseASale($this, ['bill_kind' => 'proforma']);
    $stockBefore = (float) $product->current_stock;

    $confirmed = app(ConfirmSale::class)->handle($sale, $this->user->id);

    expect($confirmed->status)->toBe('proforma');
    expect($confirmed->bill_kind)->toBe('proforma');
    expect((float) $product->fresh()->current_stock)->toBe($stockBefore);
});

it('converts proforma to tax invoice draft and confirms with stock movement', function () {
    ['sale' => $sale, 'product' => $product] = phaseASale($this, ['bill_kind' => 'proforma']);
    app(ConfirmSale::class)->handle($sale, $this->user->id);
    $stockBefore = (float) $product->current_stock;

    $this->postJson("/api/sales/{$sale->id}/convert-proforma", [], $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.bill_kind', 'tax_invoice');

    $this->postJson("/api/sales/{$sale->id}/confirm", [], $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');

    expect((float) $product->fresh()->current_stock)->toBe($stockBefore - 2);
});

it('blocks direct cancel of confirmed sales when approval is required', function () {
    ['sale' => $sale] = phaseASale($this);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    $this->deleteJson("/api/sales/{$sale->id}", [], $this->apiHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('approves a cancellation request and reverses stock', function () {
    ['sale' => $sale, 'product' => $product] = phaseASale($this);
    app(ConfirmSale::class)->handle($sale, $this->user->id);
    $stockBefore = (float) $product->fresh()->current_stock;

    $this->postJson("/api/sales/{$sale->id}/cancel-request", ['reason' => 'Wrong customer billed'], $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.cancel_reason', 'Wrong customer billed');

    $this->postJson("/api/sales/{$sale->id}/cancel-approve", [], $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect((float) $product->fresh()->current_stock)->toBe($stockBefore + 2);
});

it('rejects a cancellation request and keeps the sale confirmed', function () {
    ['sale' => $sale] = phaseASale($this);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    $this->postJson("/api/sales/{$sale->id}/cancel-request", ['reason' => 'Duplicate entry'], $this->apiHeaders())
        ->assertOk();

    $this->postJson("/api/sales/{$sale->id}/cancel-reject", ['reason' => 'Already shipped'], $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');

    expect($sale->fresh()->cancel_requested_at)->toBeNull();
});

it('allows direct cancel when approval setting is disabled', function () {
    phaseASetting($this, 'sale_cancel_requires_approval', '0');
    ['sale' => $sale] = phaseASale($this);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    $this->deleteJson("/api/sales/{$sale->id}", [], $this->apiHeaders())
        ->assertOk();
    expect($sale->fresh()->status)->toBe('cancelled');
});

it('sends sales invoice via WhatsApp when enabled', function () {
    $this->mock(WhatsAppService::class, function ($mock) {
        $mock->shouldReceive('sendMessage')->once()->andReturn(['success' => true, 'message' => 'Sent', 'provider' => 'test']);
    });

    ['sale' => $sale] = phaseASale($this);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    $this->postJson("/api/sales/{$sale->id}/whatsapp", [], $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('message', 'Sent');
});

it('skips discount ceiling validation for complimentary bills', function () {
    phaseASetting($this, 'discount_ceiling_percent', '5');
    $product = $this->createProduct(['current_stock' => 50, 'retail_price' => 100, 'gst_rate' => 0]);
    $customer = $this->createCustomer();

    $this->postJson('/api/sales', [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'bill_kind' => 'complimentary',
        'is_interstate' => false,
        'amount_paid' => 0,
        'items' => [
            ['product_id' => $product->id, 'qty' => 1, 'rate' => 100, 'gst_rate' => 0, 'discount' => 50],
        ],
    ], $this->apiHeaders())->assertCreated()->assertJsonPath('data.bill_kind', 'complimentary');
});

it('confirms complimentary bill with stock movement and no receipt when unpaid', function () {
    ['sale' => $sale, 'product' => $product] = phaseASale($this, [
        'bill_kind' => 'complimentary',
        'payment_mode' => 'cash',
        'amount_paid' => 0,
    ], ['rate' => 100, 'discount' => 100]);
    $stockBefore = (float) $product->current_stock;

    app(ConfirmSale::class)->handle($sale, $this->user->id);

    expect($sale->fresh()->status)->toBe('confirmed');
    expect((float) $product->fresh()->current_stock)->toBe($stockBefore - 2);
    expect(CustomerReceipt::forCompany($this->company->id)->count())->toBe(0);
});
