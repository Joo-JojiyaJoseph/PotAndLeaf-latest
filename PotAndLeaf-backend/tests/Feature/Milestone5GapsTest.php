<?php

use App\Actions\Sales\CreateSale;
use App\Actions\Sales\ConfirmSale;
use App\Models\CommissionDailyTargetTier;
use App\Models\CommissionRule;
use App\Models\CommissionTier;
use App\Models\CommissionTransaction;
use App\Models\Customer;
use App\Models\LoyaltyLedgerEntry;
use App\Models\ManagerCommissionRule;
use App\Models\ProductionOrder;
use App\Models\Role;
use App\Models\SeasonalCareRule;
use App\Models\SupervisorCommissionEntry;
use App\Models\User;
use App\Models\WhatsAppMessageLog;
use App\Services\CommissionEngine;
use App\Services\CommissionNotificationService;
use App\Services\LoyaltyService;
use App\Services\SeasonalCareService;
use App\Services\SupervisorCommissionService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'sales.view', 'sales.create', 'sales.confirm', 'sales.delete',
        'commission.view', 'commission.manage', 'customers.view',
        'loyalty.view', 'loyalty.manage',
    ]);
});

it('uses the flat slab for the whole sale when the rule says so', function () {
    $rule = CommissionRule::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'base_percent' => 0,
        'is_active' => true,
        'tier_mode' => 'flat',
        'tier_period' => 'monthly',
    ]);
    CommissionTier::create(['commission_rule_id' => $rule->id, 'min_amount' => 0, 'max_amount' => 50000, 'percent' => 1, 'sort_order' => 0]);
    CommissionTier::create(['commission_rule_id' => $rule->id, 'min_amount' => 50000, 'max_amount' => null, 'percent' => 2, 'sort_order' => 1]);

    $product = $this->createProduct(['current_stock' => 10, 'retail_price' => 60000, 'gst_rate' => 0]);
    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $this->createCustomer()->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 60000, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    $tx = CommissionTransaction::where('commission_type', 'salesman_tier')->first();
    expect((float) $tx->amount)->toBe(1200.0);
    expect($tx->rule_snapshot['tier_mode'])->toBe('flat');
});

it('adds a percentage on top of a fixed daily target bonus', function () {
    $rule = CommissionRule::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'base_percent' => 0,
        'is_active' => true,
    ]);
    CommissionDailyTargetTier::create([
        'commission_rule_id' => $rule->id,
        'min_amount' => 1000,
        'bonus_amount' => 50,
        'bonus_percent' => 10,
        'sort_order' => 0,
    ]);
    $product = $this->createProduct(['current_stock' => 10, 'retail_price' => 1000, 'gst_rate' => 0]);
    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $this->createCustomer()->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 1000, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    expect((float) CommissionTransaction::where('commission_type', 'daily_target')->value('amount'))->toBe(150.0);
});

it('accrues manager commission once per branch per day', function () {
    ManagerCommissionRule::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'percent' => 1,
        'is_active' => true,
    ]);
    $product = $this->createProduct(['current_stock' => 10, 'retail_price' => 10000, 'gst_rate' => 0]);
    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $this->createCustomer()->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 10000, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    $date = now()->toDateString();
    $rows = app(CommissionEngine::class)->accrueManagerCommissionForDate($this->company->id, $date);
    expect($rows)->toHaveCount(1);
    expect((float) $rows->first()->amount)->toBe(100.0);

    $again = app(CommissionEngine::class)->accrueManagerCommissionForDate($this->company->id, $date);
    expect($again)->toHaveCount(0);
    expect(CommissionTransaction::where('source_type', 'branch-day')->count())->toBe(1);
});

it('pays a supervisor product rate once for the same sale', function () {
    $product = $this->createProduct(['current_stock' => 10, 'retail_price' => 100, 'cost_price' => 80, 'gst_rate' => 0]);
    CommissionRule::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'is_active' => true,
        'is_supervisor' => true,
        'rate_type' => 'percent',
        'base_percent' => 50,
    ]);
    \App\Models\SupervisorCommissionRate::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'product_id' => $product->id,
        'rate_type' => 'per_unit',
        'rate' => 12,
        'is_active' => true,
    ]);
    ProductionOrder::create([
        'company_id' => $this->company->id,
        'output_product_id' => $product->id,
        'supervisor_id' => $this->user->id,
        'order_no' => 'PRD-M5',
        'order_date' => now()->toDateString(),
        'output_quantity' => 4,
        'commission_pending_qty' => 4,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $service = app(SupervisorCommissionService::class);
    $service->accrue($this->company->id, $product->id, 2, 'sale', 'sale', (string) Str::uuid(), 80);
    $service->accrue($this->company->id, $product->id, 2, 'sale', 'sale', SupervisorCommissionEntry::first()->reference_id, 80);

    expect(SupervisorCommissionEntry::where('trigger_event', 'sale')->count())->toBe(1);
    expect((float) SupervisorCommissionEntry::where('trigger_event', 'sale')->value('amount'))->toBe(24.0);
});

it('rejects a redemption below the configured minimum', function () {
    incentiveGapSetting($this, 'loyalty_redeem_min_points', '50');
    $customer = $this->createCustomer(['loyalty_points' => 100]);
    $product = $this->createProduct(['current_stock' => 10, 'retail_price' => 1000, 'gst_rate' => 0]);

    expect(fn () => app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'loyalty_points_redeemed' => 10,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 1000, 'gst_rate' => 0]],
    ], $this->user->id))->toThrow(ValidationException::class);
});

it('expires earned points once and leaves the balance consistent', function () {
    $customer = $this->createCustomer(['loyalty_points' => 0]);
    $product = $this->createProduct(['current_stock' => 10, 'retail_price' => 500, 'gst_rate' => 0]);
    incentiveGapSetting($this, 'loyalty_points_expiry_days', '30');
    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 500, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);
    expect((int) $customer->fresh()->loyalty_points)->toBe(5);

    LoyaltyLedgerEntry::where('type', 'earn')->update(['expires_at' => now()->subDay()->toDateString()]);
    $loyalty = app(LoyaltyService::class);
    expect($loyalty->expireDuePoints($this->company->id, now()->toDateString()))->toBe(1);
    expect((int) $customer->fresh()->loyalty_points)->toBe(0);
    expect(LoyaltyLedgerEntry::where('type', 'expire')->count())->toBe(1);
    expect($loyalty->expireDuePoints($this->company->id, now()->toDateString()))->toBe(0);
});

it('does not send a second end-of-day summary after a failed attempt', function () {
    $this->user->update(['phone' => '9876543210']);
    incentiveGapSetting($this, 'whatsapp_enabled', '1');
    CommissionRule::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'base_percent' => 2,
        'is_active' => true,
    ]);
    $product = $this->createProduct(['current_stock' => 10, 'retail_price' => 1000, 'gst_rate' => 0]);
    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $this->createCustomer()->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 1000, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    $notifier = app(CommissionNotificationService::class);
    $notifier->sendEodSummaries($this->company->id, now()->toDateString());
    $notifier->sendEodSummaries($this->company->id, now()->toDateString());

    expect(WhatsAppMessageLog::where('message_type', 'eod_commission')->count())->toBe(1);
});

it('skips seasonal care when the customer has opted out', function () {
    incentiveGapSetting($this, 'whatsapp_enabled', '1');
    $customer = $this->createCustomer(['phone' => '9876543210', 'whatsapp_opt_in' => false]);
    $product = $this->createProduct(['current_stock' => 10, 'retail_price' => 100, 'gst_rate' => 0]);
    SeasonalCareRule::create([
        'company_id' => $this->company->id,
        'name' => 'After 15 days',
        'product_id' => $product->id,
        'days_after_purchase' => 15,
        'timing' => 'days_after_purchase',
        'message_template' => 'Hello {customer_name}',
        'is_active' => true,
    ]);
    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->subDays(15)->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 100, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    $this->mock(WhatsAppService::class, function ($mock) {
        $mock->shouldReceive('sendMessage')->never();
    });

    app(SeasonalCareService::class)->sendDueMessages($this->company->id, now()->toDateString());
    expect(WhatsAppMessageLog::where('message_type', 'seasonal_care')->count())->toBe(0);
});

it('lets staff read only their own commission summary', function () {
    $salesman = User::factory()->create(['is_active' => true, 'is_super_admin' => false]);
    $salesman->companies()->attach($this->company->id, ['is_default' => true]);
    $role = Role::create(['name' => 'Salesman', 'slug' => 'salesman-'.Str::lower(Str::random(4)), 'is_system' => false]);
    $role->permissions()->sync(\App\Models\Permission::where('name', 'commission.view_own')->pluck('id'));
    $salesman->roles()->attach($role->id, ['company_id' => $this->company->id]);

    $this->getJson('/api/commission/compute?user_id='.$this->user->id.'&period='.now()->format('Y-m'), $this->apiHeaders($salesman))
        ->assertForbidden();

    $this->getJson('/api/commission/summary?user_id='.$salesman->id.'&from='.now()->startOfMonth()->toDateString().'&to='.now()->toDateString(), $this->apiHeaders($salesman))
        ->assertOk()
        ->assertJsonPath('data.user_id', $salesman->id);
});

it('returns a customer loyalty balance and history', function () {
    $customer = $this->createCustomer(['loyalty_points' => 12]);
    LoyaltyLedgerEntry::create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'type' => 'adjust',
        'points' => 12,
        'balance_after' => 12,
        'note' => 'Opening',
    ]);

    $this->getJson('/api/loyalty/customers/'.$customer->id, $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.loyalty_points', 12)
        ->assertJsonPath('data.ledger.data.0.note', 'Opening');
});

function incentiveGapSetting(object $test, string $key, string $value): void
{
    \App\Models\CompanySetting::query()->updateOrCreate(
        ['company_id' => $test->company->id, 'key' => $key],
        ['value' => $value],
    );
}
