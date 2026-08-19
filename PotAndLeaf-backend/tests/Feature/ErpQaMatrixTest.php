<?php

/**
 * Cross-module QA matrix — end-to-end API flows for POS audit sections 1–12 (Phases A–D).
 * Run alone: php artisan test --group=qa
 */

use App\Models\CompanySetting;
use App\Models\CustomerReceipt;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\Supplier;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithQaUser();
});

function qaSetting(object $test, string $key, string $value): void
{
    CompanySetting::query()->updateOrCreate(
        ['company_id' => $test->company->id, 'key' => $key],
        ['value' => $value],
    );
}

describe('QA matrix — POS & accounting integrity', function () {
    it('confirms cash sale via API, posts receipt, and reflects in cash book', function () {
        qaSetting($this, 'cash_opening_balance', '0');
        $customer = $this->createCustomer();
        $product = $this->createProduct(['current_stock' => 20, 'retail_price' => 100, 'gst_rate' => 0]);
        $stockBefore = (float) $product->current_stock;

        $create = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'payment_mode' => 'cash',
            'is_interstate' => false,
            'items' => [
                ['product_id' => $product->id, 'qty' => 3, 'rate' => 100, 'gst_rate' => 0],
            ],
        ], $this->apiHeaders())->assertCreated();

        $saleId = $create->json('data.id');

        $this->postJson("/api/sales/{$saleId}/confirm", [], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        expect(CustomerReceipt::forCompany($this->company->id)->where('sale_id', $saleId)->count())->toBe(1);
        expect((float) $product->fresh()->current_stock)->toBe($stockBefore - 3);

        $book = app(ReportService::class)->cashBook(
            $this->company->id,
            now()->toDateString(),
            now()->toDateString(),
        );
        expect($book['total_in'])->toBe(300.0);
    })->group('qa');
});

describe('QA matrix — advance orders', function () {
    it('books advance via API and fulfill creates linked draft sale', function () {
        $customer = $this->createCustomer();
        $product = $this->createProduct(['retail_price' => 200, 'gst_rate' => 0, 'current_stock' => 10]);

        $book = $this->postJson('/api/advance-orders', [
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'advance_amount' => 150,
            'advance_mode' => 'cash',
            'items' => [
                ['product_id' => $product->id, 'qty' => 2, 'rate' => 200, 'gst_rate' => 0],
            ],
        ], $this->apiHeaders())->assertCreated();

        $orderId = $book->json('data.id');

        $fulfill = $this->postJson("/api/advance-orders/{$orderId}/fulfill", [], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.sale_id', fn ($id) => filled($id));

        $saleId = $fulfill->json('data.sale_id');
        expect(\App\Models\AdvanceOrder::find($orderId)->status)->toBe('fulfilled');
        expect(Sale::find($saleId))->not->toBeNull();
        expect((float) Sale::find($saleId)->amount_paid)->toBe(150.0);
        expect((float) $customer->fresh()->advance_balance)->toBe(150.0);
    })->group('qa');
});

describe('QA matrix — backorders', function () {
    it('fulfills backorder into draft sale and confirm reduces stock', function () {
        $customer = $this->createCustomer();
        $product = $this->createProduct(['current_stock' => 12, 'retail_price' => 80, 'gst_rate' => 0]);
        $stockBefore = (float) $product->current_stock;

        $order = $this->postJson('/api/backorders', [
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'ordered_qty' => 20, 'rate' => 80],
            ],
        ], $this->apiHeaders())->assertCreated();

        $lineId = $order->json('data.items.0.id');

        $fulfill = $this->postJson("/api/backorders/{$order->json('data.id')}/fulfill", [
            'items' => [['id' => $lineId, 'qty' => 5]],
        ], $this->apiHeaders())->assertOk();

        $saleId = $fulfill->json('data.sale_id');

        $this->postJson("/api/sales/{$saleId}/confirm", [], $this->apiHeaders())->assertOk();
        expect((float) $product->fresh()->current_stock)->toBe($stockBefore - 5);
    })->group('qa');
});

describe('QA matrix — supplier PO batch', function () {
    it('runs reorder report through PO send and GRN conversion', function () {
        $supplier = $this->createSupplier(['name' => 'Matrix Grower']);
        $product = $this->createProduct([
            'current_stock' => 4,
            'reorder_level' => 20,
            'cost_price' => 50,
            'gst_rate' => 5,
        ]);
        $product->suppliers()->attach($supplier->id, ['supplier_price' => 50, 'is_primary' => true]);

        $this->getJson('/api/purchase-orders/reorder-report', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.summary.product_count', 1);

        $batch = $this->postJson('/api/purchase-orders/batch-from-reorder', [
            'po_date' => now()->toDateString(),
            'orders' => [[
                'supplier_id' => $supplier->id,
                'items' => [
                    ['product_id' => $product->id, 'qty' => 15, 'rate' => 50, 'gst_rate' => 5],
                ],
            ]],
        ], $this->apiHeaders())->assertCreated();

        $poId = $batch->json('data.0.id');
        expect(PurchaseOrder::find($poId)->status)->toBe('draft');

        $this->postJson("/api/purchase-orders/{$poId}/send", [], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $convert = $this->postJson("/api/purchase-orders/{$poId}/convert", [], $this->apiHeaders())
            ->assertOk();

        $purchase = Purchase::find($convert->json('data.purchase_id'));
        expect($purchase)->not->toBeNull();
        expect($purchase->status)->toBe('draft');
        expect(PurchaseOrder::find($poId)->status)->toBe('received');
    })->group('qa');
});

describe('QA matrix — accounting reports', function () {
    it('returns all accounting report endpoints with expected structure', function () {
        $customer = $this->createCustomer();
        $supplier = $this->createSupplier();
        $from = now()->toDateString();
        $to = now()->toDateString();

        $this->getJson("/api/reports/accounting/cash-book?from={$from}&to={$to}", $this->apiHeaders())
            ->assertOk()
            ->assertJsonStructure(['data' => ['opening_balance', 'rows', 'closing_balance']]);

        $this->getJson("/api/reports/accounting/bank-book?from={$from}&to={$to}", $this->apiHeaders())
            ->assertOk()
            ->assertJsonStructure(['data' => ['opening_balance', 'rows', 'closing_balance']]);

        $this->getJson('/api/reports/accounting/debtor-ledger?'.http_build_query([
            'customer_id' => $customer->id, 'from' => $from, 'to' => $to,
        ]), $this->apiHeaders())
            ->assertOk()
            ->assertJsonStructure(['data' => ['rows', 'closing_balance']]);

        $this->getJson('/api/reports/accounting/creditor-ledger?'.http_build_query([
            'supplier_id' => $supplier->id, 'from' => $from, 'to' => $to,
        ]), $this->apiHeaders())
            ->assertOk()
            ->assertJsonStructure(['data' => ['rows', 'closing_balance']]);

        $this->getJson('/api/reports/accounting/ageing-receivables', $this->apiHeaders())
            ->assertOk()
            ->assertJsonStructure(['data' => ['buckets', 'lines', 'total']]);

        $this->getJson('/api/reports/accounting/ageing-payables', $this->apiHeaders())
            ->assertOk()
            ->assertJsonStructure(['data' => ['buckets', 'lines', 'total']]);
    })->group('qa');

    it('tracks credit sale through debtor ledger after receipt allocation', function () {
        $customer = $this->createCustomer(['outstanding' => 0]);
        $product = $this->createProduct(['current_stock' => 5, 'gst_rate' => 0]);

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'payment_mode' => 'credit',
            'amount_paid' => 0,
            'is_interstate' => false,
            'items' => [
                ['product_id' => $product->id, 'qty' => 1, 'rate' => 500, 'gst_rate' => 0],
            ],
        ], $this->apiHeaders())->assertCreated();

        $saleId = $sale->json('data.id');
        $this->postJson("/api/sales/{$saleId}/confirm", [], $this->apiHeaders())->assertOk();

        $this->postJson('/api/customer-receipts', [
            'customer_id' => $customer->id,
            'sale_id' => $saleId,
            'receipt_date' => now()->toDateString(),
            'amount' => 200,
            'mode' => 'bank',
        ], $this->apiHeaders())->assertCreated();

        $ledger = $this->getJson('/api/reports/accounting/debtor-ledger?'.http_build_query([
            'customer_id' => $customer->id,
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
        ]), $this->apiHeaders())->assertOk();

        expect((float) $ledger->json('data.closing_balance'))->toBe(300.0);
        expect((float) $customer->fresh()->outstanding)->toBe(300.0);
    })->group('qa');
});

describe('QA matrix — permissions', function () {
    it('denies reorder report without po.view permission', function () {
        $this->createCompanyWithUser(['sales.view']);

        $this->getJson('/api/purchase-orders/reorder-report', $this->apiHeaders())
            ->assertForbidden();
    })->group('qa');

    it('denies batch PO creation without po.create permission', function () {
        $this->createCompanyWithUser(['po.view']);
        $supplier = Supplier::create([
            'company_id' => $this->company->id,
            'supplier_code' => 'SUP-X',
            'name' => 'Blocked',
            'status' => 'active',
            'outstanding' => 0,
        ]);
        $product = $this->createProduct(['current_stock' => 1, 'reorder_level' => 10]);

        $this->postJson('/api/purchase-orders/batch-from-reorder', [
            'po_date' => now()->toDateString(),
            'orders' => [[
                'supplier_id' => $supplier->id,
                'items' => [['product_id' => $product->id, 'qty' => 5, 'rate' => 10]],
            ]],
        ], $this->apiHeaders())->assertForbidden();
    })->group('qa');
});

describe('QA matrix — cross-branch stock', function () {
    it('exposes ATP fields on cross-branch stock API', function () {
        $this->seedPermissions();
        $branch = \App\Models\Company::create(['name' => 'Branch QA', 'code' => 'BQA', 'is_active' => true]);
        $this->user->companies()->attach($branch->id);

        $this->createProduct(['sku' => 'QA-SKU', 'current_stock' => 6, 'reorder_level' => 10]);
        Product::create([
            'company_id' => $branch->id,
            'sku' => 'QA-SKU',
            'name' => 'QA Product',
            'gst_rate' => 0,
            'mrp' => 100,
            'cost_price' => 40,
            'retail_price' => 80,
            'current_stock' => 30,
            'opening_stock' => 30,
            'status' => 'active',
        ]);

        $res = $this->getJson('/api/inventory/stock/cross-branch?sku=QA-SKU', $this->apiHeaders())
            ->assertOk();

        $branches = collect($res->json('data.branches'));
        expect($branches->every(fn ($b) => array_key_exists('available_to_promise', $b)))->toBeTrue();
        expect((float) $branches->sum('current_stock'))->toBe(36.0);
    })->group('qa');
});
