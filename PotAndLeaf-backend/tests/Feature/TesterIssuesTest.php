<?php

/**
 * Regression tests for the tester's issue list (13–22 Oct 2026).
 * Issue numbers refer to "potAndLeaf testing - Issues".
 *
 * Run: php artisan test tests/Feature/TesterIssuesTest.php
 */

use App\Actions\Products\CreateProduct;
use App\Actions\Products\UpdateProduct;
use App\Actions\Sales\CancelSale;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Actions\StockVerifications\ApproveStockVerification;
use App\Actions\StockVerifications\CreateStockVerification;
use App\Actions\StockVerifications\SubmitStockVerification;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Rental;
use App\Models\StockLedgerEntry;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PurchaseOrderService;
use App\Services\RentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'po.view', 'po.create', 'rental.view', 'rental.create', 'products.view', 'products.create', 'products.update',
        'users.view', 'users.create', 'users.update', 'users.delete',
        'sales.view', 'sales.create', 'sales.confirm', 'sales.delete', 'loyalty.manage',
    ]);
});

function issueBatch(object $test, Product $product, float $qty, float $cost, string $no, int $daysAgo = 0): ProductBatch
{
    return ProductBatch::create([
        'company_id' => $test->company->id, 'product_id' => $product->id,
        'batch_no' => $no, 'barcode' => 'BC-'.$no, 'qty' => $qty, 'remaining_qty' => $qty,
        'cost_price' => $cost, 'status' => 'active', 'received_at' => now()->subDays($daysAgo),
    ]);
}

it('hides inactive suppliers and products when creating a purchase order or rental (issues 33, 42)', function () {
    $active = $this->createSupplier(['name' => 'Live Supplier']);
    $this->createSupplier(['name' => 'Old Supplier', 'status' => 'inactive']);
    $plant = $this->createProduct(['name' => 'Live Plant', 'is_rental' => true]);
    $this->createProduct(['name' => 'Retired Plant', 'is_rental' => true, 'status' => 'inactive']);

    $po = $this->getJson('/api/purchase-orders/form-data', $this->apiHeaders())->assertOk();
    expect(collect($po->json('data.suppliers'))->pluck('id')->all())->toBe([$active->id])
        ->and(collect($po->json('data.products'))->pluck('id')->all())->toBe([$plant->id]);

    $rental = $this->getJson('/api/rentals/form-data', $this->apiHeaders())->assertOk();
    expect(collect($rental->json('data.products'))->pluck('name')->all())->toBe(['Live Plant']);
});

it('stops the same item being ordered twice and leaves ordered items off the reorder list (issue 3)', function () {
    $supplier = $this->createSupplier();
    $pot = $this->createProduct(['name' => 'Clay pot', 'current_stock' => 2, 'reorder_level' => 10]);
    $line = ['product_id' => $pot->id, 'qty' => 5, 'rate' => 40, 'gst_rate' => 18];
    $order = fn (array $items, array $extra = []) => $this->postJson('/api/purchase-orders', array_merge([
        'supplier_id' => $supplier->id, 'po_date' => now()->toDateString(), 'items' => $items,
    ], $extra), $this->apiHeaders());

    $order([$line, $line])->assertStatus(422)->assertJsonPath('errors.items.0', 'Clay pot is added more than once. Put the total quantity on one line.');

    $svc = app(PurchaseOrderService::class);
    expect($svc->reorderSuggestions($this->company->id)[0]['suggested_qty'])->toBe(18.0);

    $first = $order([$line])->assertCreated();
    $poNo = $first->json('data.po_no');

    // 2 in stock + 5 on order is still under 10 → suggested, but only the rest.
    $sug = $svc->reorderSuggestions($this->company->id)[0];
    expect($sug['on_order'])->toBe(5.0)->and($sug['open_po_nos'])->toBe([$poNo])->and($sug['suggested_qty'])->toBe(13.0);

    $order([$line])->assertStatus(422)->assertJsonPath('errors.open_orders.0', "Clay pot is already on {$poNo} (5 still to come).");
    $order([$line], ['allow_duplicate' => true])->assertCreated();

    // 2 + 10 on order ≥ reorder level → no longer suggested.
    expect($svc->reorderSuggestions($this->company->id))->toBe([]);
});

it('lets HO see, open and restore a user who was removed from every company (issue 52)', function () {
    $staff = User::factory()->create(['name' => 'Gone Staff', 'is_active' => true]);
    $staff->companies()->attach($this->company->id, ['is_default' => true]);
    $this->deleteJson("/api/users/{$staff->id}", [], $this->apiHeaders())->assertOk();

    // A company user may not browse removed users.
    $this->getJson('/api/users?status=removed', $this->apiHeaders())->assertForbidden();

    $ho = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
    $ho->companies()->attach($this->company->id, ['is_default' => true]);
    $h = $this->apiHeaders($ho);
    app('auth')->forgetGuards(); // next request signs in as HO

    expect(collect($this->getJson('/api/users?status=removed', $h)->assertOk()->json('data'))->pluck('name')->all())->toBe(['Gone Staff']);
    $this->getJson("/api/users/{$staff->id}", $h)->assertOk()->assertJsonPath('data.companies', []);

    $this->postJson("/api/users/{$staff->id}/restore", [], $h)->assertOk();
    expect($staff->fresh()->is_active)->toBeTrue()
        ->and($staff->companies()->pluck('companies.id')->all())->toBe([$this->company->id]);
    $this->postJson("/api/users/{$staff->id}/restore", [], $h)->assertStatus(422);
});

it('records which batches a stock count took from or added to (issues 47, 55)', function () {
    $product = $this->createProduct(['current_stock' => 10]);
    $old = issueBatch($this, $product, 4, 50, 'B-OLD', 5);
    $new = issueBatch($this, $product, 6, 60, 'B-NEW', 1);

    $count = function (float $counted) use ($product) {
        $v = app(CreateStockVerification::class)->handle($this->company->id, [
            'count_date' => now()->toDateString(), 'items' => [['product_id' => $product->id, 'counted_qty' => $counted]],
        ], $this->user->id);
        app(SubmitStockVerification::class)->handle($v, $this->user->id);

        return app(ApproveStockVerification::class)->handle($v->fresh(), $this->user->id);
    };

    // 5 short: 4 off the old batch, 1 off the new one.
    $item = $count(5)->items->first();
    expect($item->batch_adjustments)->toBe([
        ['batch_id' => $old->id, 'batch_no' => 'B-OLD', 'qty' => -4],
        ['batch_id' => $new->id, 'batch_no' => 'B-NEW', 'qty' => -1],
    ])
        ->and((float) $old->fresh()->remaining_qty)->toBe(0.0)
        ->and((float) $new->fresh()->remaining_qty)->toBe(5.0)
        ->and(StockLedgerEntry::where('reference_type', 'stock-verification')->pluck('product_batch_id')->all())->toBe([$old->id, $new->id]);

    // 2 extra found: added to the newest batch.
    $item = $count(7)->items->first();
    expect($item->batch_adjustments)->toBe([['batch_id' => $new->id, 'batch_no' => 'B-NEW', 'qty' => 2]])
        ->and((float) $new->fresh()->remaining_qty)->toBe(7.0)
        ->and((float) $product->fresh()->current_stock)->toBe(7.0);
});

it('values stock at what each batch cost, not the product cost price (issue 54)', function () {
    $product = $this->createProduct(['current_stock' => 12, 'cost_price' => 100]);
    issueBatch($this, $product, 5, 80, 'B-1');
    issueBatch($this, $product, 5, 90, 'B-2');

    $row = collect(app(InventoryService::class)->valuation($this->company->id)['items'])->firstWhere('id', $product->id);
    // 5×80 + 5×90 + 2 not in any batch × 100 = 1,050
    expect($row['value'])->toBe(1050.0)->and($row['cost'])->toBe(87.5);
});

it('puts returned rental plants and cancelled unscanned sales back on their batches (issue 22)', function () {
    $product = $this->createProduct(['current_stock' => 10, 'retail_price' => 200, 'gst_rate' => 0]);
    $batch = issueBatch($this, $product, 10, 50, 'B-R');
    $customer = $this->createCustomer();

    $rental = Rental::create([
        'company_id' => $this->company->id, 'customer_id' => $customer->id, 'rental_no' => 'RNT-777001',
        'start_date' => now()->toDateString(), 'billing_cycle' => 'monthly', 'deposit' => 100, 'status' => 'draft',
    ]);
    $item = $rental->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'qty' => 3, 'rate_per_cycle' => 50]);
    $svc = app(RentalService::class);
    $svc->activate($rental, $this->user->id);
    expect((float) $batch->fresh()->remaining_qty)->toBe(7.0);
    $svc->returnItems($rental->fresh(), [$item->id => 3], $this->user->id);
    expect((float) $batch->fresh()->remaining_qty)->toBe(10.0);

    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id, 'sale_date' => now()->toDateString(), 'payment_mode' => 'cash', 'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 4, 'rate' => 200, 'discount' => 0, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);
    expect((float) $batch->fresh()->remaining_qty)->toBe(6.0);
    app(CancelSale::class)->handle($sale->fresh(), $this->user->id);
    expect((float) $batch->fresh()->remaining_qty)->toBe(10.0)
        ->and((float) $product->fresh()->current_stock)->toBe(10.0);
});

it('gives a product added with opening stock its own batch and barcode (issue 40)', function () {
    $product = app(CreateProduct::class)->handle($this->company->id, [
        'name' => 'Snake plant', 'sku' => 'SNK-1', 'cost_price' => 70, 'retail_price' => 150, 'gst_rate' => 5, 'opening_stock' => 8,
    ], $this->user->id);

    $batch = ProductBatch::where('product_id', $product->id)->first();
    expect($batch->batch_no)->toBe('OPEN-SNK-1')->and($batch->barcode)->not->toBeEmpty()
        ->and((float) $batch->remaining_qty)->toBe(8.0);

    app(UpdateProduct::class)->handle($product->fresh(), ['name' => 'Snake plant', 'sku' => 'SNK-1', 'cost_price' => 70, 'opening_stock' => 11], $this->user->id);
    expect((float) $batch->fresh()->remaining_qty)->toBe(11.0);

    app(UpdateProduct::class)->handle($product->fresh(), ['name' => 'Snake plant', 'sku' => 'SNK-1', 'cost_price' => 70, 'opening_stock' => 6], $this->user->id);
    expect((float) $batch->fresh()->remaining_qty)->toBe(6.0)
        ->and((float) $product->fresh()->current_stock)->toBe(6.0);
});

it('checks a loyalty Add rule and gives product bonus on top of the normal points', function () {
    $pot = $this->createProduct(['name' => 'Bonsai pot', 'current_stock' => 10, 'retail_price' => 500, 'gst_rate' => 0]);
    $h = $this->apiHeaders();

    $this->getJson('/api/loyalty/rules/form-data', $h)->assertOk()->assertJsonPath('data.products.0.name', 'Bonsai pot');

    // A product bonus needs a product and something to give.
    $this->postJson('/api/loyalty/rules', ['name' => 'Pot bonus', 'rule_type' => 'product'], $h)
        ->assertStatus(422)->assertJsonValidationErrors(['product_id']);
    $this->postJson('/api/loyalty/rules', ['name' => 'Pot bonus', 'rule_type' => 'product', 'product_id' => $pot->id], $h)
        ->assertStatus(422)->assertJsonValidationErrors(['bonus_points_per_unit']);
    $this->postJson('/api/loyalty/rules', ['name' => 'Pot bonus', 'rule_type' => 'product', 'product_id' => $pot->id, 'bonus_points_per_unit' => 5, 'is_active' => true], $h)
        ->assertCreated();

    $customer = $this->createCustomer(['loyalty_points' => 0]);
    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id, 'sale_date' => now()->toDateString(), 'payment_mode' => 'cash', 'is_interstate' => false,
        'items' => [['product_id' => $pot->id, 'qty' => 2, 'rate' => 500, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    // ₹1,000 bill: normal 1 pt per ₹100 = 10, plus 2 pots × 5 bonus = 10.
    expect((int) $customer->fresh()->loyalty_points)->toBe(20);
});
