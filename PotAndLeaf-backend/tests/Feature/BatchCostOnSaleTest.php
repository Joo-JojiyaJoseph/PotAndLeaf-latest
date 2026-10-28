<?php

/**
 * A product bought at different prices: each batch keeps its own cost.
 * A sale takes stock from the scanned batch, or oldest batch first, and the
 * cost of what was sold (profit reports) uses those batches' costs.
 */

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Models\ProductBatch;
use App\Models\StockLedgerEntry;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['sales.view', 'sales.create', 'sales.confirm', 'reports.view']);
});

function batchSale(object $t, array $item)
{
    $sale = app(CreateSale::class)->handle($t->company->id, [
        'sale_date' => now()->toDateString(), 'payment_mode' => 'cash', 'is_interstate' => false, 'items' => [$item],
    ], $t->user->id);

    return app(ConfirmSale::class)->handle($sale, $t->user->id);
}

it('costs a sale at the batches it came from', function () {
    // Latest purchase cost ₹150 is on the product; an older batch cost ₹100.
    $palm = $this->createProduct(['name' => 'Areca', 'current_stock' => 8, 'cost_price' => 150, 'retail_price' => 300, 'gst_rate' => 0]);
    $old = ProductBatch::create(['company_id' => $this->company->id, 'product_id' => $palm->id, 'batch_no' => 'B-OLD', 'barcode' => 'B-OLD',
        'qty' => 3, 'remaining_qty' => 3, 'cost_price' => 100, 'status' => 'active', 'received_at' => now()->subDays(10)]);
    $new = ProductBatch::create(['company_id' => $this->company->id, 'product_id' => $palm->id, 'batch_no' => 'B-NEW', 'barcode' => 'B-NEW',
        'qty' => 5, 'remaining_qty' => 5, 'cost_price' => 150, 'status' => 'active', 'received_at' => now()->subDay()]);

    // 4 sold without scanning: 3 from the old batch @100 + 1 from the new @150.
    $s1 = batchSale($this, ['product_id' => $palm->id, 'qty' => 4, 'rate' => 300, 'gst_rate' => 0]);
    $cost1 = (float) StockLedgerEntry::where('reference_id', $s1->id)->where('direction', 'out')->value('unit_cost');
    expect($cost1)->toBe(112.5)
        ->and((float) $old->fresh()->remaining_qty)->toBe(0.0)
        ->and((float) $new->fresh()->remaining_qty)->toBe(4.0);

    // 2 sold by scanning the new batch: ₹150 each. Price on the bill stays the product price.
    $s2 = batchSale($this, ['product_id' => $palm->id, 'product_batch_id' => $new->id, 'qty' => 2, 'rate' => 300, 'gst_rate' => 0]);
    expect((float) StockLedgerEntry::where('reference_id', $s2->id)->where('direction', 'out')->value('unit_cost'))->toBe(150.0)
        ->and((float) $s2->items->first()->rate)->toBe(300.0);

    $today = now()->toDateString();
    $profit = app(ReportService::class)->approximateProfit($this->company->id, $today, $today);
    expect($profit['aggregate']['cogs'])->toEqual(750.0)          // 450 + 300
        ->and($profit['aggregate']['sales'])->toEqual(1800.0);

    $margin = app(ReportService::class)->marginAnalysis($this->company->id, $today, now()->addDay()->toDateString());
    expect($margin['rows'][0]['cogs'])->toEqual(750.0)->and($margin['rows'][0]['margin'])->toEqual(1050.0);
});
