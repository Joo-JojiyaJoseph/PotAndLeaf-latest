<?php

/**
 * Sales Return & Purchase Return module tests — SRETURN-001 … SRETURN-011, PRETURN-001 … PRETURN-008.
 *
 * Real API + database integration (no mocked business logic).
 *
 * Run: php artisan test tests/Feature/ReturnModuleTest.php
 */

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\PurchaseReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'sales.view',
        'sales.create',
        'sales.confirm',
        'sales_returns.view',
        'sales_returns.create',
        'sales_returns.confirm',
        'sales_returns.delete',
        'purchases.view',
        'purchases.create',
        'purchases.confirm',
        'purchase_returns.view',
        'purchase_returns.create',
        'purchase_returns.confirm',
        'purchase_returns.delete',
        'inventory.view',
    ]);

    $this->supplier = $this->createSupplier(['name' => 'Return Supplier', 'status' => 'active']);
    $this->customer = $this->createCustomer(['name' => 'Return Customer', 'type' => 'retail']);
});

function returnHeaders(object $test): array
{
    return $test->apiHeaders();
}

function confirmSaleForReturn(object $test, Product $product, float $qty = 10, float $rate = 100): Sale
{
    $create = $test->postJson('/api/sales', [
        'customer_id' => $test->customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'bill_kind' => 'tax_invoice',
        'amount_paid' => $qty * $rate,
        'items' => [
            ['product_id' => $product->id, 'qty' => $qty, 'rate' => $rate, 'discount' => 0, 'gst_rate' => 0],
        ],
    ], returnHeaders($test))->assertCreated();

    $saleId = $create->json('data.id');
    $test->postJson("/api/sales/{$saleId}/confirm", [], returnHeaders($test))->assertOk();

    return Sale::with('items')->findOrFail($saleId);
}

function confirmPurchaseForReturn(object $test, Product $product, float $qty = 20, float $rate = 50): Purchase
{
    $create = $test->postJson('/api/purchases', [
        'supplier_id' => $test->supplier->id,
        'purchase_date' => now()->toDateString(),
        'is_interstate' => false,
        'items' => [
            ['product_id' => $product->id, 'qty' => $qty, 'rate' => $rate, 'discount' => 0, 'gst_rate' => 0],
        ],
    ], returnHeaders($test))->assertCreated();

    $purchaseId = $create->json('data.id');
    $test->postJson("/api/purchases/{$purchaseId}/confirm", [], returnHeaders($test))->assertOk();

    return Purchase::with('items')->findOrFail($purchaseId);
}

function salesReturnPayload(string $saleId, string $saleItemId, float $qty, array $overrides = []): array
{
    return array_merge([
        'sale_id' => $saleId,
        'return_date' => now()->toDateString(),
        'items' => [['sale_item_id' => $saleItemId, 'qty' => $qty]],
    ], $overrides);
}

function purchaseReturnPayload(string $purchaseId, string $purchaseItemId, float $qty, array $overrides = []): array
{
    return array_merge([
        'purchase_id' => $purchaseId,
        'return_date' => now()->toDateString(),
        'items' => [['purchase_item_id' => $purchaseItemId, 'qty' => $qty]],
    ], $overrides);
}

describe('Sales Return module — API', function () {

    it('SRETURN-001 create sales return draft', function () {
        $product = $this->createProduct(['current_stock' => 20, 'retail_price' => 100, 'gst_rate' => 0]);
        $sale = confirmSaleForReturn($this, $product, 8, 100);
        $saleItemId = $sale->items->first()->id;

        $response = $this->postJson(
            '/api/sales-returns',
            salesReturnPayload($sale->id, $saleItemId, 3),
            returnHeaders($this),
        );

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.sale.id', $sale->id);

        expect(SalesReturn::count())->toBe(1);
    })->group('sales-return', 'SRETURN-001');

    it('SRETURN-002 select sale via source endpoint', function () {
        $product = $this->createProduct(['current_stock' => 15, 'retail_price' => 100, 'gst_rate' => 0]);
        $confirmed = confirmSaleForReturn($this, $product, 5, 100);

        $draftCreate = $this->postJson('/api/sales', [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'payment_mode' => 'cash',
            'is_interstate' => false,
            'bill_kind' => 'tax_invoice',
            'amount_paid' => 200,
            'items' => [
                ['product_id' => $product->id, 'qty' => 2, 'rate' => 100, 'discount' => 0, 'gst_rate' => 0],
            ],
        ], returnHeaders($this))->assertCreated();
        $draftSaleId = $draftCreate->json('data.id');

        $this->getJson('/api/sales-returns/source?sale_id='.$confirmed->id, returnHeaders($this))
            ->assertOk()
            ->assertJsonPath('data.sale.id', $confirmed->id)
            ->assertJsonPath('data.sale.sale_no', $confirmed->sale_no);

        $this->getJson('/api/sales-returns/source?sale_id='.$draftSaleId, returnHeaders($this))
            ->assertNotFound();

        $this->getJson('/api/sales-returns/source?sale_id='.fake()->uuid(), returnHeaders($this))
            ->assertNotFound();
    })->group('sales-return', 'SRETURN-002');

    it('SRETURN-003 select product line from sale source', function () {
        $product = $this->createProduct(['name' => 'Return Rose', 'current_stock' => 30, 'retail_price' => 150, 'gst_rate' => 0]);
        $sale = confirmSaleForReturn($this, $product, 6, 150);
        $saleItem = $sale->items->first();

        $response = $this->getJson('/api/sales-returns/source?sale_id='.$sale->id, returnHeaders($this))->assertOk();

        expect($response->json('data.items'))->toHaveCount(1);

        $line = $response->json('data.items.0');
        expect($line['sale_item_id'])->toBe($saleItem->id)
            ->and($line['product_id'])->toBe($product->id)
            ->and($line['product_name'])->toBe('Return Rose')
            ->and((float) $line['qty'])->toBe(6.0)
            ->and((float) $line['returnable'])->toBe(6.0);

        $wrongItemId = fake()->uuid();
        $this->postJson('/api/sales-returns', salesReturnPayload($sale->id, $wrongItemId, 1), returnHeaders($this))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    })->group('sales-return', 'SRETURN-003');

    it('SRETURN-004 valid return quantity accepted', function () {
        $product = $this->createProduct(['current_stock' => 20, 'retail_price' => 100, 'gst_rate' => 0]);
        $sale = confirmSaleForReturn($this, $product, 10, 100);
        $saleItemId = $sale->items->first()->id;

        $response = $this->postJson(
            '/api/sales-returns',
            salesReturnPayload($sale->id, $saleItemId, 4),
            returnHeaders($this),
        )->assertCreated();

        expect((float) $response->json('data.items.0.qty'))->toBe(4.0)
            ->and((float) $response->json('data.grand_total'))->toBe(400.0);
    })->group('sales-return', 'SRETURN-004');

    it('SRETURN-005 zero return quantity rejected', function () {
        $product = $this->createProduct(['current_stock' => 20, 'retail_price' => 100, 'gst_rate' => 0]);
        $sale = confirmSaleForReturn($this, $product, 5, 100);
        $saleItemId = $sale->items->first()->id;

        $this->postJson(
            '/api/sales-returns',
            salesReturnPayload($sale->id, $saleItemId, 0),
            returnHeaders($this),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.qty']);
    })->group('sales-return', 'SRETURN-005');

    it('SRETURN-006 negative return quantity rejected', function () {
        $product = $this->createProduct(['current_stock' => 20, 'retail_price' => 100, 'gst_rate' => 0]);
        $sale = confirmSaleForReturn($this, $product, 5, 100);
        $saleItemId = $sale->items->first()->id;

        $this->postJson(
            '/api/sales-returns',
            salesReturnPayload($sale->id, $saleItemId, -2),
            returnHeaders($this),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.qty']);
    })->group('sales-return', 'SRETURN-006');

    it('SRETURN-007 return more than sold quantity rejected', function () {
        $product = $this->createProduct(['current_stock' => 20, 'retail_price' => 100, 'gst_rate' => 0]);
        $sale = confirmSaleForReturn($this, $product, 5, 100);
        $saleItemId = $sale->items->first()->id;

        $this->postJson(
            '/api/sales-returns',
            salesReturnPayload($sale->id, $saleItemId, 6),
            returnHeaders($this),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    })->group('sales-return', 'SRETURN-007');

    it('SRETURN-008 submit return saves as draft', function () {
        $product = $this->createProduct(['current_stock' => 20, 'retail_price' => 100, 'gst_rate' => 0]);
        $sale = confirmSaleForReturn($this, $product, 7, 100);
        $saleItemId = $sale->items->first()->id;

        $response = $this->postJson(
            '/api/sales-returns',
            salesReturnPayload($sale->id, $saleItemId, 2, ['reason' => 'Damaged', 'notes' => 'Customer complaint']),
            returnHeaders($this),
        )->assertCreated();

        $returnId = $response->json('data.id');

        $this->getJson("/api/sales-returns/{$returnId}", returnHeaders($this))
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.reason', 'Damaged')
            ->assertJsonPath('data.notes', 'Customer complaint');

        expect((float) $product->fresh()->current_stock)->toBe(13.0);
    })->group('sales-return', 'SRETURN-008');

    it('SRETURN-009 confirm sales return', function () {
        $product = $this->createProduct(['current_stock' => 20, 'retail_price' => 100, 'gst_rate' => 0]);
        $sale = confirmSaleForReturn($this, $product, 5, 100);
        $saleItemId = $sale->items->first()->id;

        $create = $this->postJson(
            '/api/sales-returns',
            salesReturnPayload($sale->id, $saleItemId, 3),
            returnHeaders($this),
        )->assertCreated();

        $returnId = $create->json('data.id');

        $this->postJson("/api/sales-returns/{$returnId}/confirm", [], returnHeaders($this))
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        expect(SalesReturn::find($returnId)->status)->toBe('confirmed');
    })->group('sales-return', 'SRETURN-009');

    it('SRETURN-010 cancel draft sales return', function () {
        $product = $this->createProduct(['current_stock' => 20, 'retail_price' => 100, 'gst_rate' => 0]);
        $sale = confirmSaleForReturn($this, $product, 5, 100);
        $saleItemId = $sale->items->first()->id;

        $create = $this->postJson(
            '/api/sales-returns',
            salesReturnPayload($sale->id, $saleItemId, 2),
            returnHeaders($this),
        )->assertCreated();

        $returnId = $create->json('data.id');

        $this->deleteJson("/api/sales-returns/{$returnId}", [], returnHeaders($this))
            ->assertOk();

        expect(SalesReturn::find($returnId)->status)->toBe('cancelled');
        expect((float) $product->fresh()->current_stock)->toBe(15.0);
    })->group('sales-return', 'SRETURN-010');

    it('SRETURN-011 confirm sales return increases inventory', function () {
        $product = $this->createProduct(['current_stock' => 20, 'retail_price' => 100, 'gst_rate' => 0]);
        $sale = confirmSaleForReturn($this, $product, 8, 100);
        expect((float) $product->fresh()->current_stock)->toBe(12.0);

        $saleItemId = $sale->items->first()->id;
        $returnQty = 3;

        $create = $this->postJson(
            '/api/sales-returns',
            salesReturnPayload($sale->id, $saleItemId, $returnQty),
            returnHeaders($this),
        )->assertCreated();

        $returnId = $create->json('data.id');
        $this->postJson("/api/sales-returns/{$returnId}/confirm", [], returnHeaders($this))->assertOk();

        expect((float) $product->fresh()->current_stock)->toBe(15.0);

        $ledger = $this->getJson(
            "/api/inventory/ledger?product_id={$product->id}&reference_type=sales-return",
            returnHeaders($this),
        )->assertOk();

        expect($ledger->json('data.0.direction'))->toBe('in')
            ->and((float) $ledger->json('data.0.qty'))->toBe((float) $returnQty);
    })->group('sales-return', 'SRETURN-011');

})->group('sales-return');

describe('Purchase Return module — API', function () {

    it('PRETURN-001 create purchase return draft', function () {
        $product = $this->createProduct(['current_stock' => 0, 'opening_stock' => 0]);
        $purchase = confirmPurchaseForReturn($this, $product, 15, 50);
        $purchaseItemId = $purchase->items->first()->id;

        $response = $this->postJson(
            '/api/purchase-returns',
            purchaseReturnPayload($purchase->id, $purchaseItemId, 4),
            returnHeaders($this),
        );

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.purchase.id', $purchase->id);

        expect(PurchaseReturn::count())->toBe(1);
    })->group('purchase-return', 'PRETURN-001');

    it('PRETURN-002 select purchase via source endpoint', function () {
        $product = $this->createProduct(['current_stock' => 0, 'opening_stock' => 0]);
        $confirmed = confirmPurchaseForReturn($this, $product, 12, 50);

        $draftCreate = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'is_interstate' => false,
            'items' => [
                ['product_id' => $product->id, 'qty' => 3, 'rate' => 50, 'discount' => 0, 'gst_rate' => 0],
            ],
        ], returnHeaders($this))->assertCreated();
        $draftPurchaseId = $draftCreate->json('data.id');

        $this->getJson('/api/purchase-returns/source?purchase_id='.$confirmed->id, returnHeaders($this))
            ->assertOk()
            ->assertJsonPath('data.purchase.id', $confirmed->id)
            ->assertJsonPath('data.purchase.purchase_no', $confirmed->purchase_no);

        $this->getJson('/api/purchase-returns/source?purchase_id='.$draftPurchaseId, returnHeaders($this))
            ->assertNotFound();

        $this->getJson('/api/purchase-returns/source?purchase_id='.fake()->uuid(), returnHeaders($this))
            ->assertNotFound();
    })->group('purchase-return', 'PRETURN-002');

    it('PRETURN-003 select product line from purchase source', function () {
        $product = $this->createProduct(['name' => 'Return Palm', 'current_stock' => 0, 'opening_stock' => 0]);
        $purchase = confirmPurchaseForReturn($this, $product, 18, 60);
        $purchaseItem = $purchase->items->first();

        $response = $this->getJson('/api/purchase-returns/source?purchase_id='.$purchase->id, returnHeaders($this))->assertOk();

        expect($response->json('data.items'))->toHaveCount(1);

        $line = $response->json('data.items.0');
        expect($line['purchase_item_id'])->toBe($purchaseItem->id)
            ->and($line['product_id'])->toBe($product->id)
            ->and($line['product_name'])->toBe('Return Palm')
            ->and((float) $line['qty'])->toBe(18.0)
            ->and((float) $line['returnable'])->toBe(18.0);

        $wrongItemId = fake()->uuid();
        $this->postJson('/api/purchase-returns', purchaseReturnPayload($purchase->id, $wrongItemId, 1), returnHeaders($this))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    })->group('purchase-return', 'PRETURN-003');

    it('PRETURN-004 valid return quantity accepted', function () {
        $product = $this->createProduct(['current_stock' => 0, 'opening_stock' => 0]);
        $purchase = confirmPurchaseForReturn($this, $product, 20, 50);
        $purchaseItemId = $purchase->items->first()->id;

        $response = $this->postJson(
            '/api/purchase-returns',
            purchaseReturnPayload($purchase->id, $purchaseItemId, 5),
            returnHeaders($this),
        )->assertCreated();

        expect((float) $response->json('data.items.0.qty'))->toBe(5.0)
            ->and((float) $response->json('data.grand_total'))->toBe(250.0);
    })->group('purchase-return', 'PRETURN-004');

    it('PRETURN-005 return greater than purchased quantity rejected', function () {
        $product = $this->createProduct(['current_stock' => 0, 'opening_stock' => 0]);
        $purchase = confirmPurchaseForReturn($this, $product, 10, 50);
        $purchaseItemId = $purchase->items->first()->id;

        $this->postJson(
            '/api/purchase-returns',
            purchaseReturnPayload($purchase->id, $purchaseItemId, 11),
            returnHeaders($this),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    })->group('purchase-return', 'PRETURN-005');

    it('PRETURN-006 confirm purchase return', function () {
        $product = $this->createProduct(['current_stock' => 0, 'opening_stock' => 0]);
        $purchase = confirmPurchaseForReturn($this, $product, 20, 50);
        $purchaseItemId = $purchase->items->first()->id;

        $create = $this->postJson(
            '/api/purchase-returns',
            purchaseReturnPayload($purchase->id, $purchaseItemId, 6),
            returnHeaders($this),
        )->assertCreated();

        $returnId = $create->json('data.id');

        $this->postJson("/api/purchase-returns/{$returnId}/confirm", [], returnHeaders($this))
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        expect(PurchaseReturn::find($returnId)->status)->toBe('confirmed');
    })->group('purchase-return', 'PRETURN-006');

    it('PRETURN-007 cancel draft purchase return', function () {
        $product = $this->createProduct(['current_stock' => 0, 'opening_stock' => 0]);
        $purchase = confirmPurchaseForReturn($this, $product, 20, 50);
        $purchaseItemId = $purchase->items->first()->id;

        $create = $this->postJson(
            '/api/purchase-returns',
            purchaseReturnPayload($purchase->id, $purchaseItemId, 5),
            returnHeaders($this),
        )->assertCreated();

        $returnId = $create->json('data.id');

        $this->deleteJson("/api/purchase-returns/{$returnId}", [], returnHeaders($this))
            ->assertOk();

        expect(PurchaseReturn::find($returnId)->status)->toBe('cancelled');
        expect((float) $product->fresh()->current_stock)->toBe(20.0);
    })->group('purchase-return', 'PRETURN-007');

    it('PRETURN-008 confirm purchase return decreases inventory', function () {
        $product = $this->createProduct(['current_stock' => 0, 'opening_stock' => 0]);
        $purchase = confirmPurchaseForReturn($this, $product, 20, 50);
        expect((float) $product->fresh()->current_stock)->toBe(20.0);

        $purchaseItemId = $purchase->items->first()->id;
        $returnQty = 7;

        $create = $this->postJson(
            '/api/purchase-returns',
            purchaseReturnPayload($purchase->id, $purchaseItemId, $returnQty),
            returnHeaders($this),
        )->assertCreated();

        $returnId = $create->json('data.id');
        $this->postJson("/api/purchase-returns/{$returnId}/confirm", [], returnHeaders($this))->assertOk();

        expect((float) $product->fresh()->current_stock)->toBe(13.0);

        $ledger = $this->getJson(
            "/api/inventory/ledger?product_id={$product->id}&reference_type=purchase-return",
            returnHeaders($this),
        )->assertOk();

        expect($ledger->json('data.0.direction'))->toBe('out')
            ->and((float) $ledger->json('data.0.qty'))->toBe((float) $returnQty);
    })->group('purchase-return', 'PRETURN-008');

})->group('purchase-return');
