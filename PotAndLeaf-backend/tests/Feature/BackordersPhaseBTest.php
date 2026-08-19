<?php

use App\Actions\Sales\CreateSale;
use App\Models\Backorder;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockTransfer;
use App\Services\BackorderService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'backorder.view', 'backorder.create', 'backorder.fulfill', 'backorder.delete',
        'sales.view', 'sales.create', 'sales.confirm', 'inventory.view',
    ]);
});

function phaseBProduct(object $test, array $overrides = []): Product
{
    return $test->createProduct(array_merge([
        'sku' => 'PALM-BO-01',
        'current_stock' => 10,
        'retail_price' => 100,
        'gst_rate' => 0,
    ], $overrides));
}

it('creates a backorder without deducting stock', function () {
    $product = phaseBProduct($this);
    $customer = $this->createCustomer();
    $stockBefore = (float) $product->current_stock;

    $this->postJson('/api/backorders', [
        'customer_id' => $customer->id,
        'order_date' => now()->toDateString(),
        'items' => [
            ['product_id' => $product->id, 'ordered_qty' => 25, 'rate' => 100],
        ],
    ], $this->apiHeaders())->assertCreated()
        ->assertJsonPath('data.status', 'open');

    expect((float) $product->fresh()->current_stock)->toBe($stockBefore);
});

it('partially fulfills a backorder and creates a draft sale', function () {
    $product = phaseBProduct($this, ['current_stock' => 15]);
    $customer = $this->createCustomer();

    $order = app(BackorderService::class)->create($this->company->id, [
        'customer_id' => $customer->id,
        'order_date' => now()->toDateString(),
        'items' => [
            ['product_id' => $product->id, 'ordered_qty' => 40, 'rate' => 100],
        ],
    ]);

    $line = $order->items->first();

    $this->postJson("/api/backorders/{$order->id}/fulfill", [
        'items' => [['id' => $line->id, 'qty' => 10]],
    ], $this->apiHeaders())->assertOk()
        ->assertJsonPath('data.order.status', 'partial');

    expect((float) $line->fresh()->fulfilled_qty)->toBe(10.0);
    expect((float) $line->fresh()->pendingQty())->toBe(30.0);
});

it('creates a backorder from a draft sale shortage', function () {
    $product = phaseBProduct($this, ['current_stock' => 5]);
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

    $this->postJson("/api/sales/{$sale->id}/backorder", [], $this->apiHeaders())
        ->assertCreated()
        ->assertJsonPath('data.items.0.ordered_qty', 7);

    expect(Backorder::forCompany($this->company->id)->count())->toBe(1);
});

it('returns cross-branch stock by sku', function () {
    $this->seedPermissions();
    $branch = Company::create(['name' => 'Branch B', 'code' => 'BRB', 'is_active' => true]);
    $this->user->companies()->attach($branch->id);

    phaseBProduct($this, ['current_stock' => 8]);
    Product::create([
        'company_id' => $branch->id,
        'sku' => 'PALM-BO-01',
        'name' => 'Areca Palm',
        'gst_rate' => 0,
        'mrp' => 500,
        'cost_price' => 200,
        'retail_price' => 400,
        'current_stock' => 42,
        'opening_stock' => 42,
        'status' => 'active',
    ]);

    $this->getJson('/api/inventory/stock/cross-branch?sku=PALM-BO-01', $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.sku', 'PALM-BO-01')
        ->assertJsonCount(2, 'data.branches');
});

it('includes in-transit qty in cross-branch stock', function () {
    $product = phaseBProduct($this);
    StockTransfer::create([
        'company_id' => $this->company->id,
        'to_company_id' => $this->company->id,
        'transfer_type' => 'inter_company',
        'transfer_no' => 'TRF-000001',
        'transfer_date' => now()->toDateString(),
        'status' => 'in_transit',
        'dispatched_at' => now(),
    ])->items()->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'qty' => 5,
        'approved_qty' => 5,
    ]);

    $data = app(InventoryService::class)->crossBranchStock($this->user, $this->company->id, sku: 'PALM-BO-01');

    expect(collect($data['branches'])->firstWhere('is_current_branch', true)['in_transit_in'])->toBe(5.0);
});

it('cancels a backorder and moves pending qty to cancelled', function () {
    $product = phaseBProduct($this);
    $customer = $this->createCustomer();
    $order = app(BackorderService::class)->create($this->company->id, [
        'customer_id' => $customer->id,
        'order_date' => now()->toDateString(),
        'items' => [['product_id' => $product->id, 'ordered_qty' => 20, 'rate' => 50]],
    ]);

    $this->deleteJson("/api/backorders/{$order->id}", [], $this->apiHeaders())->assertOk();
    $line = $order->items()->first()->fresh();
    expect($order->fresh()->status)->toBe('cancelled');
    expect((float) $line->cancelled_qty)->toBe(20.0);
});

it('allows manager role to list backorders in a second company', function () {
    $this->seedPermissions();
    $branch = Company::create(['name' => 'Branch MKD', 'code' => 'MKD', 'is_active' => true]);
    $this->user->companies()->attach($branch->id);

    $manager = Role::create(['name' => 'Manager MKD', 'slug' => 'manager-mkd-test', 'is_system' => false]);
    $manager->permissions()->sync(
        Permission::where('name', 'like', 'backorder.%')->pluck('id'),
    );
    $this->user->roles()->attach($manager->id, ['company_id' => $branch->id]);

    $product = Product::create([
        'company_id' => $branch->id,
        'sku' => 'MKD-BO-1',
        'name' => 'Branch Product',
        'gst_rate' => 0,
        'mrp' => 100,
        'cost_price' => 40,
        'retail_price' => 80,
        'current_stock' => 10,
        'opening_stock' => 10,
        'status' => 'active',
    ]);
    $customer = Customer::create([
        'company_id' => $branch->id,
        'customer_code' => 'C-MKD-1',
        'name' => 'Branch Customer',
        'type' => 'retail',
        'status' => 'active',
        'outstanding' => 0,
        'loyalty_points' => 0,
    ]);

    app(BackorderService::class)->create($branch->id, [
        'customer_id' => $customer->id,
        'order_date' => now()->toDateString(),
        'items' => [['product_id' => $product->id, 'ordered_qty' => 5, 'rate' => 50]],
    ]);

    $headers = array_merge($this->apiHeaders(), ['X-Company-Id' => (string) $branch->id]);

    $this->getJson('/api/backorders', $headers)->assertOk();
});
