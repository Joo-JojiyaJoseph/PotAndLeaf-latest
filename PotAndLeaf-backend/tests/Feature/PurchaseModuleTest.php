<?php

/**
 * Purchase module automated tests — PURCHASE-001 … PURCHASE-021.
 *
 * API coverage via Pest (Laravel). UI-only cases skipped until Vitest/Playwright.
 * Calculations verified against PurchaseCalculator (server source of truth).
 *
 * Run: php artisan test tests/Feature/PurchaseModuleTest.php
 */

use App\Http\Controllers\Api\PurchaseController;
use App\Models\Product;
use App\Models\Purchase;
use App\Support\Purchasing\PurchaseCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'purchases.view',
        'purchases.create',
        'purchases.update',
        'purchases.confirm',
        'purchases.delete',
    ]);

    $this->supplier = $this->createSupplier(['name' => 'Grower Supplies', 'status' => 'active']);
    $this->productA = $this->createProduct(['name' => 'Rose Plant', 'cost_price' => 100, 'gst_rate' => 18, 'current_stock' => 0, 'opening_stock' => 0]);
    $this->productB = $this->createProduct(['name' => 'Palm Tree', 'cost_price' => 150, 'gst_rate' => 12, 'current_stock' => 0, 'opening_stock' => 0]);
});

function purchaseHeaders(object $test): array
{
    return $test->apiHeaders();
}

function purchaseLine(string $productId, float $qty, float $rate, float $discount = 0, float $gstRate = 0): array
{
    return [
        'product_id' => $productId,
        'qty' => $qty,
        'rate' => $rate,
        'discount' => $discount,
        'gst_rate' => $gstRate,
    ];
}

function validPurchasePayload(object $test, array $items, array $overrides = []): array
{
    return array_merge([
        'supplier_id' => $test->supplier->id,
        'purchase_date' => now()->toDateString(),
        'is_interstate' => false,
        'landed_cost_total' => 0,
        'items' => $items,
    ], $overrides);
}

function expectedPurchaseTotals(array $items, bool $isInterstate = false, float $landed = 0.0): array
{
    return app(PurchaseCalculator::class)->compute($items, $isInterstate, $landed)['totals'];
}

function createDraftPurchase(object $test, array $items, array $overrides = []): Purchase
{
    $response = $test->postJson(
        '/api/purchases',
        validPurchasePayload($test, $items, $overrides),
        purchaseHeaders($test),
    )->assertCreated();

    return Purchase::findOrFail($response->json('data.id'));
}

describe('Purchase module — API', function () {

    it('PURCHASE-002 create purchase saves draft', function () {
        $items = [purchaseLine($this->productA->id, 10, 100)];

        $response = $this->postJson(
            '/api/purchases',
            validPurchasePayload($this, $items),
            purchaseHeaders($this),
        );

        $response->assertCreated()
            ->assertJsonPath('message', 'Purchase saved as draft.')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.supplier.id', $this->supplier->id);

        expect(Purchase::forCompany($this->company->id)->count())->toBe(1)
            ->and($response->json('data.purchase_no'))->not->toBeEmpty();
    })->group('purchase', 'PURCHASE-002');

    it('PURCHASE-003 select supplier requires valid active supplier', function () {
        $items = [purchaseLine($this->productA->id, 5, 100)];

        $missing = $this->postJson('/api/purchases', [
            'purchase_date' => now()->toDateString(),
            'items' => $items,
        ], purchaseHeaders($this));
        $missing->assertUnprocessable()->assertJsonValidationErrors(['supplier_id']);

        $invalid = $this->postJson('/api/purchases', validPurchasePayload($this, $items, [
            'supplier_id' => '00000000-0000-0000-0000-000000000099',
        ]), purchaseHeaders($this));
        $invalid->assertUnprocessable()->assertJsonValidationErrors(['supplier_id']);

        $created = $this->postJson('/api/purchases', validPurchasePayload($this, $items), purchaseHeaders($this));
        $created->assertCreated()
            ->assertJsonPath('data.supplier.id', $this->supplier->id)
            ->assertJsonPath('data.supplier.name', 'Grower Supplies');
    })->group('purchase', 'PURCHASE-003');

    it('PURCHASE-004 add product line persists item', function () {
        $items = [purchaseLine($this->productA->id, 8, 95, 10, 18)];

        $response = $this->postJson(
            '/api/purchases',
            validPurchasePayload($this, $items),
            purchaseHeaders($this),
        )->assertCreated();

        expect(collect($response->json('data.items')))->toHaveCount(1)
            ->and($response->json('data.items.0.product_id'))->toBe($this->productA->id)
            ->and((float) $response->json('data.items.0.qty'))->toBe(8.0);
    })->group('purchase', 'PURCHASE-004');

    it('PURCHASE-005 add multiple products persists all lines', function () {
        $items = [
            purchaseLine($this->productA->id, 10, 100, 0, 18),
            purchaseLine($this->productB->id, 2, 150, 30, 12),
        ];

        $response = $this->postJson(
            '/api/purchases',
            validPurchasePayload($this, $items),
            purchaseHeaders($this),
        )->assertCreated();

        $productIds = collect($response->json('data.items'))->pluck('product_id')->sort()->values()->all();
        expect($productIds)->toBe(collect([$this->productA->id, $this->productB->id])->sort()->values()->all())
            ->and(collect($response->json('data.items')))->toHaveCount(2);
    })->group('purchase', 'PURCHASE-005');

    it('PURCHASE-006 remove product line on update', function () {
        $purchase = createDraftPurchase($this, [
            purchaseLine($this->productA->id, 5, 100),
            purchaseLine($this->productB->id, 3, 150),
        ]);

        $response = $this->putJson(
            "/api/purchases/{$purchase->id}",
            validPurchasePayload($this, [
                purchaseLine($this->productA->id, 5, 100),
            ]),
            purchaseHeaders($this),
        )->assertOk();

        expect(collect($response->json('data.items')))->toHaveCount(1)
            ->and($response->json('data.items.0.product_id'))->toBe($this->productA->id)
            ->and($purchase->fresh()->items)->toHaveCount(1);
    })->group('purchase', 'PURCHASE-006');

    it('PURCHASE-007 enter valid quantity accepts positive qty', function () {
        $items = [purchaseLine($this->productA->id, 12.5, 80)];

        $response = $this->postJson(
            '/api/purchases',
            validPurchasePayload($this, $items),
            purchaseHeaders($this),
        )->assertCreated();

        expect((float) $response->json('data.items.0.qty'))->toBe(12.5);
    })->group('purchase', 'PURCHASE-007');

    it('PURCHASE-008 enter zero quantity is rejected', function () {
        $response = $this->postJson(
            '/api/purchases',
            validPurchasePayload($this, [purchaseLine($this->productA->id, 0, 100)]),
            purchaseHeaders($this),
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.qty']);
    })->group('purchase', 'PURCHASE-008');

    it('PURCHASE-009 enter negative quantity is rejected', function () {
        $response = $this->postJson(
            '/api/purchases',
            validPurchasePayload($this, [purchaseLine($this->productA->id, -5, 100)]),
            purchaseHeaders($this),
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.qty']);
    })->group('purchase', 'PURCHASE-009');

    it('PURCHASE-010 change quantity recalculates totals', function () {
        $purchase = createDraftPurchase($this, [purchaseLine($this->productA->id, 10, 100)]);

        $updated = $this->putJson(
            "/api/purchases/{$purchase->id}",
            validPurchasePayload($this, [purchaseLine($this->productA->id, 20, 100)]),
            purchaseHeaders($this),
        )->assertOk();

        $expected = expectedPurchaseTotals([purchaseLine($this->productA->id, 20, 100)]);

        expect((float) $updated->json('data.subtotal'))->toBe($expected['subtotal'])
            ->and((float) $updated->json('data.items.0.qty'))->toBe(20.0);
    })->group('purchase', 'PURCHASE-010');

    it('PURCHASE-011 verify subtotal calculation', function () {
        $items = [
            purchaseLine($this->productA->id, 10, 100),
            purchaseLine($this->productB->id, 5, 50),
        ];
        $expected = expectedPurchaseTotals($items);

        $response = $this->postJson(
            '/api/purchases',
            validPurchasePayload($this, $items),
            purchaseHeaders($this),
        )->assertCreated();

        expect((float) $response->json('data.subtotal'))->toBe($expected['subtotal'])
            ->and($expected['subtotal'])->toBe(1250.0);
    })->group('purchase', 'PURCHASE-011');

    it('PURCHASE-012 verify discount total calculation', function () {
        $items = [purchaseLine($this->productA->id, 10, 100, 75, 0)];
        $expected = expectedPurchaseTotals($items);

        $response = $this->postJson(
            '/api/purchases',
            validPurchasePayload($this, $items),
            purchaseHeaders($this),
        )->assertCreated();

        expect((float) $response->json('data.discount_total'))->toBe($expected['discount_total'])
            ->and((float) $response->json('data.subtotal'))->toBe(925.0)
            ->and((float) $response->json('data.items.0.taxable_value'))->toBe(925.0);
    })->group('purchase', 'PURCHASE-012');

    it('PURCHASE-013 verify tax split for intra and inter state', function () {
        $intraItems = [purchaseLine($this->productA->id, 10, 100, 0, 18)];
        $intraExpected = app(PurchaseCalculator::class)->compute($intraItems, false, 0);

        $intra = $this->postJson(
            '/api/purchases',
            validPurchasePayload($this, $intraItems, ['is_interstate' => false]),
            purchaseHeaders($this),
        )->assertCreated();

        expect((float) $intra->json('data.tax_total'))->toBe($intraExpected['totals']['tax_total'])
            ->and((float) $intra->json('data.items.0.cgst_amount'))->toBe(90.0)
            ->and((float) $intra->json('data.items.0.sgst_amount'))->toBe(90.0)
            ->and((float) $intra->json('data.items.0.igst_amount'))->toBe(0.0);

        $interItems = [purchaseLine($this->productA->id, 10, 100, 0, 18)];
        $interExpected = app(PurchaseCalculator::class)->compute($interItems, true, 0);

        $inter = $this->postJson(
            '/api/purchases',
            validPurchasePayload($this, $interItems, ['is_interstate' => true]),
            purchaseHeaders($this),
        )->assertCreated();

        expect((float) $inter->json('data.tax_total'))->toBe($interExpected['totals']['tax_total'])
            ->and((float) $inter->json('data.items.0.igst_amount'))->toBe(180.0)
            ->and((float) $inter->json('data.items.0.cgst_amount'))->toBe(0.0)
            ->and((float) $inter->json('data.items.0.sgst_amount'))->toBe(0.0);
    })->group('purchase', 'PURCHASE-013');

    it('PURCHASE-014 verify grand total includes tax and landed cost', function () {
        $items = [
            purchaseLine($this->productA->id, 10, 100, 0, 18),
            purchaseLine($this->productB->id, 2, 150, 30, 12),
        ];
        $expected = expectedPurchaseTotals($items, false, 100.0);

        $response = $this->postJson(
            '/api/purchases',
            validPurchasePayload($this, $items, ['landed_cost_total' => 100]),
            purchaseHeaders($this),
        )->assertCreated();

        expect((float) $response->json('data.grand_total'))->toBe($expected['grand_total'])
            ->and((float) $response->json('data.landed_cost_total'))->toBe(100.0)
            ->and($expected['grand_total'])->toBe(1582.4);
    })->group('purchase', 'PURCHASE-014');

    it('PURCHASE-015 submit valid purchase with full header fields', function () {
        $items = [purchaseLine($this->productA->id, 6, 120, 20, 5)];

        $response = $this->postJson(
            '/api/purchases',
            validPurchasePayload($this, $items, [
                'invoice_no' => 'INV-PUR-001',
                'invoice_date' => now()->toDateString(),
                'notes' => 'Seasonal stock order',
                'landed_cost_total' => 25,
            ]),
            purchaseHeaders($this),
        )->assertCreated();

        $expected = expectedPurchaseTotals($items, false, 25.0);

        expect($response->json('data.invoice_no'))->toBe('INV-PUR-001')
            ->and($response->json('data.notes'))->toBe('Seasonal stock order')
            ->and((float) $response->json('data.grand_total'))->toBe($expected['grand_total'])
            ->and($response->json('data.status'))->toBe('draft');
    })->group('purchase', 'PURCHASE-015');

    it('PURCHASE-016 submit incomplete purchase is rejected', function () {
        $empty = $this->postJson('/api/purchases', [], purchaseHeaders($this));
        $empty->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier_id', 'purchase_date', 'items']);

        $noLines = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'items' => [],
        ], purchaseHeaders($this));
        $noLines->assertUnprocessable()->assertJsonValidationErrors(['items']);
    })->group('purchase', 'PURCHASE-016');

    it('PURCHASE-017 edit draft purchase updates header and lines', function () {
        $purchase = createDraftPurchase($this, [purchaseLine($this->productA->id, 5, 100)]);

        $response = $this->putJson(
            "/api/purchases/{$purchase->id}",
            validPurchasePayload($this, [purchaseLine($this->productA->id, 15, 110, 50, 18)], [
                'invoice_no' => 'INV-EDIT-01',
                'notes' => 'Updated purchase',
            ]),
            purchaseHeaders($this),
        )->assertOk()
            ->assertJsonPath('message', 'Purchase updated.');

        $expected = expectedPurchaseTotals([purchaseLine($this->productA->id, 15, 110, 50, 18)]);

        expect($response->json('data.invoice_no'))->toBe('INV-EDIT-01')
            ->and($response->json('data.notes'))->toBe('Updated purchase')
            ->and((float) $response->json('data.subtotal'))->toBe($expected['subtotal']);
    })->group('purchase', 'PURCHASE-017');

    it('PURCHASE-018 cancel draft purchase', function () {
        $purchase = createDraftPurchase($this, [purchaseLine($this->productA->id, 4, 100)]);

        $this->deleteJson("/api/purchases/{$purchase->id}", [], purchaseHeaders($this))
            ->assertOk()
            ->assertJsonPath('message', 'Purchase cancelled.');

        expect($purchase->fresh()->status)->toBe('cancelled');
    })->group('purchase', 'PURCHASE-018');

    it('PURCHASE-019 confirm purchase posts stock and updates status', function () {
        $items = [purchaseLine($this->productA->id, 10, 100, 0, 0)];
        $purchase = createDraftPurchase($this, $items);
        $expected = expectedPurchaseTotals($items);

        $response = $this->postJson(
            "/api/purchases/{$purchase->id}/confirm",
            [],
            purchaseHeaders($this),
        )->assertOk()
            ->assertJsonPath('message', 'Purchase confirmed and stock posted.')
            ->assertJsonPath('data.status', 'confirmed');

        expect((float) $response->json('data.grand_total'))->toBe($expected['grand_total'])
            ->and((float) $this->productA->fresh()->current_stock)->toBe(10.0)
            ->and((float) $this->supplier->fresh()->outstanding)->toBe($expected['grand_total']);
    })->group('purchase', 'PURCHASE-019');

    it('PURCHASE-020 API validation error returns 422 with field errors', function () {
        $response = $this->postJson('/api/purchases', validPurchasePayload($this, [
            purchaseLine($this->productA->id, 1, 100),
        ], [
            'purchase_date' => 'not-a-date',
        ]), purchaseHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['purchase_date'])
            ->assertJsonStructure(['message', 'errors']);
    })->group('purchase', 'PURCHASE-020');

    it('PURCHASE-021 API server error on create returns 500', function () {
        $this->app->bind(PurchaseController::class, fn () => new class extends PurchaseController
        {
            public function __construct()
            {
                parent::__construct(app(\App\Services\PurchaseService::class));
            }

            public function store(\App\Http\Requests\Purchase\StorePurchaseRequest $request): JsonResponse
            {
                return response()->json(['message' => 'Server Error'], 500);
            }
        });

        $response = $this->postJson(
            '/api/purchases',
            validPurchasePayload($this, [purchaseLine($this->productA->id, 1, 100)]),
            purchaseHeaders($this),
        );

        $response->assertStatus(500)
            ->assertJson(['message' => 'Server Error']);
    })->group('purchase', 'PURCHASE-021');

})->group('purchase');

describe('Purchase module — frontend UI (requires Vitest / Playwright)', function () {

    it('PURCHASE-001 open purchase page', function () {
        $this->markTestSkipped(
            'Frontend only: PurchasesList navigates to /purchases/new. Install vitest, jsdom, @testing-library/react.'
        );
    })->group('purchase', 'PURCHASE-001');

})->group('purchase');
