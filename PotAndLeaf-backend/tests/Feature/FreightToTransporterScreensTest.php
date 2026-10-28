<?php

/**
 * Settings "Freight / loading paid to the supplier" = Off: the purchase screens
 * show the freight as paid to a transporter and the balance owed to the supplier without it.
 */

use App\Actions\Purchases\ConfirmPurchase;
use App\Actions\Purchases\CreatePurchase;
use App\Models\CompanySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['purchases.view', 'purchases.create', 'purchases.confirm']);
});

it('shows owed-to-supplier without the transporter freight', function () {
    $supplier = $this->createSupplier(['outstanding' => 0]);
    $pot = $this->createProduct(['current_stock' => 0, 'gst_rate' => 18]);
    $mix = $this->createProduct(['current_stock' => 0, 'gst_rate' => 5]);
    $make = fn () => app(CreatePurchase::class)->handle($this->company->id, [
        'supplier_id' => $supplier->id, 'purchase_date' => now()->toDateString(), 'is_interstate' => false,
        'landed_cost_total' => 1200,
        'items' => [
            ['product_id' => $pot->id, 'qty' => 50, 'rate' => 120, 'discount' => 300, 'gst_rate' => 18],
            ['product_id' => $mix->id, 'qty' => 20, 'rate' => 450, 'discount' => 0, 'gst_rate' => 5],
        ],
    ], $this->user->id);

    expect($this->getJson('/api/purchases/form-data', $this->apiHeaders())->json('data.freight_to_supplier'))->toBeTrue();
    CompanySetting::query()->updateOrCreate(['company_id' => $this->company->id, 'key' => 'landed_cost_payable_to_supplier'], ['value' => '0']);
    expect($this->getJson('/api/purchases/form-data', $this->apiHeaders())->json('data.freight_to_supplier'))->toBeFalse();

    $draft = $make();
    $d = $this->getJson("/api/purchases/{$draft->id}", $this->apiHeaders())->assertOk()->json('data');
    expect($d['grand_total'])->toEqual(17376)->and($d['supplier_payable'])->toEqual(16176)->and($d['freight_to_transporter'])->toEqual(1200);

    app(ConfirmPurchase::class)->handle($draft, $this->user->id);
    // Changing the setting later does not change a confirmed bill.
    CompanySetting::query()->where('company_id', $this->company->id)->where('key', 'landed_cost_payable_to_supplier')->update(['value' => '1']);
    $c = $this->getJson("/api/purchases/{$draft->id}", $this->apiHeaders())->assertOk()->json('data');
    expect($c['supplier_payable'])->toEqual(16176)->and($c['balance'])->toEqual(16176)->and($c['freight_to_transporter'])->toEqual(1200)
        ->and((float) $supplier->fresh()->outstanding)->toBe(16176.0);
});
