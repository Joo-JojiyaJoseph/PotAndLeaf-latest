<?php

/**
 * Reorder report: a product with no linked supplier uses the supplier it was
 * last bought from, and the PDF follows the supplier / qty picked on screen.
 */

use App\Actions\Purchases\ConfirmPurchase;
use App\Actions\Purchases\CreatePurchase;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['po.view', 'po.create', 'purchases.create', 'purchases.confirm', 'products.create', 'suppliers.view']);
});

it('uses the last purchase supplier, and the PDF report follows the screen choices', function () {
    $sup = $this->createSupplier(['name' => 'Last Bought From']);
    $other = $this->createSupplier(['name' => 'Picked On Screen']);
    $product = $this->createProduct(['name' => 'product1', 'reorder_level' => 50, 'current_stock' => 0, 'cost_price' => 100]);

    $purchase = app(CreatePurchase::class)->handle($this->company->id, ['supplier_id' => $sup->id, 'purchase_date' => now()->toDateString(),
        'items' => [['product_id' => $product->id, 'qty' => 19, 'rate' => 100]]], $this->user->id);
    app(ConfirmPurchase::class)->handle($purchase, $this->user->id);

    $svc = app(PurchaseOrderService::class);
    $report = $svc->reorderReport($this->company->id);
    expect($report['unassigned'])->toBe([])
        ->and($report['suppliers'][0]['supplier_name'])->toBe('Last Bought From')
        ->and($report['suppliers'][0]['items'][0]['supplier_source'])->toBe('last_purchase');

    // The screen picked another supplier and changed the qty: the PDF data follows it.
    $picked = $svc->reorderReport($this->company->id, [$product->id => ['supplier_id' => $other->id, 'qty' => '81', 'include' => true]]);
    expect($picked['suppliers'][0]['supplier_name'])->toBe('Picked On Screen')
        ->and((float) $picked['suppliers'][0]['items'][0]['suggested_qty'])->toBe(81.0);

    // Un-ticked on screen: left out of the PDF.
    $none = $svc->reorderReport($this->company->id, [$product->id => ['include' => false]]);
    expect($none['suppliers'])->toBe([])->and($none['unassigned'])->toBe([]);

    // The export endpoint accepts the choices and returns a PDF.
    $this->get('/api/purchase-orders/reorder-report/export?layout=flat&choices='.urlencode(json_encode([$product->id => ['supplier_id' => $other->id, 'qty' => 81]])), $this->apiHeaders())
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});
