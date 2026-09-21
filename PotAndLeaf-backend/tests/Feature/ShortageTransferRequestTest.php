<?php

use App\Actions\Sales\CreateSale;
use App\Models\Backorder;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'backorder.view', 'backorder.create', 'backorder.fulfill', 'backorder.delete',
        'sales.view', 'sales.create', 'sales.confirm', 'inventory.view',
        'transfers.view',
    ]);
});

function sisterBranchWithSku(object $test, string $sku, float $stock, string $name = 'Sister Branch'): array
{
    $branch = Company::create([
        'name' => $name,
        'code' => 'SIS'.Str::upper(Str::random(3)),
        'is_active' => true,
    ]);
    $product = Product::create([
        'company_id' => $branch->id,
        'sku' => $sku,
        'name' => 'Areca Palm',
        'gst_rate' => 0,
        'mrp' => 500,
        'cost_price' => 200,
        'retail_price' => 400,
        'current_stock' => $stock,
        'opening_stock' => $stock,
        'status' => 'active',
    ]);

    return [$branch, $product];
}

it('creates an inter-company transfer request instead of a backorder when another branch has stock', function () {
    $product = $this->createProduct([
        'sku' => 'PALM-XFER-01',
        'current_stock' => 2,
        'retail_price' => 100,
        'gst_rate' => 0,
    ]);
    [$branch, $sourceProduct] = sisterBranchWithSku($this, 'PALM-XFER-01', 40);
    $customer = $this->createCustomer();
    $localStock = (float) $product->current_stock;
    $sourceStock = (float) $sourceProduct->current_stock;

    $res = $this->postJson('/api/backorders', [
        'customer_id' => $customer->id,
        'order_date' => now()->toDateString(),
        'items' => [
            ['product_id' => $product->id, 'ordered_qty' => 10, 'rate' => 100],
        ],
    ], $this->apiHeaders())->assertCreated();

    expect($res->json('data.backorder'))->toBeNull();
    expect($res->json('data.transfer_requests.0.status'))->toBe('requested');
    expect($res->json('data.transfer_requests.0.from_company_id'))->toBe($branch->id);
    expect($res->json('data.transfer_requests.0.to_company_id'))->toBe($this->company->id);
    expect((float) $res->json('data.transfer_requests.0.items.0.qty'))->toBe(10.0);
    expect($res->json('data.resolution.0.action'))->toBe('transfer');

    expect(Backorder::forCompany($this->company->id)->count())->toBe(0);
    expect(StockTransfer::query()->where('status', 'requested')->count())->toBe(1);
    expect((float) $product->fresh()->current_stock)->toBe($localStock);
    expect((float) $sourceProduct->fresh()->current_stock)->toBe($sourceStock);
});

it('falls back to a backorder when no other company has the sku in stock', function () {
    $product = $this->createProduct([
        'sku' => 'PALM-NONE-01',
        'current_stock' => 1,
        'retail_price' => 100,
        'gst_rate' => 0,
    ]);
    sisterBranchWithSku($this, 'PALM-NONE-01', 0);
    $customer = $this->createCustomer();

    $this->postJson('/api/backorders', [
        'customer_id' => $customer->id,
        'order_date' => now()->toDateString(),
        'items' => [
            ['product_id' => $product->id, 'ordered_qty' => 12, 'rate' => 100],
        ],
    ], $this->apiHeaders())->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.items.0.ordered_qty', 12);

    expect(StockTransfer::count())->toBe(0);
    expect(Backorder::forCompany($this->company->id)->count())->toBe(1);
});

it('splits a request across a transfer and a backorder when the other branch has only part of the qty', function () {
    $product = $this->createProduct([
        'sku' => 'PALM-PART-01',
        'current_stock' => 0,
        'retail_price' => 100,
        'gst_rate' => 0,
    ]);
    sisterBranchWithSku($this, 'PALM-PART-01', 4);
    $customer = $this->createCustomer();

    $res = $this->postJson('/api/backorders', [
        'customer_id' => $customer->id,
        'order_date' => now()->toDateString(),
        'items' => [
            ['product_id' => $product->id, 'ordered_qty' => 10, 'rate' => 100],
        ],
    ], $this->apiHeaders())->assertCreated();

    expect($res->json('data.resolution.0.action'))->toBe('split');
    expect((float) $res->json('data.transfer_requests.0.items.0.qty'))->toBe(4.0);
    expect((float) $res->json('data.backorder.items.0.ordered_qty'))->toBe(6.0);
    expect(Backorder::count())->toBe(1);
    expect(StockTransfer::count())->toBe(1);
});

it('creates a transfer from a draft sale shortage when another branch has the stock', function () {
    $product = $this->createProduct([
        'sku' => 'PALM-SALE-01',
        'current_stock' => 5,
        'retail_price' => 100,
        'gst_rate' => 0,
    ]);
    [$branch] = sisterBranchWithSku($this, 'PALM-SALE-01', 20);
    $customer = $this->createCustomer();

    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [
            ['product_id' => $product->id, 'qty' => 12, 'rate' => 100, 'gst_rate' => 0],
        ],
    ], $this->user->id);

    $res = $this->postJson("/api/sales/{$sale->id}/backorder", [], $this->apiHeaders())
        ->assertCreated();

    expect($res->json('data.backorder'))->toBeNull();
    expect((float) $res->json('data.transfer_requests.0.items.0.qty'))->toBe(7.0);
    expect($res->json('data.transfer_requests.0.from_company_id'))->toBe($branch->id);
    expect(Backorder::count())->toBe(0);
});

it('rejects a shortage request with no quantity', function () {
    $product = $this->createProduct([
        'sku' => 'PALM-QTY-01',
        'current_stock' => 0,
        'retail_price' => 100,
        'gst_rate' => 0,
    ]);
    $customer = $this->createCustomer();

    $this->postJson('/api/backorders', [
        'customer_id' => $customer->id,
        'order_date' => now()->toDateString(),
        'items' => [
            ['product_id' => $product->id, 'ordered_qty' => 0, 'rate' => 100],
        ],
    ], $this->apiHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['items.0.ordered_qty']);
});

it('picks a single branch that can cover the full qty instead of splitting', function () {
    $product = $this->createProduct([
        'sku' => 'PALM-PICK-01',
        'current_stock' => 0,
        'retail_price' => 50,
        'gst_rate' => 0,
    ]);
    sisterBranchWithSku($this, 'PALM-PICK-01', 3, 'Small Branch');
    [$big] = sisterBranchWithSku($this, 'PALM-PICK-01', 25, 'Large Branch');
    $customer = $this->createCustomer();

    $res = $this->postJson('/api/backorders', [
        'customer_id' => $customer->id,
        'order_date' => now()->toDateString(),
        'items' => [
            ['product_id' => $product->id, 'ordered_qty' => 10, 'rate' => 50],
        ],
    ], $this->apiHeaders())->assertCreated();

    expect($res->json('data.transfer_requests'))->toHaveCount(1);
    expect($res->json('data.transfer_requests.0.from_company_id'))->toBe($big->id);
    expect((float) $res->json('data.transfer_requests.0.items.0.qty'))->toBe(10.0);
    expect($res->json('data.backorder'))->toBeNull();
});
