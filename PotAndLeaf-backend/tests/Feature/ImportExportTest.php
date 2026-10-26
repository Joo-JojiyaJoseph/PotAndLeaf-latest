<?php

/**
 * Spreadsheet import (products, customers, suppliers) and list filters.
 *
 * Run: php artisan test tests/Feature/ImportExportTest.php
 */

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'products.view', 'products.create', 'customers.view', 'customers.view_all', 'customers.create',
        'suppliers.view', 'suppliers.create', 'sales.view', 'sales.view_all', 'sales.create', 'sales.confirm',
    ]);
});

it('checks product rows first, then imports them with new categories, units and opening stock', function () {
    $rows = [
        ['name' => 'Jade Plant', 'sku' => 'JADE-1', 'category' => 'Succulents', 'unit' => 'Nos', 'cost_price' => '60', 'retail_price' => '150', 'gst_rate' => '5', 'opening_stock' => '12'],
        ['name' => 'Snake Plant', 'category' => 'Succulents', 'cost_price' => '', 'retail_price' => '200'],
        ['name' => 'Jade Plant', 'category' => 'Succulents', 'cost_price' => '60'],
    ];

    $check = $this->postJson('/api/import/products', ['rows' => $rows, 'dry_run' => true], $this->apiHeaders())->assertOk()->json('data');
    expect($check['total'])->toBe(3)->and($check['valid'])->toBe(1)->and($check['created'])->toBe(0)
        ->and($check['new_categories'])->toBe(['Succulents'])
        ->and(collect($check['errors'])->pluck('row')->all())->toBe([3, 4])
        ->and($check['errors'][0]['messages'])->toContain('Cost price is required.')
        ->and($check['errors'][1]['messages'])->toContain('Same name as row 2 in this file.')
        ->and(Product::where('name', 'Jade Plant')->exists())->toBeFalse();

    $done = $this->postJson('/api/import/products', ['rows' => [$rows[0]]], $this->apiHeaders())->assertOk()->json('data');
    $jade = Product::where('name', 'Jade Plant')->first();
    expect($done['created'])->toBe(1)
        ->and($jade->category->name)->toBe('Succulents')
        ->and((float) $jade->current_stock)->toBe(12.0)
        ->and(ProductBatch::where('product_id', $jade->id)->value('remaining_qty'))->toEqual(12)
        ->and(ProductCategory::where('name', 'Succulents')->count())->toBe(1);

    // Importing it again is refused like the Add form would.
    $again = $this->postJson('/api/import/products', ['rows' => [$rows[0]]], $this->apiHeaders())->json('data');
    expect($again['created'])->toBe(0)->and($again['errors'][0]['messages'][0])->toContain('already');
});

it('imports customers and suppliers with sensible defaults', function () {
    $this->postJson('/api/import/customers', ['rows' => [
        ['name' => 'Anu Traders', 'phone' => '9876543210', 'type' => 'wholesale'],
        ['name' => 'Ravi'],
        ['name' => 'Bad Phone', 'phone' => 'abc'],
    ]], $this->apiHeaders())->assertOk()->assertJsonPath('data.created', 2);
    expect(Customer::where('name', 'Ravi')->value('type')->value ?? Customer::where('name', 'Ravi')->value('type'))->toBe('retail');

    $this->postJson('/api/import/suppliers', ['rows' => [['name' => 'Green Pots', 'phone' => '9123456780', 'address' => 'MG Road, Kottayam', 'credit_days' => '30']]], $this->apiHeaders())
        ->assertOk()->assertJsonPath('data.created', 1);
    expect(Supplier::where('name', 'Green Pots')->value('credit_days'))->toBe(30);
});

it('refuses import without the create permission', function () {
    $this->createCompanyWithUser(['products.view']);
    $this->postJson('/api/import/products', ['rows' => [['name' => 'X']]], $this->apiHeaders())->assertForbidden();
});

it('filters sales by date, customer name and payment mode', function () {
    $anu = $this->createCustomer(['name' => 'Anu Traders']);
    $product = $this->createProduct(['current_stock' => 50, 'retail_price' => 100, 'gst_rate' => 0]);
    $make = function (string $date, ?string $customerId, string $mode) use ($product) {
        $sale = app(CreateSale::class)->handle($this->company->id, [
            'customer_id' => $customerId, 'sale_date' => $date, 'payment_mode' => $mode, 'is_interstate' => false,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 100, 'gst_rate' => 0]],
        ], $this->user->id);

        return app(ConfirmSale::class)->handle($sale, $this->user->id);
    };
    $old = $make(now()->subDays(40)->toDateString(), $anu->id, 'cash');
    $recentAnu = $make(now()->toDateString(), $anu->id, 'upi');
    $walkIn = $make(now()->toDateString(), null, 'cash');

    $ids = fn (string $q) => collect($this->getJson('/api/sales?'.$q, $this->apiHeaders())->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    $from = now()->subDays(7)->toDateString();
    expect($ids("from={$from}"))->toBe(collect([$recentAnu->id, $walkIn->id])->sort()->values()->all())
        ->and($ids('search=Anu'))->toBe(collect([$old->id, $recentAnu->id])->sort()->values()->all())
        ->and($ids('payment_mode=upi'))->toBe([$recentAnu->id]);
});
