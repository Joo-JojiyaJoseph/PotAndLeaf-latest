<?php

/**
 * Proposal gaps and crash fixes found in the 23 Oct 2026 end-to-end test:
 * Tally export, customer order notifications, Finance role, production
 * reports, and reports / commission screens with "All companies".
 *
 * Run: php artisan test tests/Feature/RequirementGapsTest.php
 */

use App\Actions\Purchases\ConfirmPurchase;
use App\Actions\Purchases\CreatePurchase;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Models\CompanySetting;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppMessageLog;
use App\Services\AdvanceOrderService;
use App\Services\CustomerOrderNotificationService;
use App\Services\TallyExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

function gapSuperAdmin(object $test): User
{
    $test->createCompanyWithUser(['reports.view']);

    return User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
}

it('exports balanced Tally vouchers and ledgers for sales, purchases and money', function () {
    $this->createCompanyWithUser(['reports.view', 'accounts.view']);
    CompanySetting::create(['company_id' => $this->company->id, 'key' => 'landed_cost_payable_to_supplier', 'value' => '0']);
    $supplier = $this->createSupplier(['name' => 'Green Pots & Co']);
    $customer = $this->createCustomer(['name' => 'Rahul <R&D>']);
    $pot = $this->createProduct(['current_stock' => 0, 'gst_rate' => 18]);

    app(ConfirmPurchase::class)->handle(app(CreatePurchase::class)->handle($this->company->id, [
        'supplier_id' => $supplier->id, 'purchase_date' => now()->toDateString(), 'is_interstate' => false,
        'landed_cost_total' => 1200,
        'items' => [['product_id' => $pot->id, 'qty' => 50, 'rate' => 120, 'discount' => 300, 'gst_rate' => 18]],
    ], $this->user->id), $this->user->id);

    $sale = app(CreateSale::class)->handle($this->company->id, [
        'customer_id' => $customer->id, 'sale_date' => now()->toDateString(), 'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [['product_id' => $pot->id, 'qty' => 3, 'rate' => 250, 'discount' => 0, 'gst_rate' => 18]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    $today = now()->toDateString();
    $out = app(TallyExportService::class)->build($this->company->id, $today, $today);

    $types = collect($out['vouchers'])->pluck('type')->sort()->values()->all();
    expect($types)->toBe(['Purchase', 'Receipt', 'Sales']);
    foreach ($out['vouchers'] as $v) {
        expect(round(collect($v['lines'])->sum('amount'), 2))->toBe(0.0); // debits = credits
    }

    $purchase = collect($out['vouchers'])->firstWhere('type', 'Purchase');
    $byLedger = collect($purchase['lines'])->pluck('amount', 'ledger');
    expect($byLedger['Green Pots & Co'])->toBe(-6726.0)         // 5,700 + 1,026 GST (freight paid to transporter)
        ->and($byLedger['Freight Payable'])->toBe(-1200.0)
        ->and($byLedger['Input CGST'])->toBe(513.0);

    $xml = simplexml_load_string($out['xml']);
    expect($xml)->not->toBeFalse()
        ->and(str_contains($out['xml'], 'Rahul &lt;R&amp;D&gt;'))->toBeTrue()
        ->and(count($xml->xpath('//VOUCHER')))->toBe(3)
        ->and(count($xml->xpath('//LEDGER[@NAME="Green Pots & Co"]')))->toBe(1);
});

it('downloads the Tally file from the reports API', function () {
    $this->createCompanyWithUser(['reports.view', 'receipts.view']);

    $this->get('/api/reports/accounting/tally-export?from=2026-10-01&to=2026-10-23', $this->apiHeaders())
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
});

it('messages the customer when an advance order is booked and when it is ready', function () {
    $this->createCompanyWithUser(['advance.view', 'advance.create']);
    $customer = $this->createCustomer(['phone' => '9876543210']);
    $product = $this->createProduct(['retail_price' => 500, 'gst_rate' => 0]);

    $order = app(AdvanceOrderService::class)->create($this->company->id, [
        'customer_id' => $customer->id, 'order_date' => now()->toDateString(), 'advance_amount' => 200,
        'items' => [['product_id' => $product->id, 'qty' => 2, 'rate' => 500, 'gst_rate' => 0]],
    ], $this->user->id);
    app(CustomerOrderNotificationService::class)->notifyAdvanceOrder($order, 'booked');

    // No WATI/Twilio keys in tests: the message is logged and the user is told why it wasn't sent.
    $this->postJson("/api/advance-orders/{$order->id}/notify", [], $this->apiHeaders())
        ->assertStatus(422)
        ->assertJsonPath('message', 'WhatsApp is not configured on this server (no WATI or Twilio credentials). Message was logged instead of sent.');

    $logs = WhatsAppMessageLog::where('recipient_id', $customer->id)->orderBy('created_at')->get();
    expect($logs->pluck('message_type')->all())->toBe(['advance_order_booked', 'advance_order_ready'])
        ->and($logs->last()->recipient_phone)->toBe('9876543210')
        ->and($logs->last()->message)->toContain('Balance to pay: ₹800.00');
});

it('explains why it cannot message a customer without a phone number', function () {
    $this->createCompanyWithUser(['advance.view', 'advance.create']);
    $customer = $this->createCustomer(['phone' => null]);
    $product = $this->createProduct(['retail_price' => 500, 'gst_rate' => 0]);
    $order = app(AdvanceOrderService::class)->create($this->company->id, [
        'customer_id' => $customer->id, 'order_date' => now()->toDateString(),
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 500, 'gst_rate' => 0]],
    ], $this->user->id);

    $this->postJson("/api/advance-orders/{$order->id}/notify", [], $this->apiHeaders())
        ->assertStatus(422)
        ->assertJsonPath('message', 'Customer has no WhatsApp/phone number on file.');
});

it('adds the Finance role with money and report permissions but no billing', function () {
    $this->seedPermissions();
    (require base_path('database/migrations/2026_10_23_000003_add_finance_role.php'))->up();

    $finance = Role::where('slug', 'finance')->first();
    $names = $finance->permissions()->pluck('name');
    expect($names)->toContain('payments.view')
        ->and($names)->toContain('accounts.view')
        ->and($names)->toContain('reports.view')
        ->and($names)->not->toContain('sales.create')
        ->and($names)->not->toContain('purchases.create');
});

it('loads the production by-product and batches reports', function () {
    $this->createCompanyWithUser(['reports.view', 'production.view']);
    $headers = $this->apiHeaders();

    $this->getJson('/api/reports/production/by-product?from=2026-10-01&to=2026-10-23', $headers)->assertOk();
    $this->getJson('/api/reports/production/batches?from=2026-10-01&to=2026-10-23', $headers)->assertOk();
});

it('loads every consolidated report and commission list with All companies selected', function () {
    $user = gapSuperAdmin($this);
    $headers = $this->apiHeaders($user);
    $q = 'company_id=all&from=2026-10-01&to=2026-10-23';

    foreach ([
        'reports/sales/comparison-month', 'reports/sales/comparison-yoy', 'reports/sales/ytd',
        'reports/sales/twelve-month-trend', 'reports/gst-reconciliation', 'reports/leaderboard',
        'reports/commission', 'reports/margin', 'reports/profit', 'reports/price-levels',
        'reports/rental/current', 'reports/rental/delivery', 'reports/rental/income',
        'commission/promotions', 'commission/manager-rules', 'commission/transactions',
        'commission/supervisor-rates', 'commission/whatsapp-templates', 'commission/whatsapp-logs',
    ] as $path) {
        $res = $this->getJson("/api/{$path}?{$q}", $headers);
        expect($res->status())->toBe(200, $path.' '.substr((string) $res->getContent(), 0, 200));
    }

    $this->getJson("/api/commission/compute?{$q}&user_id={$user->id}&period=2026-10", $headers)->assertOk();
});

it('answers 401 (not a server error) when a logged-out browser opens an API link', function () {
    $this->get('/api/me')->assertStatus(401)->assertJsonPath('message', 'Unauthenticated.');
});
