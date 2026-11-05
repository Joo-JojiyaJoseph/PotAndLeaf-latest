<?php

/**
 * Dashboard "Sales" card: the total is the bill amount the customer pays
 * (invoice total less points discount), and the payment-mode split, the trend
 * and top customers add up to the same total. Returns are shown beside it.
 */

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Actions\SalesReturns\ConfirmSalesReturn;
use App\Actions\SalesReturns\CreateSalesReturn;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['reports.view']);
    $this->product = $this->createProduct(['current_stock' => 50, 'retail_price' => 500, 'gst_rate' => 0]);
    $this->customer = $this->createCustomer(['outstanding' => 0, 'advance_balance' => 0]);
});

function dashSale(object $t, string $mode, float $qty)
{
    $sale = app(CreateSale::class)->handle($t->company->id, [
        'customer_id' => $t->customer->id, 'sale_date' => now()->toDateString(), 'payment_mode' => $mode, 'is_interstate' => false,
        'amount_paid' => $mode === 'credit' ? 0 : $qty * 500,
        'items' => [['product_id' => $t->product->id, 'qty' => $qty, 'rate' => 500, 'gst_rate' => 0]],
    ], $t->user->id);

    return app(ConfirmSale::class)->handle($sale, $t->user->id);
}

it('adds up: total = payment modes = trend = top customers, after points discount', function () {
    dashSale($this, 'cash', 2);                   // ₹1,000
    $credit = dashSale($this, 'credit', 4);       // ₹2,000, ₹100 off with points
    $credit->forceFill(['loyalty_discount' => 100])->save();

    $today = now()->toDateString();
    $d = app(ReportService::class)->dashboard($this->company->id, $today, $today);

    expect($d['sales']['total'])->toBe(2900.0)
        ->and($d['sales']['count'])->toBe(2)
        ->and($d['sales']['loyalty_discount'])->toBe(100.0)
        ->and(round(collect($d['sales']['by_mode'])->sum(), 2))->toBe(2900.0)
        ->and(round(collect($d['sales']['trend'])->sum('total'), 2))->toBe(2900.0)
        ->and(round(collect($d['top_customers'])->sum('revenue'), 2))->toBe(2900.0)
        ->and($d['sales']['returns'])->toBe(0.0);
});

it('shows returns in the range beside the total', function () {
    $sale = dashSale($this, 'cash', 2);
    $ret = app(CreateSalesReturn::class)->handle($this->company->id, [
        'sale_id' => $sale->id, 'return_date' => now()->toDateString(),
        'items' => [['sale_item_id' => $sale->items->first()->id, 'qty' => 1]],
    ], $this->user->id);
    app(ConfirmSalesReturn::class)->handle($ret, $this->user->id);

    $today = now()->toDateString();
    $d = app(ReportService::class)->dashboard($this->company->id, $today, $today);
    expect($d['sales']['total'])->toBe(1000.0)
        ->and($d['sales']['returns'])->toBe(500.0)
        ->and($d['sales']['net'])->toBe(500.0);
});

it('keeps the API shape the dashboard page reads', function () {
    $this->getJson('/api/reports/dashboard', $this->apiHeaders())->assertOk()
        ->assertJsonStructure(['data' => ['sales' => ['total', 'count', 'returns', 'net', 'by_mode', 'trend'], 'receivables', 'payables', 'inventory']]);
});
