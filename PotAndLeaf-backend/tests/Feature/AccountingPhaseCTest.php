<?php

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\AdvanceOrderService;
use App\Services\ReceiptService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'reports.view', 'receipts.view', 'receipts.create', 'payments.view', 'payments.create',
        'sales.create', 'sales.confirm', 'advance.create', 'advance.delete', 'purchases.create', 'purchases.confirm',
    ]);
});

function phaseCSetting(object $test, string $key, string $value): void
{
    CompanySetting::query()->updateOrCreate(
        ['company_id' => $test->company->id, 'key' => $key],
        ['value' => $value],
    );
}

it('seeds customer outstanding from opening balance on create', function () {
    $customer = app(\App\Actions\Customers\CreateCustomer::class)->handle($this->company->id, [
        'name' => 'Opening Co',
        'opening_balance' => 500,
    ]);

    expect((float) $customer->outstanding)->toBe(500.0);
});

it('records advance on booking and increases advance_balance not outstanding', function () {
    $customer = $this->createCustomer(['outstanding' => 0, 'advance_balance' => 0]);
    $product = $this->createProduct(['retail_price' => 100, 'gst_rate' => 0]);

    app(AdvanceOrderService::class)->create($this->company->id, [
        'customer_id' => $customer->id,
        'order_date' => now()->toDateString(),
        'advance_amount' => 200,
        'advance_mode' => 'cash',
        'items' => [
            ['product_id' => $product->id, 'qty' => 1, 'rate' => 100, 'gst_rate' => 0],
        ],
    ], $this->user->id);

    $customer->refresh();
    expect((float) $customer->advance_balance)->toBe(200.0);
    expect((float) $customer->outstanding)->toBe(0.0);
    expect(CustomerReceipt::forCompany($this->company->id)->whereNotNull('advance_order_id')->count())->toBe(1);
});

it('voids advance receipt when advance order is cancelled', function () {
    $customer = $this->createCustomer();
    $product = $this->createProduct(['retail_price' => 50, 'gst_rate' => 0]);
    $order = app(AdvanceOrderService::class)->create($this->company->id, [
        'customer_id' => $customer->id,
        'order_date' => now()->toDateString(),
        'advance_amount' => 100,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 50, 'gst_rate' => 0]],
    ], $this->user->id);

    app(AdvanceOrderService::class)->cancel($order);
    expect((float) $customer->fresh()->advance_balance)->toBe(0.0);
});

it('syncs sale amount_paid when receipt is allocated to invoice', function () {
    $customer = $this->createCustomer();
    $product = $this->createProduct(['current_stock' => 10, 'gst_rate' => 0]);

    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'credit',
        'amount_paid' => 0,
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 300, 'gst_rate' => 0]],
    ], $this->user->id);

    app(ConfirmSale::class)->handle($sale, $this->user->id);

    app(ReceiptService::class)->record($this->company->id, [
        'customer_id' => $customer->id,
        'sale_id' => $sale->id,
        'receipt_date' => now()->toDateString(),
        'amount' => 150,
        'mode' => 'cash',
    ]);

    expect((float) $sale->fresh()->amount_paid)->toBe(150.0);
});

it('builds cash book with opening balance and movements', function () {
    phaseCSetting($this, 'cash_opening_balance', '1000');
    $customer = $this->createCustomer();
    $supplier = Supplier::create([
        'company_id' => $this->company->id,
        'supplier_code' => 'SUP-001',
        'name' => 'Grower',
        'status' => 'active',
        'outstanding' => 0,
        'opening_balance' => 0,
    ]);

    app(ReceiptService::class)->record($this->company->id, [
        'customer_id' => $customer->id,
        'receipt_date' => now()->toDateString(),
        'amount' => 500,
        'mode' => 'cash',
    ]);

    app(\App\Services\PaymentService::class)->record($this->company->id, [
        'supplier_id' => $supplier->id,
        'payment_date' => now()->toDateString(),
        'amount' => 200,
        'mode' => 'cash',
    ]);

    $book = app(ReportService::class)->cashBook(
        $this->company->id,
        now()->toDateString(),
        now()->toDateString(),
    );

    expect($book['opening_balance'])->toBe(1000.0);
    expect($book['total_in'])->toBe(500.0);
    expect($book['total_out'])->toBe(200.0);
    expect($book['closing_balance'])->toBe(1300.0);
});

it('exposes debtor ledger via API', function () {
    $customer = $this->createCustomer(['opening_balance' => 0]);
    $product = $this->createProduct(['current_stock' => 5, 'gst_rate' => 0]);

    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'credit',
        'amount_paid' => 0,
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 400, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    $this->getJson('/api/reports/accounting/debtor-ledger?'.http_build_query([
        'customer_id' => $customer->id,
        'from' => now()->toDateString(),
        'to' => now()->toDateString(),
    ]), $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.closing_balance', 400);
});

it('returns ageing receivables buckets', function () {
    $customer = $this->createCustomer();
    $product = $this->createProduct(['current_stock' => 5, 'gst_rate' => 0]);
    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->subDays(45)->toDateString(),
        'payment_mode' => 'credit',
        'amount_paid' => 0,
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 250, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    $this->getJson('/api/reports/accounting/ageing-receivables', $this->apiHeaders())
        ->assertOk()
        ->assertJsonStructure(['data' => ['buckets', 'lines', 'total']]);
});
