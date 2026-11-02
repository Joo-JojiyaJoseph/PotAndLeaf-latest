<?php

/**
 * Stock ledger: inactive products are hidden unless asked for.
 */

use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['inventory.view', 'products.view']);
    $this->active = $this->createProduct(['name' => 'Areca', 'current_stock' => 0]);
    $this->inactive = $this->createProduct(['name' => 'Old Pot', 'current_stock' => 0, 'status' => 'inactive']);
    foreach ([$this->active, $this->inactive] as $p) {
        \Illuminate\Support\Facades\DB::transaction(function () use ($p) {
            app(InventoryService::class)->post(product: $p, direction: 'in', qty: 5, unitCost: 10, referenceType: 'adjustment', referenceId: null, note: 'test', userId: $this->user->id);
            $p->save();
        });
    }
});

function ledgerNames(object $t, string $qs = ''): array
{
    return collect($t->getJson('/api/inventory/ledger'.$qs, $t->apiHeaders())->assertOk()->json('data'))
        ->pluck('product_name')->unique()->sort()->values()->all();
}

it('hides inactive products by default and filters on request', function () {
    expect(ledgerNames($this))->toBe(['Areca'])
        ->and(ledgerNames($this, '?product_status=inactive'))->toBe(['Old Pot'])
        ->and(ledgerNames($this, '?product_status=all'))->toBe(['Areca', 'Old Pot'])
        // A product picked by name always shows, even if inactive.
        ->and(ledgerNames($this, '?product_id='.$this->inactive->id))->toBe(['Old Pot']);

    $opts = collect($this->getJson('/api/inventory/ledger/form-data', $this->apiHeaders())->json('data.products'))->keyBy('name');
    expect($opts['Old Pot']['active'])->toBeFalse()->and($opts['Areca']['active'])->toBeTrue();
});
