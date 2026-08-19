<?php

use App\Models\ActivityLog;
use App\Models\Bom;
use App\Models\ProductionOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'production.view',
        'production.create',
        'production.complete',
        'production.manage_bom',
        'reports.view',
    ]);
});

it('estimates production cost and stock requirements', function () {
    $output = $this->createProduct(['name' => 'Finished', 'cost_price' => 0]);
    $component = $this->createProduct(['name' => 'Soil', 'sku' => 'SOIL-E1', 'cost_price' => 10, 'current_stock' => 100]);

    $bom = Bom::create([
        'company_id' => $this->company->id,
        'product_id' => $output->id,
        'name' => 'Recipe',
        'output_qty' => 1,
        'is_active' => true,
    ]);
    $bom->items()->create(['component_product_id' => $component->id, 'qty' => 2, 'wastage_pct' => 10]);

    $this->getJson('/api/production/estimate?'.http_build_query([
        'bom_id' => $bom->id,
        'output_quantity' => 5,
    ]), $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.total_material_cost', 110)
        ->assertJsonPath('data.unit_cost', 22)
        ->assertJsonPath('data.can_complete', true)
        ->assertJsonPath('data.items.0.required_qty', 11);
});

it('applies wastage when completing production', function () {
    $output = $this->createProduct(['name' => 'Finished', 'cost_price' => 0, 'current_stock' => 0]);
    $component = $this->createProduct(['name' => 'Soil', 'sku' => 'SOIL-W1', 'cost_price' => 10, 'current_stock' => 20]);

    $bom = Bom::create([
        'company_id' => $this->company->id,
        'product_id' => $output->id,
        'name' => 'Wastage recipe',
        'output_qty' => 1,
        'is_active' => true,
    ]);
    $bom->items()->create(['component_product_id' => $component->id, 'qty' => 10, 'wastage_pct' => 10]);

    $order = ProductionOrder::create([
        'company_id' => $this->company->id,
        'bom_id' => $bom->id,
        'output_product_id' => $output->id,
        'order_no' => 'PRD-000001',
        'order_date' => now()->toDateString(),
        'output_quantity' => 1,
        'status' => 'draft',
    ]);

    $this->postJson("/api/production/orders/{$order->id}/complete", [], $this->apiHeaders())
        ->assertOk();

    expect((float) $component->fresh()->current_stock)->toBe(9.0);
    expect((float) $order->fresh()->total_input_cost)->toBe(110.0);
});

it('logs production order completion to activity log', function () {
    $output = $this->createProduct(['name' => 'Finished', 'current_stock' => 0]);
    $component = $this->createProduct(['name' => 'Soil', 'sku' => 'SOIL-LOG', 'current_stock' => 50]);

    $bom = Bom::create([
        'company_id' => $this->company->id,
        'product_id' => $output->id,
        'name' => 'Log recipe',
        'output_qty' => 1,
        'is_active' => true,
    ]);
    $bom->items()->create(['component_product_id' => $component->id, 'qty' => 1, 'wastage_pct' => 0]);

    $order = ProductionOrder::create([
        'company_id' => $this->company->id,
        'bom_id' => $bom->id,
        'output_product_id' => $output->id,
        'order_no' => 'PRD-000002',
        'order_date' => now()->toDateString(),
        'output_quantity' => 2,
        'status' => 'draft',
    ]);

    $this->postJson("/api/production/orders/{$order->id}/complete", [], $this->apiHeaders())
        ->assertOk();

    expect(ActivityLog::where('module', 'production')->where('action', 'complete')->exists())->toBeTrue();
});

it('returns production summary report', function () {
    $today = now()->toDateString();
    $output = $this->createProduct(['name' => 'Rose Pot']);
    $bom = Bom::create([
        'company_id' => $this->company->id,
        'product_id' => $output->id,
        'name' => 'Rose recipe',
        'output_qty' => 1,
        'is_active' => true,
    ]);

    ProductionOrder::create([
        'company_id' => $this->company->id,
        'bom_id' => $bom->id,
        'output_product_id' => $output->id,
        'order_no' => 'PRD-000010',
        'order_date' => $today,
        'output_quantity' => 10,
        'total_input_cost' => 500,
        'output_unit_cost' => 50,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $this->getJson('/api/reports/production/summary?'.http_build_query([
        'from' => $today,
        'to' => $today,
    ]), $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.summary.completed', 1)
        ->assertJsonPath('data.summary.total_cost', 500);
});
