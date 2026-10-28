<?php

/**
 * Regression tests for the calculation fixes of 2026-10-23:
 * commission reversals, promotion base, advance orders, rental settlement,
 * landed cost payable, credit limit, pooled cancel/return, return points,
 * profit and GST reports.
 *
 * Run: php artisan test tests/Feature/CalculationFixesTest.php
 */

use App\Actions\Purchases\ConfirmPurchase;
use App\Actions\Purchases\CreatePurchase;
use App\Actions\Sales\CancelSale;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Actions\SalesReturns\ConfirmSalesReturn;
use App\Actions\SalesReturns\CreateSalesReturn;
use App\Models\AdvanceOrder;
use App\Models\CommissionDailyTargetTier;
use App\Models\CommissionPromotion;
use App\Models\CommissionRule;
use App\Models\CommissionTransaction;
use App\Models\CompanySetting;
use App\Models\CustomerReceipt;
use App\Models\LoyaltyLedgerEntry;
use App\Models\Rental;
use App\Models\RentalInvoice;
use App\Services\AdvanceOrderService;
use App\Services\CommissionService;
use App\Services\RentalService;
use App\Services\ReportService;
use App\Services\SalesAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'sales.view', 'sales.create', 'sales.confirm', 'sales.delete',
        'sales_returns.create', 'sales_returns.confirm', 'commission.view',
        'rental.view', 'rental.bill', 'purchases.create', 'purchases.confirm',
    ]);
});

function fixSetting(object $test, string $key, string $value): void
{
    CompanySetting::query()->updateOrCreate(
        ['company_id' => $test->company->id, 'key' => $key],
        ['value' => $value],
    );
}

function fixSale(object $test, array $items, array $extra = [])
{
    $sale = app(CreateSale::class)->handle($test->company->id, array_merge([
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => $items,
    ], $extra), $test->user->id);

    return app(ConfirmSale::class)->handle($sale, $test->user->id);
}

function fixFlatRule(object $test, float $percent = 1.0): CommissionRule
{
    return CommissionRule::create([
        'company_id' => $test->company->id,
        'user_id' => $test->user->id,
        'base_percent' => $percent,
        'is_active' => true,
        'is_supervisor' => false,
    ]);
}

it('nets a cancelled sale\'s commission to zero instead of deducting it twice', function () {
    fixFlatRule($this, 1);
    $product = $this->createProduct(['current_stock' => 10, 'gst_rate' => 0]);

    $sale = fixSale($this, [['product_id' => $product->id, 'qty' => 2, 'rate' => 1000, 'gst_rate' => 0]]);
    app(CancelSale::class)->handle($sale->fresh(), $this->user->id);

    $month = app(CommissionService::class)->compute($this->company->id, $this->user->id, now()->format('Y-m'));
    $range = app(CommissionService::class)->rangeSummary($this->company->id, $this->user->id, now()->startOfMonth()->toDateString(), now()->toDateString());

    expect($month['commission'])->toBe(0.0)
        ->and($range['total'])->toBe(0.0)
        ->and($range['ledger_reversals'])->toBe(-20.0);
});

it('reverses only the remainder when a partly returned sale is cancelled', function () {
    fixFlatRule($this, 10);
    $customer = $this->createCustomer();
    $product = $this->createProduct(['current_stock' => 10, 'gst_rate' => 0]);

    $sale = fixSale($this, [['product_id' => $product->id, 'qty' => 4, 'rate' => 100, 'gst_rate' => 0]], ['customer_id' => $customer->id]);
    $return = app(CreateSalesReturn::class)->handle($this->company->id, [
        'sale_id' => $sale->id,
        'return_date' => now()->toDateString(),
        'items' => [['sale_item_id' => $sale->items->first()->id, 'qty' => 1]],
    ], $this->user->id);
    app(ConfirmSalesReturn::class)->handle($return, $this->user->id);
    app(CancelSale::class)->handle($sale->fresh(), $this->user->id);

    $all = CommissionTransaction::forCompany($this->company->id)->counted()->sum('amount');
    expect(round((float) $all, 2))->toBe(0.0)
        ->and(round((float) CommissionTransaction::where('commission_type', 'reversal')->sum('amount'), 2))->toBe(-40.0);
});

it('lets a daily target bonus be earned again after a same-day return dropped it', function () {
    $rule = fixFlatRule($this, 0);
    CommissionDailyTargetTier::create(['commission_rule_id' => $rule->id, 'min_amount' => 1000, 'bonus_amount' => 100]);
    $customer = $this->createCustomer();
    $product = $this->createProduct(['current_stock' => 50, 'gst_rate' => 0]);

    $sale = fixSale($this, [['product_id' => $product->id, 'qty' => 12, 'rate' => 100, 'gst_rate' => 0]], ['customer_id' => $customer->id]);
    $return = app(CreateSalesReturn::class)->handle($this->company->id, [
        'sale_id' => $sale->id,
        'return_date' => now()->toDateString(),
        'items' => [['sale_item_id' => $sale->items->first()->id, 'qty' => 3]],
    ], $this->user->id);
    app(ConfirmSalesReturn::class)->handle($return, $this->user->id); // day 900 → bonus reversed
    fixSale($this, [['product_id' => $product->id, 'qty' => 5, 'rate' => 100, 'gst_rate' => 0]]); // day 1,400

    $daily = CommissionTransaction::forCompany($this->company->id)->counted()
        ->whereIn('commission_type', ['daily_target'])->sum('amount')
        + CommissionTransaction::forCompany($this->company->id)->counted()
            ->where('commission_type', 'reversal')->where('source_type', 'daily-target')->sum('amount');

    expect(round((float) $daily, 2))->toBe(100.0)
        ->and(CommissionTransaction::where('commission_type', 'daily_target')->where('status', 'accrued')->count())->toBe(1);
});

it('pays promotion % on the line value after discount, not after discount twice', function () {
    $product = $this->createProduct(['current_stock' => 10, 'gst_rate' => 18]);
    CommissionPromotion::create([
        'company_id' => $this->company->id, 'name' => 'Pots push', 'product_id' => $product->id,
        'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
        'min_qty' => 1, 'bonus_per_unit' => 0, 'bonus_fixed' => 0, 'bonus_percent' => 10, 'is_active' => true,
    ]);

    fixSale($this, [['product_id' => $product->id, 'qty' => 4, 'rate' => 250, 'discount' => 100, 'gst_rate' => 18]]);

    // taxable = 4 × 250 − 100 = 900 → 10% = 90 (was 80 before the fix)
    expect((float) CommissionTransaction::where('commission_type', 'promotion')->value('amount'))->toBe(90.0);
});

it('uses up a booking advance when its sale is confirmed and gives it back on cancel', function () {
    $customer = $this->createCustomer(['outstanding' => 0, 'advance_balance' => 0]);
    $product = $this->createProduct(['current_stock' => 10, 'retail_price' => 1000, 'gst_rate' => 0]);

    $order = app(AdvanceOrderService::class)->create($this->company->id, [
        'customer_id' => $customer->id,
        'order_date' => now()->toDateString(),
        'advance_amount' => 400,
        'advance_mode' => 'cash',
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 1000, 'gst_rate' => 0]],
    ], $this->user->id);
    $result = app(AdvanceOrderService::class)->fulfill($order, $this->user->id);
    $sale = app(ConfirmSale::class)->handle(\App\Models\Sale::find($result['sale_id']), $this->user->id);

    $customer->refresh();
    expect((float) $customer->advance_balance)->toBe(0.0)
        ->and((float) $customer->outstanding)->toBe(600.0)
        ->and(CustomerReceipt::where('sale_id', $sale->id)->count())->toBe(1)       // no second cash receipt
        ->and((float) CustomerReceipt::where('sale_id', $sale->id)->sum('amount'))->toBe(400.0);

    app(CancelSale::class)->handle($sale->fresh(), $this->user->id);
    $customer->refresh();
    expect((float) $customer->advance_balance)->toBe(400.0)
        ->and((float) $customer->outstanding)->toBe(0.0)
        ->and(AdvanceOrder::find($order->id)->status)->toBe('booked');
});

it('does not charge rent again at settlement for periods already invoiced', function () {
    $customer = $this->createCustomer();
    $product = $this->createProduct(['retail_price' => 250]);
    $rental = Rental::create([
        'company_id' => $this->company->id, 'customer_id' => $customer->id,
        'rental_no' => 'RNT-900001', 'start_date' => now()->subDays(44)->toDateString(),
        'billing_cycle' => 'monthly', 'auto_bill' => false, 'deposit' => 2500,
        'status' => 'active', 'activated_at' => now()->subDays(44),
    ]);
    $rental->items()->create([
        'product_id' => $product->id, 'product_name' => $product->name, 'qty' => 10,
        'rate_per_cycle' => 150, 'returned_qty' => 0, 'damaged_qty' => 0, 'missing_qty' => 0,
    ]);
    $svc = app(RentalService::class);
    $svc->generateInvoice($this->company->id, $rental->fresh(['items']), [
        'period_from' => now()->subDays(44)->toDateString(),
        'period_to' => now()->subDays(15)->toDateString(),
    ], $this->user->id);

    $item = $rental->items()->first();
    $settled = $svc->settle($rental->fresh(['items']), [
        $item->id => ['returned' => 8, 'damaged' => 1],
    ], now()->toDateString(), null, $this->user->id);

    // 45 days = 2 months: rent 3,000, of which 1,500 already invoiced.
    // Still due: 1,500 + damage 125 + missing 250 = 1,875 → deposit 2,500 covers it.
    expect((float) $settled->rental_charge)->toBe(3000.0)
        ->and((float) $settled->refund_amount)->toBe(625.0)
        ->and((float) $settled->balance_due)->toBe(0.0)
        ->and((float) RentalInvoice::where('rental_id', $rental->id)->latest('created_at')->orderByDesc('invoice_no')->value('amount'))->toBe(1875.0);
});

it('keeps freight out of the supplier balance when it is paid to a transporter', function () {
    $supplier = $this->createSupplier(['outstanding' => 0]);
    $pot = $this->createProduct(['current_stock' => 0, 'gst_rate' => 18]);
    $mix = $this->createProduct(['current_stock' => 0, 'gst_rate' => 5]);
    $make = fn () => app(CreatePurchase::class)->handle($this->company->id, [
        'supplier_id' => $supplier->id,
        'purchase_date' => now()->toDateString(),
        'is_interstate' => false,
        'landed_cost_total' => 1200,
        'items' => [
            ['product_id' => $pot->id, 'qty' => 50, 'rate' => 120, 'discount' => 300, 'gst_rate' => 18],
            ['product_id' => $mix->id, 'qty' => 20, 'rate' => 450, 'discount' => 0, 'gst_rate' => 5],
        ],
    ], $this->user->id);

    // Default (1): freight billed by the supplier → owed 17,376.
    app(ConfirmPurchase::class)->handle($make(), $this->user->id);
    expect((float) $supplier->fresh()->outstanding)->toBe(17376.0);

    // 0: freight paid to someone else → only 16,176 owed; stock cost still includes it.
    fixSetting($this, 'landed_cost_payable_to_supplier', '0');
    $p2 = app(ConfirmPurchase::class)->handle($make(), $this->user->id);
    expect((float) $supplier->fresh()->outstanding)->toBe(17376.0 + 16176.0)
        ->and((float) $p2->supplier_payable)->toBe(16176.0)
        ->and(\App\Http\Requests\Payment\StoreSupplierPaymentRequest::remainingPurchaseBalance($p2))->toBe(16176.0)
        // The landed unit cost (123.3062) is kept on this purchase's batch; the product's own cost is not changed.
        ->and(round((float) \App\Models\ProductBatch::where('purchase_id', $p2->id)->where('product_id', $pot->id)->value('cost_price'), 2))->toBe(123.31);
});

it('refuses a credit sale that would take the customer past their credit limit', function () {
    $customer = $this->createCustomer(['outstanding' => 800, 'credit_limit' => 1000]);
    $product = $this->createProduct(['current_stock' => 10, 'gst_rate' => 0]);

    expect(fn () => fixSale($this, [['product_id' => $product->id, 'qty' => 1, 'rate' => 300, 'gst_rate' => 0]], [
        'customer_id' => $customer->id, 'payment_mode' => 'credit', 'amount_paid' => 0,
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);

    fixSale($this, [['product_id' => $product->id, 'qty' => 1, 'rate' => 200, 'gst_rate' => 0]], [
        'customer_id' => $customer->id, 'payment_mode' => 'credit', 'amount_paid' => 0,
    ]);
    expect((float) $customer->fresh()->outstanding)->toBe(1000.0);
});

it('puts a cancelled or returned pooled case back into the shared unit pool', function () {
    $group = (string) \Illuminate\Support\Str::uuid();
    $unit = $this->createProduct(['current_stock' => 120, 'pool_group_id' => $group, 'pool_role' => 'unit', 'units_per_set' => 24, 'gst_rate' => 0, 'cost_price' => 25]);
    $case = $this->createProduct(['current_stock' => 5, 'pool_group_id' => $group, 'pool_role' => 'set', 'units_per_set' => 24, 'gst_rate' => 0, 'cost_price' => 600]);
    $customer = $this->createCustomer();

    $sale = fixSale($this, [['product_id' => $case->id, 'qty' => 1, 'rate' => 900, 'gst_rate' => 0]], ['customer_id' => $customer->id]);
    expect((float) $unit->fresh()->current_stock)->toBe(96.0)->and((float) $case->fresh()->current_stock)->toBe(4.0);

    app(CancelSale::class)->handle($sale->fresh(), $this->user->id);
    expect((float) $unit->fresh()->current_stock)->toBe(120.0)->and((float) $case->fresh()->current_stock)->toBe(5.0);

    $sale2 = fixSale($this, [['product_id' => $case->id, 'qty' => 2, 'rate' => 900, 'gst_rate' => 0]], ['customer_id' => $customer->id]);
    $return = app(CreateSalesReturn::class)->handle($this->company->id, [
        'sale_id' => $sale2->id, 'return_date' => now()->toDateString(),
        'items' => [['sale_item_id' => $sale2->items->first()->id, 'qty' => 1]],
    ], $this->user->id);
    app(ConfirmSalesReturn::class)->handle($return, $this->user->id);
    expect((float) $unit->fresh()->current_stock)->toBe(96.0)->and((float) $case->fresh()->current_stock)->toBe(4.0);
});

it('takes back the share of points the sale really earned on a return', function () {
    fixSetting($this, 'loyalty_earn_rupees', '100');
    fixSetting($this, 'loyalty_earn_points', '1');
    $customer = $this->createCustomer(['loyalty_points' => 0]);
    $product = $this->createProduct(['current_stock' => 10, 'gst_rate' => 0]);

    $sale = fixSale($this, [['product_id' => $product->id, 'qty' => 4, 'rate' => 500, 'gst_rate' => 0]], ['customer_id' => $customer->id]);
    expect((int) $customer->fresh()->loyalty_points)->toBe(20);

    foreach ([1, 3] as $qty) {
        $return = app(CreateSalesReturn::class)->handle($this->company->id, [
            'sale_id' => $sale->id, 'return_date' => now()->toDateString(),
            'items' => [['sale_item_id' => $sale->items->first()->id, 'qty' => $qty]],
        ], $this->user->id);
        app(ConfirmSalesReturn::class)->handle($return, $this->user->id);
    }

    // 20 earned → 5 back for 1 of 4, then 15 for the other 3: never more than earned.
    expect((int) $customer->fresh()->loyalty_points)->toBe(0)
        ->and((int) LoyaltyLedgerEntry::where('type', 'reverse')->sum('points'))->toBe(-20);
});

it('reports profit before GST at the cost recorded when the goods were sold', function () {
    $product = $this->createProduct(['current_stock' => 10, 'gst_rate' => 18, 'cost_price' => 100]);
    fixSale($this, [['product_id' => $product->id, 'qty' => 2, 'rate' => 250, 'gst_rate' => 18]]);
    $product->update(['cost_price' => 180]); // a later purchase changes today's cost

    $today = now()->toDateString();
    $profit = app(ReportService::class)->approximateProfit($this->company->id, $today, $today);

    // Sales 500 before GST (bill was 590), COGS 2 × 100 = 200.
    expect($profit['aggregate']['sales'])->toBe(500.0)
        ->and($profit['aggregate']['cogs'])->toBe(200.0)
        ->and($profit['aggregate']['profit'])->toBe(300.0);
});

it('deducts GST on purchase returns from input tax in the GST reconciliation', function () {
    $supplier = $this->createSupplier();
    $pot = $this->createProduct(['current_stock' => 0, 'gst_rate' => 18]);
    $purchase = app(ConfirmPurchase::class)->handle(app(CreatePurchase::class)->handle($this->company->id, [
        'supplier_id' => $supplier->id, 'purchase_date' => now()->toDateString(), 'is_interstate' => false,
        'items' => [['product_id' => $pot->id, 'qty' => 50, 'rate' => 120, 'discount' => 300, 'gst_rate' => 18]],
    ], $this->user->id), $this->user->id);
    $return = app(\App\Actions\PurchaseReturns\CreatePurchaseReturn::class)->handle($this->company->id, [
        'purchase_id' => $purchase->id, 'return_date' => now()->toDateString(),
        'items' => [['purchase_item_id' => $purchase->items->first()->id, 'qty' => 5]],
    ], $this->user->id);
    app(\App\Actions\PurchaseReturns\ConfirmPurchaseReturn::class)->handle($return, $this->user->id);

    $today = now()->toDateString();
    $gst = app(SalesAnalyticsService::class)->gstReconciliation($this->company->id, $today, $today);

    expect($gst['input']['tax_total'])->toBe(1026.0)
        ->and($gst['purchase_returns']['tax'])->toBe(102.6)
        ->and($gst['net_gst_payable'])->toBe(-923.4);
});
