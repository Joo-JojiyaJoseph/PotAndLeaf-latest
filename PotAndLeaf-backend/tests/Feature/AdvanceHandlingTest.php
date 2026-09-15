<?php

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Models\CustomerReceipt;
use App\Models\Purchase;
use App\Models\SupplierPayment;
use App\Services\PaymentService;
use App\Services\ReceiptService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'reports.view', 'receipts.view', 'receipts.create', 'payments.view', 'payments.create',
        'sales.create', 'sales.confirm', 'advance.create', 'purchases.create', 'purchases.confirm',
        'po.view',
    ]);
    $this->customer = $this->createCustomer(['outstanding' => 0, 'advance_balance' => 0]);
    $this->supplier = $this->createSupplier(['outstanding' => 0, 'advance_balance' => 0]);
    $this->product = $this->createProduct([
        'current_stock' => 50, 'gst_rate' => 0, 'cost_price' => 100, 'retail_price' => 250,
    ]);
});

function confirmCreditSale(object $test, float $rate = 250): \App\Models\Sale
{
    $sale = app(CreateSale::class)->handle($test->company->id, [
        'customer_id' => $test->customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'credit',
        'amount_paid' => 0,
        'is_interstate' => false,
        'items' => [['product_id' => $test->product->id, 'qty' => 1, 'rate' => $rate, 'gst_rate' => 0]],
    ], $test->user->id);

    return app(ConfirmSale::class)->handle($sale, $test->user->id);
}

function confirmPurchaseInvoice(object $test, float $qty = 5, float $rate = 100): Purchase
{
    $create = $test->postJson('/api/purchases', [
        'supplier_id' => $test->supplier->id,
        'purchase_date' => now()->toDateString(),
        'is_interstate' => false,
        'items' => [['product_id' => $test->product->id, 'qty' => $qty, 'rate' => $rate, 'discount' => 0, 'gst_rate' => 0]],
    ], $test->apiHeaders())->assertCreated();

    $id = $create->json('data.id');
    $test->postJson("/api/purchases/{$id}/confirm", [], $test->apiHeaders())->assertOk();

    return Purchase::findOrFail($id);
}

it('records a customer advance without reducing outstanding', function () {
    $this->postJson('/api/customer-receipts', [
        'customer_id' => $this->customer->id,
        'receipt_date' => now()->toDateString(),
        'amount' => 10000,
        'mode' => 'cash',
        'is_advance' => true,
    ], $this->apiHeaders())->assertCreated();

    $this->customer->refresh();
    expect((float) $this->customer->advance_balance)->toBe(10000.0)
        ->and((float) $this->customer->outstanding)->toBe(0.0);
});

it('applies customer advance to a later invoice', function () {
    app(ReceiptService::class)->record($this->company->id, [
        'customer_id' => $this->customer->id,
        'receipt_date' => now()->toDateString(),
        'amount' => 10000,
        'mode' => 'cash',
        'is_advance' => true,
    ]);

    $sale = confirmCreditSale($this, 25000);
    $this->customer->refresh();
    expect((float) $this->customer->outstanding)->toBe(25000.0);

    $this->postJson('/api/customer-receipts/apply-advance', [
        'customer_id' => $this->customer->id,
        'sale_id' => $sale->id,
        'amount' => 10000,
    ], $this->apiHeaders())->assertCreated();

    $this->customer->refresh();
    expect((float) $this->customer->advance_balance)->toBe(0.0)
        ->and((float) $this->customer->outstanding)->toBe(15000.0)
        ->and((float) $sale->fresh()->amount_paid)->toBe(10000.0);

    $book = app(ReportService::class)->cashBook(
        $this->company->id, now()->toDateString(), now()->toDateString(), 1, 50,
    );
    expect($book['total_in'])->toBe(10000.0)
        ->and($book['total_out'])->toBe(0.0);
});

it('records a supplier advance without reducing outstanding', function () {
    $this->postJson('/api/supplier-payments', [
        'supplier_id' => $this->supplier->id,
        'payment_date' => now()->toDateString(),
        'amount' => 20000,
        'mode' => 'cash',
        'is_advance' => true,
    ], $this->apiHeaders())->assertCreated();

    $this->supplier->refresh();
    expect((float) $this->supplier->advance_balance)->toBe(20000.0)
        ->and((float) $this->supplier->outstanding)->toBe(0.0);
});

it('applies supplier advance to a later purchase invoice', function () {
    app(PaymentService::class)->record($this->company->id, [
        'supplier_id' => $this->supplier->id,
        'payment_date' => now()->toDateString(),
        'amount' => 20000,
        'mode' => 'cash',
        'is_advance' => true,
    ]);

    $purchase = confirmPurchaseInvoice($this, 5, 100);
    $this->supplier->refresh();
    expect((float) $this->supplier->outstanding)->toBe(500.0);

    $this->postJson('/api/supplier-payments/apply-advance', [
        'supplier_id' => $this->supplier->id,
        'purchase_id' => $purchase->id,
        'amount' => 200,
    ], $this->apiHeaders())->assertCreated();

    $this->supplier->refresh();
    expect((float) $this->supplier->advance_balance)->toBe(19800.0)
        ->and((float) $this->supplier->outstanding)->toBe(300.0)
        ->and((float) $purchase->fresh()->amount_paid)->toBe(200.0);

    expect(SupplierPayment::where('applied_from_advance', true)->count())->toBe(1);

    $book = app(ReportService::class)->cashBook(
        $this->company->id, now()->toDateString(), now()->toDateString(), 1, 50,
    );
    expect($book['total_out'])->toBe(20000.0);
});

it('allocates one customer receipt across invoices with unallocated remainder as advance', function () {
    $sale1 = confirmCreditSale($this, 10000);
    $sale2 = confirmCreditSale($this, 15000);
    $this->customer->refresh();
    expect((float) $this->customer->outstanding)->toBe(25000.0);

    $this->postJson('/api/customer-receipts', [
        'customer_id' => $this->customer->id,
        'receipt_date' => now()->toDateString(),
        'amount' => 30000,
        'mode' => 'bank',
        'allocations' => [
            ['sale_id' => $sale1->id, 'amount' => 10000],
            ['sale_id' => $sale2->id, 'amount' => 15000],
        ],
    ], $this->apiHeaders())->assertCreated();

    $this->customer->refresh();
    expect((float) $this->customer->outstanding)->toBe(0.0)
        ->and((float) $this->customer->advance_balance)->toBe(5000.0)
        ->and((float) $sale1->fresh()->amount_paid)->toBe(10000.0)
        ->and((float) $sale2->fresh()->amount_paid)->toBe(15000.0);

    expect(CustomerReceipt::count())->toBe(1);
});

it('loads the dashboard when a super-admin filters all companies', function () {
    $admin = \App\Models\User::factory()->create(['is_super_admin' => true, 'is_active' => true]);

    $this->getJson('/api/reports/dashboard?'.http_build_query([
        'from' => now()->subDays(29)->toDateString(),
        'to' => now()->toDateString(),
        'company_id' => 'all',
    ]), [
        'Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken,
        'X-Company-Id' => (string) $this->company->id,
        'Accept' => 'application/json',
    ])->assertOk()
        ->assertJsonStructure(['data' => ['sales' => ['total', 'count'], 'purchases', 'inventory']]);
});

it('loads cash and bank books when a super-admin filters all companies', function () {
    $admin = \App\Models\User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
    $headers = [
        'Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken,
        'X-Company-Id' => (string) $this->company->id,
        'Accept' => 'application/json',
    ];
    $query = http_build_query([
        'from' => now()->subDays(29)->toDateString(),
        'to' => now()->toDateString(),
        'company_id' => 'all',
    ]);

    $this->getJson('/api/reports/accounting/cash-book?'.$query, $headers)
        ->assertOk()
        ->assertJsonStructure(['data' => ['opening_balance', 'total_in', 'total_out', 'closing_balance', 'rows']]);

    $this->getJson('/api/reports/accounting/bank-book?'.$query, $headers)
        ->assertOk()
        ->assertJsonStructure(['data' => ['opening_balance', 'total_in', 'total_out', 'closing_balance', 'rows']]);
});

it('records a receipt against another company when the header company differs', function () {
    $other = \App\Models\Company::create([
        'name' => 'Other Branch',
        'code' => 'OTH'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(3)),
        'is_active' => true,
    ]);
    $customer = \App\Models\Customer::create([
        'company_id' => $other->id,
        'customer_code' => 'C-XCOMP',
        'name' => 'Walk-in Other',
        'type' => 'retail',
        'status' => 'active',
        'outstanding' => 916,
    ]);
    $admin = \App\Models\User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
    $headers = [
        'Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken,
        'X-Company-Id' => (string) $this->company->id,
        'Accept' => 'application/json',
    ];

    $this->getJson('/api/customer-receipts/form-data?company_id=all', $headers)
        ->assertOk()
        ->assertJsonFragment(['id' => $customer->id]);

    $this->postJson('/api/customer-receipts', [
        'customer_id' => $customer->id,
        'receipt_date' => now()->toDateString(),
        'amount' => 100,
        'mode' => 'cash',
    ], $headers)->assertCreated();

    expect(CustomerReceipt::query()->where('company_id', $other->id)->count())->toBe(1);
});

it('exports cash book and debtor ledger pdfs', function () {
    confirmCreditSale($this, 400);

    $cash = $this->getJson('/api/reports/accounting/cash-book/export?from='.now()->toDateString().'&to='.now()->toDateString(), $this->apiHeaders());
    $cash->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($cash->getContent(), 0, 4))->toBe('%PDF');

    $ledger = $this->getJson('/api/reports/accounting/debtor-ledger/export?'.http_build_query([
        'customer_id' => $this->customer->id,
        'from' => now()->toDateString(),
        'to' => now()->toDateString(),
    ]), $this->apiHeaders());
    $ledger->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($ledger->getContent(), 0, 4))->toBe('%PDF');
});

it('exports cash and bank book pdfs when a super-admin filters all companies', function () {
    $admin = \App\Models\User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
    $headers = [
        'Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken,
        'X-Company-Id' => (string) $this->company->id,
        'Accept' => 'application/json',
    ];
    $query = http_build_query([
        'from' => now()->subDays(29)->toDateString(),
        'to' => now()->toDateString(),
        'company_id' => 'all',
    ]);

    $cash = $this->get('/api/reports/accounting/cash-book/export?'.$query, $headers);
    $cash->assertOk();
    expect($cash->headers->get('content-type'))->toContain('application/pdf')
        ->and(substr($cash->getContent(), 0, 4))->toBe('%PDF');

    $bank = $this->get('/api/reports/accounting/bank-book/export?'.$query, $headers);
    $bank->assertOk();
    expect($bank->headers->get('content-type'))->toContain('application/pdf')
        ->and(substr($bank->getContent(), 0, 4))->toBe('%PDF');
});
