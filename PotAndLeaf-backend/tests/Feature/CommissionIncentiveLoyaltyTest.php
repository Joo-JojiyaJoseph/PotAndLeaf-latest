<?php

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Actions\Sales\CancelSale;
use App\Models\CommissionDailyTargetTier;
use App\Models\CommissionRule;
use App\Models\CommissionTier;
use App\Models\CommissionTransaction;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\LoyaltyLedgerEntry;
use App\Services\CommissionEngine;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'sales.view', 'sales.create', 'sales.confirm', 'sales.delete', 'sales_returns.create', 'sales_returns.confirm',
        'commission.view', 'commission.manage', 'customers.view', 'customers.update',
        'loyalty.view', 'loyalty.manage', 'loyalty.adjust',
    ]);
});

function incentiveSetting(object $test, string $key, string $value): void
{
    CompanySetting::query()->updateOrCreate(
        ['company_id' => $test->company->id, 'key' => $key],
        ['value' => $value],
    );
}

it('accrues marginal tier commission on sale confirm', function () {
    $rule = CommissionRule::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'base_percent' => 0,
        'is_active' => true,
        'is_supervisor' => false,
    ]);
    CommissionTier::create(['commission_rule_id' => $rule->id, 'min_amount' => 0, 'max_amount' => 50000, 'percent' => 1, 'sort_order' => 0]);
    CommissionTier::create(['commission_rule_id' => $rule->id, 'min_amount' => 50000, 'max_amount' => null, 'percent' => 2, 'sort_order' => 1]);

    $product = $this->createProduct(['current_stock' => 100, 'retail_price' => 60000, 'gst_rate' => 0]);
    $customer = $this->createCustomer();

    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 60000, 'gst_rate' => 0]],
    ], $this->user->id);

    app(ConfirmSale::class)->handle($sale, $this->user->id);

    $tx = CommissionTransaction::where('source_id', $sale->id)->where('commission_type', 'salesman_tier')->first();
    expect($tx)->not->toBeNull();
    // 50000 @ 1% + 10000 @ 2% = 500 + 200 = 700
    expect((float) $tx->amount)->toBe(700.0);
});

it('awards daily target bonus once per tier per day', function () {
    $rule = CommissionRule::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'base_percent' => 0,
        'is_active' => true,
    ]);
    CommissionDailyTargetTier::create(['commission_rule_id' => $rule->id, 'min_amount' => 50000, 'bonus_amount' => 500, 'sort_order' => 0]);
    CommissionDailyTargetTier::create(['commission_rule_id' => $rule->id, 'min_amount' => 75000, 'bonus_amount' => 1000, 'sort_order' => 1]);

    $product = $this->createProduct(['current_stock' => 200, 'retail_price' => 80000, 'gst_rate' => 0]);
    $customer = $this->createCustomer();

    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 80000, 'gst_rate' => 0]],
    ], $this->user->id);

    app(ConfirmSale::class)->handle($sale, $this->user->id);

    $bonuses = CommissionTransaction::where('commission_type', 'daily_target')->where('user_id', $this->user->id)->get();
    expect($bonuses)->toHaveCount(2);
    expect((float) $bonuses->sum('amount'))->toBe(1500.0);

    // Second sale same day should not duplicate bonuses
    $sale2 = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 1000, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale2, $this->user->id);

    expect(CommissionTransaction::where('commission_type', 'daily_target')->where('user_id', $this->user->id)->count())->toBe(2);
});

it('reverses commission transactions on sale cancel', function () {
    CommissionRule::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'base_percent' => 5,
        'is_active' => true,
    ]);
    $product = $this->createProduct(['current_stock' => 10, 'retail_price' => 1000, 'gst_rate' => 0]);
    $customer = $this->createCustomer();

    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 1000, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    app(CancelSale::class)->handle($sale->fresh(), $this->user->id);

    expect(CommissionTransaction::where('source_id', $sale->id)->where('status', 'reversed')->count())->toBeGreaterThan(0);
    expect(CommissionTransaction::where('commission_type', 'reversal')->where('source_id', $sale->id)->exists())->toBeTrue();
});

it('earns and reverses loyalty points with ledger', function () {
    incentiveSetting($this, 'loyalty_earn_rupees', '100');
    incentiveSetting($this, 'loyalty_earn_points', '1');
    $customer = $this->createCustomer(['loyalty_points' => 0]);
    $product = $this->createProduct(['current_stock' => 10, 'retail_price' => 500, 'gst_rate' => 0]);

    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 500, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    expect((int) $customer->fresh()->loyalty_points)->toBe(5);
    expect(LoyaltyLedgerEntry::where('type', 'earn')->count())->toBe(1);

    app(CancelSale::class)->handle($sale->fresh(), $this->user->id);
    expect((int) $customer->fresh()->loyalty_points)->toBe(0);
});

it('allows manual loyalty adjustment via API', function () {
    $customer = $this->createCustomer(['loyalty_points' => 10]);

    $this->postJson('/api/loyalty/adjust', [
        'customer_id' => $customer->id,
        'points' => 25,
        'reason' => 'Promotional goodwill',
    ], $this->apiHeaders())->assertOk();

    expect((int) $customer->fresh()->loyalty_points)->toBe(35);
    expect(LoyaltyLedgerEntry::where('type', 'adjust')->count())->toBe(1);
});

it('builds daily commission summary', function () {
    CommissionRule::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'base_percent' => 2,
        'is_active' => true,
    ]);
    $product = $this->createProduct(['current_stock' => 20, 'retail_price' => 1000, 'gst_rate' => 0]);
    $customer = $this->createCustomer();
    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 1000, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    $summary = app(CommissionEngine::class)->dailySummary($this->company->id, $this->user->id, now()->toDateString());
    expect($summary['sales_total'])->toBe(1000.0);
    expect($summary['sales_commission'])->toBe(20.0);
});

it('accrues manager commission on branch net sales', function () {
    \App\Models\ManagerCommissionRule::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'percent' => 1,
        'is_active' => true,
    ]);

    $product = $this->createProduct(['current_stock' => 10, 'retail_price' => 10000, 'gst_rate' => 0]);
    $customer = $this->createCustomer();
    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->startOfMonth()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 10000, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    $period = now()->format('Y-m');
    $rows = app(CommissionEngine::class)->accrueManagerCommission($this->company->id, $period);
    expect($rows)->toHaveCount(1);
    expect((float) $rows->first()->amount)->toBe(100.0);
});

it('accrues product promotion bonus on sale confirm', function () {
    $product = $this->createProduct(['current_stock' => 50, 'retail_price' => 500, 'gst_rate' => 0]);
    \App\Models\CommissionPromotion::create([
        'company_id' => $this->company->id,
        'name' => 'Rose promo',
        'product_id' => $product->id,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
        'min_qty' => 2,
        'bonus_per_unit' => 50,
        'is_active' => true,
    ]);
    $customer = $this->createCustomer();
    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 3, 'rate' => 500, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    $bonus = CommissionTransaction::where('commission_type', 'promotion')->sum('amount');
    expect((float) $bonus)->toBe(150.0);
});

it('earns loyalty points via configurable rule', function () {
    \App\Models\LoyaltyRule::create([
        'company_id' => $this->company->id,
        'name' => 'Standard earn',
        'rule_type' => 'spend',
        'earn_rupees' => 100,
        'earn_points' => 2,
        'is_active' => true,
    ]);
    $customer = $this->createCustomer(['loyalty_points' => 0]);
    $product = $this->createProduct(['current_stock' => 10, 'retail_price' => 500, 'gst_rate' => 0]);
    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 500, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    expect((int) $customer->fresh()->loyalty_points)->toBe(10);
});

it('stores and renders EOD whatsapp template', function () {
    \App\Models\WhatsAppTemplate::create([
        'company_id' => $this->company->id,
        'slug' => 'eod_commission',
        'name' => 'EOD',
        'body' => 'Hi {employee_name}, total {total_incentive} on {date}',
        'is_active' => true,
    ]);
    $msg = app(\App\Services\CommissionNotificationService::class)->buildEodMessage(
        'Test Co', 'Alice', ['date' => now()->toDateString(), 'sales_total' => 1000, 'sales_commission' => 20,
            'daily_target_bonus' => 0, 'promotion_bonus' => 0, 'supervisor_commission' => 0, 'manager_commission' => 0,
            'total_incentive' => 20, 'daily_target' => 0, 'target_achievement_pct' => 0],
        $this->company->id,
    );
    expect($msg)->toContain('Alice')->toContain('₹20.00');
});
