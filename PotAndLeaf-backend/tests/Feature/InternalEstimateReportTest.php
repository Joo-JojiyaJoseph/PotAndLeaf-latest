<?php

/**
 * Owner-only internal estimate report:
 *   base = share% × (net sales + profit), estimate = base × rate%.
 *
 * Run: php artisan test tests/Feature/InternalEstimateReportTest.php
 */

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

it('works out 50% of sales plus profit from a ₹50,000 sale with ₹10,000 profit', function () {
    $this->createCompanyWithUser(['sales.view', 'sales.create', 'sales.confirm']);
    $product = $this->createProduct(['current_stock' => 10, 'cost_price' => 40000, 'gst_rate' => 0]);

    $sale = app(CreateSale::class)->handle($this->company->id, [
        'sale_date' => now()->toDateString(),
        'payment_mode' => 'cash',
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 50000, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    $today = now()->toDateString();
    $report = app(ReportService::class)->internalEstimate($this->company->id, $today, $today, 50, 18);

    expect($report['totals'])->toBe([
        'sales'    => 50000.0,
        'cogs'     => 40000.0,
        'profit'   => 10000.0,
        'base'     => 30000.0,   // 50% × (50,000 + 10,000)
        'estimate' => 5400.0,    // 18% × 30,000
    ])->and($report['rows'])->toHaveCount(1);
});

it('is only for the owner', function () {
    $this->createCompanyWithUser(['reports.view', 'reports.profit']);

    $this->getJson('/api/reports/internal-estimate', $this->apiHeaders())->assertForbidden();
});
