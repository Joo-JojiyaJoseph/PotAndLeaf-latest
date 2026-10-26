<?php

/**
 * The person who makes an item earns "₹ for each piece made" when the
 * production is completed; cancelling that production takes it back.
 * The existing bonus paid when the piece is sold still works alongside.
 */

use App\Models\CommissionRule;
use App\Models\SupervisorCommissionEntry;
use App\Services\CommissionService;
use App\Services\ProductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['production.view', 'production.create', 'production.complete', 'production.delete', 'products.create', 'commission.view', 'commission.manage']);
});

function makeProduction($test, float $qty = 10): string
{
    $output = $test->createProduct(['name' => 'Potted Areca', 'cost_price' => 0, 'current_stock' => 0]);
    $soil = $test->createProduct(['name' => 'Soil', 'sku' => 'SOIL-MB', 'cost_price' => 10, 'current_stock' => 100]);

    return $test->postJson('/api/production', [
        'product_id' => $output->id, 'output_quantity' => $qty, 'supervisor_id' => $test->user->id,
        'order_date' => now()->toDateString(), 'complete' => true,
        'items' => [['component_product_id' => $soil->id, 'qty' => 5]],
    ], $test->apiHeaders())->assertCreated()->assertJsonPath('data.status', 'completed')->json('data.id');
}

it('pays the maker per piece when production is completed, and takes it back on cancel', function () {
    CommissionRule::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'is_supervisor' => true, 'is_active' => true,
        'rate_type' => 'per_unit', 'per_unit_amount' => 0, 'production_per_unit' => 3]);

    $id = makeProduction($this, 10);

    $entry = SupervisorCommissionEntry::where('production_order_id', $id)->where('trigger_event', 'production')->first();
    expect($entry)->not->toBeNull()->and((float) $entry->amount)->toBe(30.0)->and((float) $entry->qty)->toBe(10.0);

    $period = now()->format('Y-m');
    $c = app(CommissionService::class)->compute($this->company->id, $this->user->id, $period);
    expect((float) $c['supervisor_from_production'])->toBe(30.0)->and((float) $c['commission'])->toBe(30.0);

    // Completing again (or a repeat call) never pays twice.
    app(\App\Services\SupervisorCommissionService::class)->accrueOnProduction(\App\Models\ProductionOrder::find($id));
    expect(SupervisorCommissionEntry::where('production_order_id', $id)->where('trigger_event', 'production')->count())->toBe(1);

    app(ProductionService::class)->cancel(\App\Models\ProductionOrder::find($id), $this->user->id);
    $c = app(CommissionService::class)->compute($this->company->id, $this->user->id, $period);
    expect((float) $c['commission'])->toBe(0.0);
});

it('pays nothing for making when the rate has no per-piece amount', function () {
    CommissionRule::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'is_supervisor' => true, 'is_active' => true,
        'rate_type' => 'per_unit', 'per_unit_amount' => 5]);
    $id = makeProduction($this, 4);
    expect(SupervisorCommissionEntry::where('production_order_id', $id)->where('trigger_event', 'production')->exists())->toBeFalse();
});

it('saves the per-piece making amount from the Set staff rate form', function () {
    $this->postJson('/api/commission/rules', ['user_id' => $this->user->id, 'is_supervisor' => true, 'rate_type' => 'per_unit',
        'per_unit_amount' => 2, 'production_per_unit' => 4.5], $this->apiHeaders())->assertSuccessful()
        ->assertJsonPath('data.production_per_unit', 4.5);
});
