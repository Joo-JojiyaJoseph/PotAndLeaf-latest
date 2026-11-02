<?php

/**
 * Leaderboard net sales = bills before GST (after points) − goods returned in the period
 * on that person's bills. Anu sells 4 × ₹500 = ₹2,000 (before GST) and 1 comes back
 * (₹500) → net ₹1,500, ranked below Rahul's ₹1,800.
 */

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Actions\SalesReturns\ConfirmSalesReturn;
use App\Actions\SalesReturns\CreateSalesReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['reports.view']);
    $this->product = $this->createProduct(['current_stock' => 50, 'retail_price' => 500, 'gst_rate' => 18]);
    $this->customer = $this->createCustomer();
});

function lbSale(object $t, int $userId, float $qty, float $rate)
{
    $sale = app(CreateSale::class)->handle($t->company->id, [
        'customer_id' => $t->customer->id, 'sale_date' => now()->toDateString(), 'payment_mode' => 'cash', 'is_interstate' => false,
        'items' => [['product_id' => $t->product->id, 'qty' => $qty, 'rate' => $rate, 'gst_rate' => 18]],
    ], $userId);

    return app(ConfirmSale::class)->handle($sale, $userId);
}

it('subtracts returns from net sales and ranks on the result', function () {
    $rahul = User::factory()->create(['name' => 'Rahul']);
    $anuSale = lbSale($this, $this->user->id, 4, 500);   // ₹2,000 before GST
    lbSale($this, $rahul->id, 1, 1800);                  // ₹1,800

    $ret = app(CreateSalesReturn::class)->handle($this->company->id, [
        'sale_id' => $anuSale->id, 'return_date' => now()->toDateString(),
        'items' => [['sale_item_id' => $anuSale->items->first()->id, 'qty' => 1]],
    ], $this->user->id);
    app(ConfirmSalesReturn::class)->handle($ret, $this->user->id);

    $rows = collect($this->getJson('/api/reports/leaderboard?period=month', $this->apiHeaders())->assertOk()->json('data.rankings'))->keyBy('user_id');
    expect($rows[$this->user->id]['sales'])->toEqual(2000)
        ->and($rows[$this->user->id]['returns'])->toEqual(500)
        ->and($rows[$this->user->id]['net_sales'])->toEqual(1500)
        ->and($rows[$this->user->id]['rank'])->toBe(2)
        ->and($rows[$rahul->id]['net_sales'])->toEqual(1800)
        ->and($rows[$rahul->id]['rank'])->toBe(1);
});
