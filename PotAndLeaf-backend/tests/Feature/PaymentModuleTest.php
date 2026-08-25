<?php

/**
 * Supplier Payment module tests — PAYMENT-001 … PAYMENT-013.
 *
 * Real API + database integration (no mocked business logic).
 *
 * Run: php artisan test tests/Feature/PaymentModuleTest.php
 */

use App\Http\Controllers\Api\SupplierPaymentController;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'payments.view',
        'payments.create',
        'payments.delete',
        'purchases.view',
        'purchases.create',
        'purchases.confirm',
    ]);

    $this->supplier = $this->createSupplier(['name' => 'Payment Supplier', 'status' => 'active']);
    $this->otherSupplier = $this->createSupplier(['name' => 'Other Supplier', 'status' => 'active']);
    $this->product = $this->createProduct([
        'name' => 'Payment Product',
        'cost_price' => 100,
        'gst_rate' => 0,
        'current_stock' => 0,
        'opening_stock' => 0,
    ]);
});

function paymentHeaders(object $test): array
{
    return $test->apiHeaders();
}

function validPaymentPayload(object $test, array $overrides = []): array
{
    return array_merge([
        'supplier_id' => $test->supplier->id,
        'payment_date' => now()->toDateString(),
        'amount' => 500,
        'mode' => 'cash',
        'reference' => 'REF-001',
        'notes' => 'Test payment',
    ], $overrides);
}

function confirmPurchaseForPayment(object $test, ?Supplier $supplier = null, float $qty = 10, float $rate = 100): Purchase
{
    $supplier ??= $test->supplier;

    $create = $test->postJson('/api/purchases', [
        'supplier_id' => $supplier->id,
        'purchase_date' => now()->toDateString(),
        'is_interstate' => false,
        'items' => [
            ['product_id' => $test->product->id, 'qty' => $qty, 'rate' => $rate, 'discount' => 0, 'gst_rate' => 0],
        ],
    ], paymentHeaders($test))->assertCreated();

    $purchaseId = $create->json('data.id');
    $test->postJson("/api/purchases/{$purchaseId}/confirm", [], paymentHeaders($test))->assertOk();

    return Purchase::findOrFail($purchaseId);
}

function recordPaymentViaApi(object $test, array $overrides = []): SupplierPayment
{
    $response = $test->postJson(
        '/api/supplier-payments',
        validPaymentPayload($test, $overrides),
        paymentHeaders($test),
    )->assertCreated();

    return SupplierPayment::findOrFail($response->json('data.id'));
}

describe('Payment module — API', function () {

    it('PAYMENT-001 payment list loads', function () {
        confirmPurchaseForPayment($this);
        recordPaymentViaApi($this, ['amount' => 200]);
        recordPaymentViaApi($this, ['amount' => 300, 'mode' => 'bank']);

        $response = $this->getJson('/api/supplier-payments', paymentHeaders($this));

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total']]);

        expect(collect($response->json('data')))->toHaveCount(2);
    })->group('payment', 'PAYMENT-001');

    it('PAYMENT-002 search payment filters by supplier', function () {
        confirmPurchaseForPayment($this, $this->supplier, 10, 100);
        confirmPurchaseForPayment($this, $this->otherSupplier, 5, 200);

        recordPaymentViaApi($this, ['supplier_id' => $this->supplier->id, 'amount' => 400]);
        recordPaymentViaApi($this, ['supplier_id' => $this->otherSupplier->id, 'amount' => 250]);

        $all = $this->getJson('/api/supplier-payments', paymentHeaders($this))->assertOk();
        expect(collect($all->json('data')))->toHaveCount(2);

        $filtered = $this->getJson(
            '/api/supplier-payments?supplier_id='.$this->supplier->id,
            paymentHeaders($this),
        )->assertOk();

        expect(collect($filtered->json('data')))->toHaveCount(1)
            ->and($filtered->json('data.0.supplier_id'))->toBe($this->supplier->id)
            ->and((float) $filtered->json('data.0.amount'))->toBe(400.0);
    })->group('payment', 'PAYMENT-002');

    it('PAYMENT-003 create valid payment', function () {
        $purchase = confirmPurchaseForPayment($this, $this->supplier, 10, 100);
        $outstandingBefore = (float) $this->supplier->fresh()->outstanding;

        $response = $this->postJson(
            '/api/supplier-payments',
            validPaymentPayload($this, [
                'purchase_id' => $purchase->id,
                'amount' => 600,
                'mode' => 'upi',
                'reference' => 'UPI-12345',
            ]),
            paymentHeaders($this),
        );

        $response->assertCreated()
            ->assertJsonPath('message', 'Payment recorded.')
            ->assertJsonPath('data.supplier_id', $this->supplier->id)
            ->assertJsonPath('data.purchase_id', $purchase->id)
            ->assertJsonPath('data.mode', 'upi')
            ->assertJsonPath('data.reference', 'UPI-12345');

        expect((float) $response->json('data.amount'))->toBe(600.0)
            ->and($response->json('data.payment_no'))->not->toBeEmpty()
            ->and((float) $this->supplier->fresh()->outstanding)->toBe($outstandingBefore - 600.0);

        $payables = $this->getJson(
            '/api/supplier-payments/payables?supplier_id='.$this->supplier->id,
            paymentHeaders($this),
        )->assertOk();

        $row = collect($payables->json('data.payables'))->firstWhere('id', $purchase->id);
        expect((float) $row['paid'])->toBe(600.0)
            ->and((float) $row['balance'])->toBe(400.0)
            ->and($row['status'])->toBe('partial');
    })->group('payment', 'PAYMENT-003');

    it('PAYMENT-004 empty payment form rejected', function () {
        $this->postJson('/api/supplier-payments', [], paymentHeaders($this))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier_id', 'payment_date', 'amount', 'mode']);
    })->group('payment', 'PAYMENT-004');

    it('PAYMENT-005 zero payment amount rejected', function () {
        confirmPurchaseForPayment($this);

        $this->postJson(
            '/api/supplier-payments',
            validPaymentPayload($this, ['amount' => 0]),
            paymentHeaders($this),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    })->group('payment', 'PAYMENT-005');

    it('PAYMENT-006 negative payment amount rejected', function () {
        confirmPurchaseForPayment($this);

        $this->postJson(
            '/api/supplier-payments',
            validPaymentPayload($this, ['amount' => -100]),
            paymentHeaders($this),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    })->group('payment', 'PAYMENT-006');

    it('PAYMENT-007 payment greater than outstanding amount rejected', function () {
        $purchase = confirmPurchaseForPayment($this, $this->supplier, 10, 100);
        $outstanding = (float) $this->supplier->fresh()->outstanding;
        expect($outstanding)->toBe(1000.0);

        $this->postJson(
            '/api/supplier-payments',
            validPaymentPayload($this, [
                'purchase_id' => $purchase->id,
                'amount' => 1500,
            ]),
            paymentHeaders($this),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        expect((float) $this->supplier->fresh()->outstanding)->toBe(1000.0);
        expect(SupplierPayment::count())->toBe(0);

        $payables = $this->getJson(
            '/api/supplier-payments/payables?supplier_id='.$this->supplier->id,
            paymentHeaders($this),
        )->assertOk();

        $row = collect($payables->json('data.payables'))->firstWhere('id', $purchase->id);
        expect((float) $row['paid'])->toBe(0.0)
            ->and((float) $row['balance'])->toBe(1000.0)
            ->and($row['status'])->toBe('unpaid');
    })->group('payment', 'PAYMENT-007');

    it('PAYMENT-014 payment equal to outstanding accepted', function () {
        confirmPurchaseForPayment($this, $this->supplier, 10, 100);

        $this->postJson(
            '/api/supplier-payments',
            validPaymentPayload($this, ['amount' => 1000]),
            paymentHeaders($this),
        )->assertCreated();

        expect((float) $this->supplier->fresh()->outstanding)->toBe(0.0);
    })->group('payment', 'PAYMENT-014');

    it('PAYMENT-015 payment less than outstanding accepted', function () {
        confirmPurchaseForPayment($this, $this->supplier, 10, 100);

        $this->postJson(
            '/api/supplier-payments',
            validPaymentPayload($this, ['amount' => 400]),
            paymentHeaders($this),
        )->assertCreated();

        expect((float) $this->supplier->fresh()->outstanding)->toBe(600.0);
    })->group('payment', 'PAYMENT-015');

    it('PAYMENT-016 payment greater than GRN balance rejected when supplier outstanding is higher', function () {
        confirmPurchaseForPayment($this, $this->supplier, 10, 100);
        confirmPurchaseForPayment($this, $this->supplier, 10, 100);
        expect((float) $this->supplier->fresh()->outstanding)->toBe(2000.0);

        $firstPurchase = Purchase::where('supplier_id', $this->supplier->id)->orderBy('created_at')->first();

        $this->postJson(
            '/api/supplier-payments',
            validPaymentPayload($this, [
                'purchase_id' => $firstPurchase->id,
                'amount' => 1500,
            ]),
            paymentHeaders($this),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        expect((float) $this->supplier->fresh()->outstanding)->toBe(2000.0);
        expect(SupplierPayment::count())->toBe(0);

        $payables = $this->getJson(
            '/api/supplier-payments/payables?supplier_id='.$this->supplier->id,
            paymentHeaders($this),
        )->assertOk();

        $row = collect($payables->json('data.payables'))->firstWhere('id', $firstPurchase->id);
        expect((float) $row['paid'])->toBe(0.0)
            ->and((float) $row['balance'])->toBe(1000.0);
    })->group('payment', 'PAYMENT-016');

    it('PAYMENT-008 valid payment methods accepted', function () {
        confirmPurchaseForPayment($this, $this->supplier, 40, 100);

        foreach (['cash', 'bank', 'upi', 'cheque'] as $mode) {
            $response = $this->postJson(
                '/api/supplier-payments',
                validPaymentPayload($this, ['amount' => 100, 'mode' => $mode]),
                paymentHeaders($this),
            )->assertCreated();

            expect($response->json('data.mode'))->toBe($mode);
        }
    })->group('payment', 'PAYMENT-008');

    it('PAYMENT-009 invalid payment method rejected', function () {
        confirmPurchaseForPayment($this);

        $this->postJson(
            '/api/supplier-payments',
            validPaymentPayload($this, ['mode' => 'card']),
            paymentHeaders($this),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['mode']);

        $this->postJson(
            '/api/supplier-payments',
            validPaymentPayload($this, ['mode' => 'credit']),
            paymentHeaders($this),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['mode']);
    })->group('payment', 'PAYMENT-009');

    it('PAYMENT-010 edit payment if supported', function () {
        $this->markTestSkipped(
            'Not supported: no PUT /api/supplier-payments/{id} — payments are record-only; void and re-record instead.',
        );
    })->group('payment', 'PAYMENT-010');

    it('PAYMENT-011 delete payment voids and restores outstanding', function () {
        confirmPurchaseForPayment($this, $this->supplier, 10, 100);
        $payment = recordPaymentViaApi($this, ['amount' => 400]);
        expect((float) $this->supplier->fresh()->outstanding)->toBe(600.0);

        $this->deleteJson("/api/supplier-payments/{$payment->id}", [], paymentHeaders($this))
            ->assertOk()
            ->assertJsonPath('message', 'Payment voided.');

        expect(SupplierPayment::find($payment->id))->toBeNull()
            ->and((float) $this->supplier->fresh()->outstanding)->toBe(1000.0);
    })->group('payment', 'PAYMENT-011');

    it('PAYMENT-012 API validation error returns 422 with field errors', function () {
        $response = $this->postJson(
            '/api/supplier-payments',
            validPaymentPayload($this, [
                'supplier_id' => fake()->uuid(),
                'payment_date' => 'not-a-date',
                'amount' => 'abc',
                'mode' => 'wire',
            ]),
            paymentHeaders($this),
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier_id', 'payment_date', 'amount', 'mode'])
            ->assertJsonStructure(['message', 'errors']);
    })->group('payment', 'PAYMENT-012');

    it('PAYMENT-013 API server error handling', function () {
        $this->app->bind(SupplierPaymentController::class, fn () => new class extends SupplierPaymentController
        {
            public function __construct()
            {
                parent::__construct(app(\App\Services\PaymentService::class));
            }

            public function store(\App\Http\Requests\Payment\StoreSupplierPaymentRequest $request): JsonResponse
            {
                return response()->json(['message' => 'Server Error'], 500);
            }
        });

        confirmPurchaseForPayment($this);

        $this->postJson(
            '/api/supplier-payments',
            validPaymentPayload($this),
            paymentHeaders($this),
        )->assertStatus(500)
            ->assertJson(['message' => 'Server Error']);
    })->group('payment', 'PAYMENT-013');

})->group('payment');
