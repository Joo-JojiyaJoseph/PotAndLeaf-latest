<?php

/**
 * Inventory module automated tests — INVENTORY-001 … INVENTORY-016.
 *
 * Real API + database integration (no mocked business calculations).
 *
 * Run: php artisan test tests/Feature/InventoryModuleTest.php
 */

use App\Http\Controllers\Api\InventoryController;
use App\Models\Company;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'inventory.view',
        'purchases.view',
        'purchases.create',
        'purchases.confirm',
        'sales.view',
        'sales.create',
        'sales.confirm',
        'sales_returns.view',
        'sales_returns.create',
        'sales_returns.confirm',
        'purchase_returns.view',
        'purchase_returns.create',
        'purchase_returns.confirm',
        'transfers.view',
        'transfers.create',
        'transfers.dispatch',
        'transfers.receive',
        'transfers.approve',
        'stock_verifications.view',
        'stock_verifications.create',
        'stock_verifications.approve',
    ]);

    $this->supplier = $this->createSupplier(['name' => 'Inventory Supplier', 'status' => 'active']);
});

function inventoryHeaders(object $test): array
{
    return $test->apiHeaders();
}

function inventoryHeadersForCompany(object $test, int|string $companyId): array
{
    $headers = $test->apiHeaders();
    $headers['X-Company-Id'] = (string) $companyId;

    return $headers;
}

function confirmPurchaseViaApi(object $test, Product $product, float $qty = 10, float $rate = 100): Purchase
{
    $create = $test->postJson('/api/purchases', [
        'supplier_id' => $test->supplier->id,
        'purchase_date' => now()->toDateString(),
        'is_interstate' => false,
        'items' => [
            ['product_id' => $product->id, 'qty' => $qty, 'rate' => $rate, 'discount' => 0, 'gst_rate' => 0],
        ],
    ], inventoryHeaders($test))->assertCreated();

    $purchaseId = $create->json('data.id');
    $test->postJson("/api/purchases/{$purchaseId}/confirm", [], inventoryHeaders($test))->assertOk();

    return Purchase::findOrFail($purchaseId);
}

function confirmSaleViaApi(object $test, Product $product, float $qty = 3, float $rate = 100): Sale
{
    $customer = $test->createCustomer();

    $create = $test->postJson('/api/sales', [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'bill_kind' => 'tax_invoice',
        'amount_paid' => $qty * $rate,
        'items' => [
            ['product_id' => $product->id, 'qty' => $qty, 'rate' => $rate, 'discount' => 0, 'gst_rate' => 0],
        ],
    ], inventoryHeaders($test))->assertCreated();

    $saleId = $create->json('data.id');
    $test->postJson("/api/sales/{$saleId}/confirm", [], inventoryHeaders($test))->assertOk();

    return Sale::findOrFail($saleId);
}

function grantDestCompanyAccess(object $test, Company $company): void
{
    $test->user->companies()->syncWithoutDetaching([$company->id => ['is_default' => false]]);

    if ($test->user->roles()->wherePivot('company_id', $company->id)->exists()) {
        return;
    }

    $sourceRole = $test->user->roles()->wherePivot('company_id', $test->company->id)->first();
    if ($sourceRole) {
        $test->user->roles()->attach($sourceRole->id, ['company_id' => $company->id]);
    }
}

function createDestCompanyWithProduct(object $test, Product $sourceProduct, float $stock = 0): array
{
    $dest = Company::create([
        'name' => 'Branch '.Str::upper(Str::random(3)),
        'code' => 'BR'.Str::upper(Str::random(3)),
        'is_active' => true,
    ]);

    $destProduct = Product::create([
        'company_id' => $dest->id,
        'sku' => $sourceProduct->sku,
        'name' => $sourceProduct->name,
        'gst_rate' => 18,
        'mrp' => 500,
        'cost_price' => 20,
        'retail_price' => 400,
        'wholesale_price' => 350,
        'dealer_price' => 300,
        'current_stock' => $stock,
        'opening_stock' => $stock,
        'status' => 'active',
    ]);

    return ['company' => $dest, 'product' => $destProduct];
}

describe('Inventory module — API', function () {

    it('INVENTORY-001 inventory stock API loads for list page', function () {
        $this->createProduct(['name' => 'List Seed', 'current_stock' => 1]);

        $response = $this->getJson('/api/inventory/stock', inventoryHeaders($this));

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total']]);

        expect(collect($response->json('data')))->not->toBeEmpty();
    })->group('inventory', 'INVENTORY-001');

    it('INVENTORY-002 product appears in inventory stock list', function () {
        $product = $this->createProduct(['name' => 'Visible Palm', 'sku' => 'INV-PALM-01', 'current_stock' => 25]);

        $response = $this->getJson('/api/inventory/stock', inventoryHeaders($this))->assertOk();

        $match = collect($response->json('data'))->firstWhere('id', $product->id);
        expect($match)->not->toBeNull()
            ->and($match['name'])->toBe('Visible Palm')
            ->and((float) $match['current_stock'])->toBe(25.0);
    })->group('inventory', 'INVENTORY-002');

    it('INVENTORY-003 search inventory filters by product name or sku', function () {
        $this->createProduct(['name' => 'Rose Inventory', 'sku' => 'INV-ROSE-01', 'current_stock' => 10]);
        $this->createProduct(['name' => 'Tulip Stock', 'sku' => 'INV-TULIP-99', 'current_stock' => 5]);

        $byName = $this->getJson('/api/inventory/stock?search=Rose', inventoryHeaders($this))->assertOk();
        expect(collect($byName->json('data')))->toHaveCount(1)
            ->and($byName->json('data.0.name'))->toBe('Rose Inventory');

        $bySku = $this->getJson('/api/inventory/stock?search=INV-TULIP', inventoryHeaders($this))->assertOk();
        expect(collect($bySku->json('data')))->toHaveCount(1);

        $noMatch = $this->getJson('/api/inventory/stock?search=ZZZNOMATCH', inventoryHeaders($this))->assertOk();
        expect(collect($noMatch->json('data')))->toHaveCount(0);
    })->group('inventory', 'INVENTORY-003');

    it('INVENTORY-004 filter inventory by low stock only', function () {
        $this->createProduct(['name' => 'Healthy Stock', 'current_stock' => 50, 'reorder_level' => 10]);
        $this->createProduct(['name' => 'Low Stock Item', 'current_stock' => 3, 'reorder_level' => 10]);

        $all = $this->getJson('/api/inventory/stock', inventoryHeaders($this))->assertOk();
        expect(collect($all->json('data')))->toHaveCount(2);

        $lowOnly = $this->getJson('/api/inventory/stock?low_only=1', inventoryHeaders($this))->assertOk();
        expect(collect($lowOnly->json('data')))->toHaveCount(1)
            ->and($lowOnly->json('data.0.name'))->toBe('Low Stock Item')
            ->and($lowOnly->json('data.0.is_low_stock'))->toBeTrue();
    })->group('inventory', 'INVENTORY-004');

    it('INVENTORY-005 view stock quantity via inventory stock API', function () {
        $product = $this->createProduct(['sku' => 'INV-QTY-01', 'current_stock' => 42.5]);

        $response = $this->getJson('/api/inventory/stock?search=INV-QTY-01', inventoryHeaders($this))->assertOk();

        expect((float) $response->json('data.0.current_stock'))->toBe(42.5);
    })->group('inventory', 'INVENTORY-005');

    it('INVENTORY-006 view inventory details via ledger and valuation', function () {
        $product = $this->createProduct(['name' => 'Ledger Product', 'sku' => 'INV-LED-01', 'current_stock' => 0, 'cost_price' => 80]);
        confirmPurchaseViaApi($this, $product, 12, 80);

        $ledger = $this->getJson("/api/inventory/ledger?product_id={$product->id}", inventoryHeaders($this))->assertOk();
        expect(collect($ledger->json('data')))->not->toBeEmpty()
            ->and($ledger->json('data.0.reference_type'))->toBe('purchase')
            ->and((float) $ledger->json('data.0.balance_after'))->toBe(12.0);

        $valuation = $this->getJson('/api/inventory/valuation', inventoryHeaders($this))->assertOk();
        $row = collect($valuation->json('data.items'))->firstWhere('id', $product->id);
        expect($row)->not->toBeNull()
            ->and((float) $row['stock'])->toBe(12.0)
            ->and((float) $row['value'])->toBe(960.0);
    })->group('inventory', 'INVENTORY-006');

    it('INVENTORY-007 purchase confirm increases inventory', function () {
        $product = $this->createProduct(['current_stock' => 0, 'opening_stock' => 0]);

        confirmPurchaseViaApi($this, $product, 15, 50);

        expect((float) $product->fresh()->current_stock)->toBe(15.0);

        $stock = $this->getJson('/api/inventory/stock?search='.$product->sku, inventoryHeaders($this))->assertOk();
        expect((float) $stock->json('data.0.current_stock'))->toBe(15.0);
    })->group('inventory', 'INVENTORY-007');

    it('INVENTORY-008 sale confirm decreases inventory', function () {
        $product = $this->createProduct(['current_stock' => 20, 'opening_stock' => 20, 'retail_price' => 100, 'gst_rate' => 0]);

        confirmSaleViaApi($this, $product, 7, 100);

        expect((float) $product->fresh()->current_stock)->toBe(13.0);

        $ledger = $this->getJson("/api/inventory/ledger?product_id={$product->id}&reference_type=sale", inventoryHeaders($this))->assertOk();
        expect($ledger->json('data.0.direction'))->toBe('out')
            ->and((float) $ledger->json('data.0.qty'))->toBe(7.0);
    })->group('inventory', 'INVENTORY-008');

    it('INVENTORY-009 sales return confirm increases inventory', function () {
        $product = $this->createProduct(['current_stock' => 20, 'retail_price' => 100, 'gst_rate' => 0]);
        $sale = confirmSaleViaApi($this, $product, 5, 100);
        expect((float) $product->fresh()->current_stock)->toBe(15.0);

        $saleItemId = $sale->items()->first()->id;

        $returnCreate = $this->postJson('/api/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'items' => [['sale_item_id' => $saleItemId, 'qty' => 2]],
        ], inventoryHeaders($this))->assertCreated();

        $returnId = $returnCreate->json('data.id');
        $this->postJson("/api/sales-returns/{$returnId}/confirm", [], inventoryHeaders($this))->assertOk();

        expect((float) $product->fresh()->current_stock)->toBe(17.0);

        $ledger = $this->getJson("/api/inventory/ledger?product_id={$product->id}&reference_type=sales-return", inventoryHeaders($this))->assertOk();
        expect($ledger->json('data.0.direction'))->toBe('in');
    })->group('inventory', 'INVENTORY-009');

    it('INVENTORY-010 purchase return confirm decreases inventory', function () {
        $product = $this->createProduct(['current_stock' => 0, 'opening_stock' => 0]);
        $purchase = confirmPurchaseViaApi($this, $product, 20, 50);
        expect((float) $product->fresh()->current_stock)->toBe(20.0);

        $purchaseItemId = $purchase->items()->first()->id;

        $returnCreate = $this->postJson('/api/purchase-returns', [
            'purchase_id' => $purchase->id,
            'return_date' => now()->toDateString(),
            'items' => [['purchase_item_id' => $purchaseItemId, 'qty' => 5]],
        ], inventoryHeaders($this))->assertCreated();

        $returnId = $returnCreate->json('data.id');
        $this->postJson("/api/purchase-returns/{$returnId}/confirm", [], inventoryHeaders($this))->assertOk();

        expect((float) $product->fresh()->current_stock)->toBe(15.0);

        $ledger = $this->getJson("/api/inventory/ledger?product_id={$product->id}&reference_type=purchase-return", inventoryHeaders($this))->assertOk();
        expect($ledger->json('data.0.direction'))->toBe('out');
    })->group('inventory', 'INVENTORY-010');

    it('INVENTORY-011 transfer dispatch decreases source inventory', function () {
        $sourceProduct = $this->createProduct(['sku' => 'INV-XFER-01', 'current_stock' => 100, 'cost_price' => 20]);
        ['company' => $dest, 'product' => $destProduct] = createDestCompanyWithProduct($this, $sourceProduct);

        $transfer = $this->postJson('/api/transfers', [
            'transfer_type' => 'inter_company',
            'to_company_id' => $dest->id,
            'transfer_date' => now()->toDateString(),
            'items' => [['product_id' => $sourceProduct->id, 'qty' => 20]],
        ], inventoryHeaders($this))->assertCreated();

        $transferId = $transfer->json('data.id');
        $itemId = $transfer->json('data.items.0.id');

        $this->postJson("/api/transfers/{$transferId}/dispatch", [], inventoryHeaders($this))->assertOk();

        expect((float) $sourceProduct->fresh()->current_stock)->toBe(80.0);

        grantDestCompanyAccess($this, $dest);

        $this->postJson("/api/transfers/{$transferId}/receive", [
            'receipts' => [['id' => $itemId, 'received_qty' => 20]],
        ], inventoryHeadersForCompany($this, $dest->id))->assertOk();

        expect((float) $destProduct->fresh()->current_stock)->toBe(20.0);
    })->group('inventory', 'INVENTORY-011');

    it('INVENTORY-012 transfer receive increases destination inventory', function () {
        $sourceProduct = $this->createProduct(['sku' => 'INV-XFER-02', 'current_stock' => 50, 'cost_price' => 15]);
        ['company' => $dest, 'product' => $destProduct] = createDestCompanyWithProduct($this, $sourceProduct);

        $transfer = $this->postJson('/api/transfers', [
            'transfer_type' => 'inter_company',
            'to_company_id' => $dest->id,
            'transfer_date' => now()->toDateString(),
            'items' => [['product_id' => $sourceProduct->id, 'qty' => 12]],
        ], inventoryHeaders($this))->assertCreated();

        $transferId = $transfer->json('data.id');
        $itemId = $transfer->json('data.items.0.id');

        $this->postJson("/api/transfers/{$transferId}/dispatch", [], inventoryHeaders($this))->assertOk();

        grantDestCompanyAccess($this, $dest);

        $this->postJson("/api/transfers/{$transferId}/receive", [
            'receipts' => [['id' => $itemId, 'received_qty' => 12]],
        ], inventoryHeadersForCompany($this, $dest->id))->assertOk();

        expect((float) $destProduct->fresh()->current_stock)->toBe(12.0);

        $destStock = $this->getJson('/api/inventory/stock?search=INV-XFER-02', inventoryHeadersForCompany($this, $dest->id))->assertOk();
        expect((float) $destStock->json('data.0.current_stock'))->toBe(12.0);
    })->group('inventory', 'INVENTORY-012');

    it('INVENTORY-013 stock verification approval adjusts inventory to counted qty', function () {
        $product = $this->createProduct(['current_stock' => 30, 'cost_price' => 40]);

        $create = $this->postJson('/api/stock-verifications', [
            'count_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'counted_qty' => 22]],
        ], inventoryHeaders($this))->assertCreated();

        $verificationId = $create->json('data.id');

        $this->postJson("/api/stock-verifications/{$verificationId}/submit", [], inventoryHeaders($this))->assertOk();
        $this->postJson("/api/stock-verifications/{$verificationId}/approve", [], inventoryHeaders($this))->assertOk();

        expect((float) $product->fresh()->current_stock)->toBe(22.0);

        $ledger = $this->getJson("/api/inventory/ledger?product_id={$product->id}&reference_type=stock-verification", inventoryHeaders($this))->assertOk();
        expect($ledger->json('data.0.direction'))->toBe('out')
            ->and((float) $ledger->json('data.0.qty'))->toBe(8.0);
    })->group('inventory', 'INVENTORY-013');

    it('INVENTORY-014 zero stock product appears in inventory list', function () {
        $product = $this->createProduct(['name' => 'Zero Stock Plant', 'sku' => 'INV-ZERO-01', 'current_stock' => 0, 'opening_stock' => 0]);

        $response = $this->getJson('/api/inventory/stock?search=INV-ZERO-01', inventoryHeaders($this))->assertOk();

        expect(collect($response->json('data')))->toHaveCount(1)
            ->and((float) $response->json('data.0.current_stock'))->toBe(0.0);
    })->group('inventory', 'INVENTORY-014');

    it('INVENTORY-015 negative stock is blocked on oversell', function () {
        $product = $this->createProduct(['current_stock' => 5, 'retail_price' => 100, 'gst_rate' => 0]);
        $customer = $this->createCustomer();

        $create = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'payment_mode' => 'cash',
            'is_interstate' => false,
            'bill_kind' => 'tax_invoice',
            'amount_paid' => 1000,
            'items' => [
                ['product_id' => $product->id, 'qty' => 10, 'rate' => 100, 'discount' => 0, 'gst_rate' => 0],
            ],
        ], inventoryHeaders($this))->assertCreated();

        $saleId = $create->json('data.id');

        $this->postJson("/api/sales/{$saleId}/confirm", [], inventoryHeaders($this))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        expect((float) $product->fresh()->current_stock)->toBe(5.0);
        expect(StockLedgerEntry::where('product_id', $product->id)->where('reference_type', 'sale')->exists())->toBeFalse();
    })->group('inventory', 'INVENTORY-015');

    it('INVENTORY-016 API error handling for invalid inventory queries', function () {
        $this->getJson('/api/inventory/stock/cross-branch', inventoryHeaders($this))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);

        $this->getJson('/api/inventory/ledger?direction=invalid', inventoryHeaders($this))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['direction']);

        $this->app->bind(InventoryController::class, fn () => new class extends InventoryController
        {
            public function __construct()
            {
                parent::__construct(app(\App\Services\InventoryService::class), app(\App\Services\ReportExportService::class));
            }

            public function stock(\Illuminate\Http\Request $request): JsonResponse
            {
                return response()->json(['message' => 'Server Error'], 500);
            }
        });

        $this->getJson('/api/inventory/stock', inventoryHeaders($this))
            ->assertStatus(500)
            ->assertJson(['message' => 'Server Error']);
    })->group('inventory', 'INVENTORY-016');

})->group('inventory');
