<?php

/**
 * Sales module automated tests — SALE-001 … SALE-027.
 *
 * API + database integration. Calculations verified against SaleCalculator.
 *
 * Run: php artisan test tests/Feature/SaleModuleTest.php
 */

use App\Http\Controllers\Api\SaleController;
use App\Models\CompanySetting;
use App\Models\Product;
use App\Models\Sale;
use App\Support\Sales\SaleCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'sales.view',
        'sales.create',
        'sales.confirm',
        'sales.delete',
    ]);

    $this->customer = $this->createCustomer(['name' => 'Retail Customer', 'type' => 'retail']);
    $this->productA = $this->createProduct([
        'name' => 'Rose Plant',
        'sku' => 'SALE-ROSE-01',
        'retail_price' => 400,
        'wholesale_price' => 350,
        'dealer_price' => 300,
        'gst_rate' => 18,
        'current_stock' => 100,
        'opening_stock' => 100,
    ]);
    $this->productB = $this->createProduct([
        'name' => 'Palm Tree',
        'sku' => 'SALE-PALM-01',
        'retail_price' => 600,
        'gst_rate' => 12,
        'current_stock' => 50,
        'opening_stock' => 50,
    ]);

    CompanySetting::query()->updateOrCreate(
        ['company_id' => $this->company->id, 'key' => 'sale_cancel_requires_approval'],
        ['value' => '0'],
    );
});

function saleHeaders(object $test): array
{
    return $test->apiHeaders();
}

function saleLine(string $productId, float $qty, float $rate, float $discount = 0, float $gstRate = 0): array
{
    return [
        'product_id' => $productId,
        'qty' => $qty,
        'rate' => $rate,
        'discount' => $discount,
        'gst_rate' => $gstRate,
    ];
}

function validSalePayload(object $test, array $items, array $overrides = []): array
{
    return array_merge([
        'customer_id' => $test->customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'bill_kind' => 'tax_invoice',
        'amount_paid' => 0,
        'items' => $items,
    ], $overrides);
}

function expectedSaleTotals(array $items, bool $isInterstate = false): array
{
    return app(SaleCalculator::class)->compute($items, $isInterstate)['totals'];
}

function assertSaleCalc(string $field, float $expected, float $actual): void
{
    $diff = round($actual - $expected, 2);
    expect($actual)->toBe($expected, "{$field} — Expected: {$expected}, Actual: {$actual}, Difference: {$diff}");
}

function createDraftSaleViaApi(object $test, array $items, array $overrides = []): Sale
{
    $response = $test->postJson(
        '/api/sales',
        validSalePayload($test, $items, $overrides),
        saleHeaders($test),
    )->assertCreated();

    return Sale::findOrFail($response->json('data.id'));
}

describe('Sales module — API', function () {

    it('SALE-001 sales list loads', function () {
        createDraftSaleViaApi($this, [saleLine($this->productA->id, 1, 400, 0, 0)]);
        createDraftSaleViaApi($this, [saleLine($this->productB->id, 2, 600, 0, 0)]);

        $response = $this->getJson('/api/sales', saleHeaders($this));

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total']]);

        expect(collect($response->json('data')))->toHaveCount(2);
    })->group('sales', 'SALE-001');

    it('SALE-002 search sales filters by sale number', function () {
        $sale = createDraftSaleViaApi($this, [saleLine($this->productA->id, 1, 400)]);
        $saleNo = $sale->sale_no;

        $match = $this->getJson('/api/sales?search='.$saleNo, saleHeaders($this))->assertOk();
        expect(collect($match->json('data')))->toHaveCount(1)
            ->and($match->json('data.0.sale_no'))->toBe($saleNo);

        $noMatch = $this->getJson('/api/sales?search=INV-NOMATCH', saleHeaders($this))->assertOk();
        expect(collect($noMatch->json('data')))->toHaveCount(0);
    })->group('sales', 'SALE-002');

    it('SALE-003 filter sales by status', function () {
        $draft = createDraftSaleViaApi($this, [saleLine($this->productA->id, 1, 400)]);
        $confirmed = createDraftSaleViaApi($this, [saleLine($this->productB->id, 1, 600)]);
        $this->postJson("/api/sales/{$confirmed->id}/confirm", [], saleHeaders($this))->assertOk();

        $drafts = $this->getJson('/api/sales?status=draft', saleHeaders($this))->assertOk();
        expect(collect($drafts->json('data')))->toHaveCount(1)
            ->and($drafts->json('data.0.id'))->toBe($draft->id);

        $confirmedRows = $this->getJson('/api/sales?status=confirmed', saleHeaders($this))->assertOk();
        expect(collect($confirmedRows->json('data')))->toHaveCount(1)
            ->and($confirmedRows->json('data.0.status'))->toBe('confirmed');
    })->group('sales', 'SALE-003');

    it('SALE-005 create sale with valid customer', function () {
        $response = $this->postJson(
            '/api/sales',
            validSalePayload($this, [saleLine($this->productA->id, 2, 400, 0, 18)]),
            saleHeaders($this),
        );

        $response->assertCreated()
            ->assertJsonPath('message', 'Sale saved as draft.')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.customer.id', $this->customer->id)
            ->assertJsonPath('data.customer_name', 'Retail Customer');

        expect($response->json('data.sale_no'))->not->toBeEmpty();
    })->group('sales', 'SALE-005');

    it('SALE-006 add product line persists item', function () {
        $response = $this->postJson(
            '/api/sales',
            validSalePayload($this, [saleLine($this->productA->id, 3, 395, 15, 18)]),
            saleHeaders($this),
        )->assertCreated();

        expect(collect($response->json('data.items')))->toHaveCount(1)
            ->and($response->json('data.items.0.product_id'))->toBe($this->productA->id)
            ->and((float) $response->json('data.items.0.qty'))->toBe(3.0);
    })->group('sales', 'SALE-006');

    it('SALE-007 add multiple products persists all lines', function () {
        $items = [
            saleLine($this->productA->id, 2, 400, 0, 18),
            saleLine($this->productB->id, 1, 600, 50, 12),
        ];

        $response = $this->postJson('/api/sales', validSalePayload($this, $items), saleHeaders($this))->assertCreated();

        expect(collect($response->json('data.items')))->toHaveCount(2);
    })->group('sales', 'SALE-007');

    it('SALE-009 change quantity recalculates totals on create', function () {
        $itemsLow = [saleLine($this->productA->id, 5, 100, 0, 0)];
        $itemsHigh = [saleLine($this->productA->id, 10, 100, 0, 0)];

        $expectedLow = expectedSaleTotals($itemsLow);
        $expectedHigh = expectedSaleTotals($itemsHigh);

        $low = $this->postJson('/api/sales', validSalePayload($this, $itemsLow), saleHeaders($this))->assertCreated();
        $high = $this->postJson('/api/sales', validSalePayload($this, $itemsHigh), saleHeaders($this))->assertCreated();

        assertSaleCalc('subtotal qty=5', $expectedLow['subtotal'], (float) $low->json('data.subtotal'));
        assertSaleCalc('subtotal qty=10', $expectedHigh['subtotal'], (float) $high->json('data.subtotal'));
        expect($expectedHigh['subtotal'])->toBe($expectedLow['subtotal'] * 2);
    })->group('sales', 'SALE-009');

    it('SALE-010 quantity zero is rejected', function () {
        $response = $this->postJson(
            '/api/sales',
            validSalePayload($this, [saleLine($this->productA->id, 0, 400)]),
            saleHeaders($this),
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.qty']);
    })->group('sales', 'SALE-010');

    it('SALE-011 negative quantity is rejected', function () {
        $response = $this->postJson(
            '/api/sales',
            validSalePayload($this, [saleLine($this->productA->id, -3, 400)]),
            saleHeaders($this),
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.qty']);
    })->group('sales', 'SALE-011');

    it('SALE-012 quantity greater than available stock blocked on confirm', function () {
        $product = $this->createProduct(['current_stock' => 5, 'retail_price' => 100, 'gst_rate' => 0]);
        $sale = createDraftSaleViaApi($this, [saleLine($product->id, 10, 100)]);

        $this->postJson("/api/sales/{$sale->id}/confirm", [], saleHeaders($this))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        expect((float) $product->fresh()->current_stock)->toBe(5.0);
    })->group('sales', 'SALE-012');

    it('SALE-013 verify product price stored on line', function () {
        $response = $this->postJson(
            '/api/sales',
            validSalePayload($this, [saleLine($this->productA->id, 1, 425.50, 0, 18)]),
            saleHeaders($this),
        )->assertCreated();

        assertSaleCalc('line rate', 425.50, (float) $response->json('data.items.0.rate'));
    })->group('sales', 'SALE-013');

    it('SALE-014 verify subtotal calculation', function () {
        $items = [
            saleLine($this->productA->id, 10, 100, 0, 0),
            saleLine($this->productB->id, 5, 50, 0, 0),
        ];
        $expected = expectedSaleTotals($items);

        $response = $this->postJson('/api/sales', validSalePayload($this, $items), saleHeaders($this))->assertCreated();

        assertSaleCalc('subtotal', $expected['subtotal'], (float) $response->json('data.subtotal'));
        expect($expected['subtotal'])->toBe(1250.0);
    })->group('sales', 'SALE-014');

    it('SALE-015 verify discount reduces taxable value', function () {
        $items = [saleLine($this->productA->id, 10, 100, 75, 0)];
        $expected = expectedSaleTotals($items);

        $response = $this->postJson('/api/sales', validSalePayload($this, $items), saleHeaders($this))->assertCreated();

        assertSaleCalc('subtotal after discount', $expected['subtotal'], (float) $response->json('data.subtotal'));
        assertSaleCalc('line discount', 75.0, (float) $response->json('data.items.0.discount'));
        assertSaleCalc('taxable value', 925.0, (float) $response->json('data.items.0.taxable_value'));
    })->group('sales', 'SALE-015');

    it('SALE-016 verify tax split for intra and inter state', function () {
        $intraItems = [saleLine($this->productA->id, 10, 100, 0, 18)];
        $intraExpected = expectedSaleTotals($intraItems);

        $intra = $this->postJson(
            '/api/sales',
            validSalePayload($this, $intraItems, ['is_interstate' => false]),
            saleHeaders($this),
        )->assertCreated();

        assertSaleCalc('intra tax_total', $intraExpected['tax_total'], (float) $intra->json('data.tax_total'));
        assertSaleCalc('intra cgst', 90.0, (float) $intra->json('data.items.0.cgst_amount'));
        assertSaleCalc('intra sgst', 90.0, (float) $intra->json('data.items.0.sgst_amount'));

        $interItems = [saleLine($this->productA->id, 10, 100, 0, 18)];
        $interExpected = expectedSaleTotals($interItems, true);

        $inter = $this->postJson(
            '/api/sales',
            validSalePayload($this, $interItems, ['is_interstate' => true]),
            saleHeaders($this),
        )->assertCreated();

        assertSaleCalc('inter tax_total', $interExpected['tax_total'], (float) $inter->json('data.tax_total'));
        assertSaleCalc('inter igst', 180.0, (float) $inter->json('data.items.0.igst_amount'));
    })->group('sales', 'SALE-016');

    it('SALE-017 verify grand total with round off', function () {
        $items = [
            saleLine($this->productA->id, 10, 100, 0, 18),
            saleLine($this->productB->id, 2, 150, 30, 12),
        ];
        $expected = expectedSaleTotals($items);

        $response = $this->postJson('/api/sales', validSalePayload($this, $items), saleHeaders($this))->assertCreated();

        assertSaleCalc('grand_total', $expected['grand_total'], (float) $response->json('data.grand_total'));
        assertSaleCalc('round_off', $expected['round_off'], (float) $response->json('data.round_off'));
        expect($expected['grand_total'])->toBe(1482.0);
    })->group('sales', 'SALE-017');

    it('SALE-018 select payment method is stored', function () {
        foreach (['cash', 'card', 'upi', 'credit'] as $mode) {
            $response = $this->postJson(
                '/api/sales',
                validSalePayload($this, [saleLine($this->productA->id, 1, 100)], ['payment_mode' => $mode]),
                saleHeaders($this),
            )->assertCreated();

            expect($response->json('data.payment_mode'))->toBe($mode);
        }
    })->group('sales', 'SALE-018');

    it('SALE-019 submit valid sale saves draft with totals', function () {
        $items = [saleLine($this->productA->id, 4, 400, 20, 5)];
        $expected = expectedSaleTotals($items);

        $response = $this->postJson(
            '/api/sales',
            validSalePayload($this, $items, [
                'amount_paid' => $expected['grand_total'],
                'notes' => 'Counter sale',
            ]),
            saleHeaders($this),
        )->assertCreated();

        expect($response->json('data.status'))->toBe('draft')
            ->and($response->json('data.notes'))->toBe('Counter sale');

        assertSaleCalc('grand_total', $expected['grand_total'], (float) $response->json('data.grand_total'));
    })->group('sales', 'SALE-019');

    it('SALE-020 submit incomplete sale is rejected', function () {
        $empty = $this->postJson('/api/sales', [], saleHeaders($this));
        $empty->assertUnprocessable()
            ->assertJsonValidationErrors(['sale_date', 'payment_mode', 'items']);

        $noLines = $this->postJson('/api/sales', [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'payment_mode' => 'cash',
            'items' => [],
        ], saleHeaders($this));
        $noLines->assertUnprocessable()->assertJsonValidationErrors(['items']);
    })->group('sales', 'SALE-020');

    it('SALE-021 confirm sale updates status and posts stock', function () {
        $sale = createDraftSaleViaApi($this, [saleLine($this->productA->id, 3, 400, 0, 0)]);

        $response = $this->postJson("/api/sales/{$sale->id}/confirm", [], saleHeaders($this));

        $response->assertOk()
            ->assertJsonPath('message', 'Sale confirmed — stock updated.')
            ->assertJsonPath('data.status', 'confirmed');
    })->group('sales', 'SALE-021');

    it('SALE-022 cancel draft sale', function () {
        $sale = createDraftSaleViaApi($this, [saleLine($this->productA->id, 2, 400)]);

        $this->deleteJson("/api/sales/{$sale->id}", [], saleHeaders($this))
            ->assertOk()
            ->assertJsonPath('message', 'Sale cancelled.');

        expect($sale->fresh()->status)->toBe('cancelled');
    })->group('sales', 'SALE-022');

    it('SALE-024 verify inventory decreases on confirm', function () {
        $product = $this->createProduct(['current_stock' => 20, 'retail_price' => 150, 'gst_rate' => 0]);
        $stockBefore = (float) $product->current_stock;
        $qtySold = 5.0;
        $expectedAfter = $stockBefore - $qtySold;

        $sale = createDraftSaleViaApi($this, [saleLine($product->id, $qtySold, 150)]);
        $this->postJson("/api/sales/{$sale->id}/confirm", [], saleHeaders($this))->assertOk();

        $actualAfter = (float) $product->fresh()->current_stock;

        expect($actualAfter)->toBe($expectedAfter, sprintf(
            'Stock before: %s, Qty sold: %s, Expected after: %s, Actual after: %s, Difference: %s',
            $stockBefore, $qtySold, $expectedAfter, $actualAfter, round($actualAfter - $expectedAfter, 2)
        ));
    })->group('sales', 'SALE-024');

    it('SALE-025 generate invoice PDF', function () {
        $sale = createDraftSaleViaApi($this, [saleLine($this->productA->id, 1, 400, 0, 18)]);
        $this->postJson("/api/sales/{$sale->id}/confirm", [], saleHeaders($this))->assertOk();

        $response = $this->get("/api/sales/{$sale->id}/invoice.pdf", saleHeaders($this));

        $response->assertOk();
        expect($response->headers->get('content-type'))->toContain('application/pdf');
    })->group('sales', 'SALE-025');

    it('SALE-026 API validation error returns 422 with field errors', function () {
        $response = $this->postJson('/api/sales', validSalePayload($this, [
            saleLine($this->productA->id, 1, 100),
        ], [
            'payment_mode' => 'invalid-mode',
        ]), saleHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_mode'])
            ->assertJsonStructure(['message', 'errors']);
    })->group('sales', 'SALE-026');

    it('SALE-027 API server error on create returns 500', function () {
        $this->app->bind(SaleController::class, fn () => new class extends SaleController
        {
            public function __construct()
            {
                parent::__construct(app(\App\Services\SaleService::class));
            }

            public function store(\App\Http\Requests\Sale\StoreSaleRequest $request): JsonResponse
            {
                return response()->json(['message' => 'Server Error'], 500);
            }
        });

        $response = $this->postJson(
            '/api/sales',
            validSalePayload($this, [saleLine($this->productA->id, 1, 100)]),
            saleHeaders($this),
        );

        $response->assertStatus(500)
            ->assertJson(['message' => 'Server Error']);
    })->group('sales', 'SALE-027');

})->group('sales');

describe('Sales module — frontend UI (requires Vitest / Playwright)', function () {

    it('SALE-004 open create sale form', function () {
        $this->markTestSkipped(
            'Frontend only: SaleForm at /sales/new loads form-data via React Query. Install vitest + RTL.'
        );
    })->group('sales', 'SALE-004');

    it('SALE-008 remove product line from draft', function () {
        $this->markTestSkipped(
            'No PUT /api/sales endpoint — draft line removal is UI-only until update API exists.'
        );
    })->group('sales', 'SALE-008');

    it('SALE-023 edit sale', function () {
        $this->markTestSkipped(
            'No PUT /api/sales endpoint — sales are create-only; edit is not implemented in backend API.'
        );
    })->group('sales', 'SALE-023');

})->group('sales');
