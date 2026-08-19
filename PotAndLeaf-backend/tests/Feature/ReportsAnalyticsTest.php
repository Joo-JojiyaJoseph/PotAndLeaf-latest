<?php

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Models\CommissionTransaction;
use App\Models\CompanySetting;
use App\Services\EodManagementSummaryService;
use App\Services\SalesAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'reports.view', 'receipts.view', 'payments.view', 'commission.view',
        'sales.view', 'sales.create', 'sales.confirm',
    ]);
});

function confirmSale(object $test, float $amount): void
{
    $product = $test->createProduct(['current_stock' => 100, 'retail_price' => $amount, 'gst_rate' => 18]);
    $customer = $test->createCustomer();
    $sale = app(CreateSale::class)->handle($test->company->id, [
        'customer_id' => $customer->id,
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => $amount, 'gst_rate' => 18]],
    ], $test->user->id);
    app(ConfirmSale::class)->handle($sale, $test->user->id);
}

it('returns month comparison with current sales', function () {
    confirmSale($this, 10000);

    $this->getJson('/api/reports/sales/comparison-month', $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.current.invoice_count', 1)
        ->assertJsonStructure(['data' => ['current', 'previous', 'difference']]);
});

it('ranks staff on leaderboard by net sales', function () {
    confirmSale($this, 25000);

    $this->getJson('/api/reports/leaderboard?period=month', $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.rankings.0.user_id', $this->user->id)
        ->assertJsonPath('data.rankings.0.rank', 1);
});

it('builds management EOD summary payload', function () {
    confirmSale($this, 15000);
    CompanySetting::query()->updateOrCreate(
        ['company_id' => $this->company->id, 'key' => 'eod_management_enabled'],
        ['value' => '1'],
    );

    $summary = app(EodManagementSummaryService::class)->build($this->company->id, now()->toDateString());

    expect($summary['sales']['invoices'])->toBe(1)
        ->and($summary['sales']['net'])->toBeGreaterThan(0);
});

it('skips duplicate management EOD email when already sent', function () {
    Mail::fake();
    CompanySetting::query()->updateOrCreate(
        ['company_id' => $this->company->id, 'key' => 'eod_management_enabled'],
        ['value' => '1'],
    );
    CompanySetting::query()->updateOrCreate(
        ['company_id' => $this->company->id, 'key' => 'eod_management_email_recipients'],
        ['value' => 'ho@example.com'],
    );

    $service = app(EodManagementSummaryService::class);
    $date = now()->toDateString();

    $first = $service->send($this->company->id, $date, force: true);
    $second = $service->send($this->company->id, $date, force: false);

    expect($first['email_sent'])->toBe(1)
        ->and($second['skipped'])->toBe(1);
});

it('returns GST reconciliation summary', function () {
    confirmSale($this, 5000);

    $this->getJson('/api/reports/gst-reconciliation?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->toDateString(),
    ]), $this->apiHeaders())
        ->assertOk()
        ->assertJsonStructure(['data' => ['output', 'input', 'net_gst_payable']]);
});

it('returns commission report for staff', function () {
    CommissionTransaction::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'commission_type' => 'daily_target',
        'source_type' => 'manual',
        'source_id' => 'test-1',
        'amount' => 500,
        'status' => 'accrued',
        'transaction_date' => now()->toDateString(),
    ]);

    $this->getJson('/api/reports/commission?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->toDateString(),
    ]), $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.totals.commission', 500);
});

it('computes financial year start from settings', function () {
    CompanySetting::query()->updateOrCreate(
        ['company_id' => $this->company->id, 'key' => 'financial_year_start_month'],
        ['value' => '4'],
    );

    $fyStart = app(SalesAnalyticsService::class)->financialYearStart($this->company->id, now()->setMonth(8)->setDay(15));

    expect($fyStart->format('Y-m-d'))->toBe(now()->year.'-04-01');
});
