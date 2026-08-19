<?php

use App\Models\Bom;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'production.view',
        'production.create',
        'production.complete',
        'production.manage_bom',
        'production.delete',
    ]);
});

function createMultiStageBom($test, array $overrides = []): Bom
{
    $output = $test->createProduct(['name' => 'Finished Pot', 'cost_price' => 0, 'current_stock' => 0]);
    $soil = $test->createProduct(['name' => 'Soil', 'sku' => 'SOIL-MS1', 'cost_price' => 5, 'current_stock' => 100]);
    $pot = $test->createProduct(['name' => 'Pot', 'sku' => 'POT-MS1', 'cost_price' => 3, 'current_stock' => 50]);

    $payload = array_merge([
        'product_id' => $output->id,
        'name'       => 'Staged Rose Pot',
        'output_qty' => 1,
        'is_active'  => true,
        'stages'     => [
            [
                'name'  => 'Mixing',
                'items' => [
                    ['component_product_id' => $soil->id, 'qty' => 2, 'wastage_pct' => 0],
                ],
            ],
            [
                'name'  => 'Potting',
                'items' => [
                    ['component_product_id' => $pot->id, 'qty' => 1, 'wastage_pct' => 0],
                ],
            ],
        ],
    ], $overrides);

    $response = $test->postJson('/api/production/boms', $payload, $test->apiHeaders());
    $response->assertCreated();

    return Bom::find($response->json('data.id'));
}

it('creates a multi-stage bill of materials', function () {
    $bom = createMultiStageBom($this);

    expect($bom->isMultiStage())->toBeTrue();
    expect($bom->stages)->toHaveCount(2);
    expect($bom->items)->toHaveCount(2);
});

it('spawns order stages from a multi-stage bom', function () {
    $bom = createMultiStageBom($this);

    $response = $this->postJson('/api/production/orders', [
        'bom_id'          => $bom->id,
        'output_quantity' => 10,
        'order_date'      => now()->toDateString(),
    ], $this->apiHeaders())->assertCreated();

    $order = ProductionOrder::find($response->json('data.id'));
    expect($order->isMultiStage())->toBeTrue();
    expect($order->stages)->toHaveCount(2);
    expect($order->stages->pluck('name')->all())->toBe(['Mixing', 'Potting']);
});

it('blocks single-step complete on multi-stage orders', function () {
    $bom = createMultiStageBom($this);
    $order = ProductionOrder::create([
        'company_id'        => $this->company->id,
        'bom_id'            => $bom->id,
        'output_product_id' => $bom->product_id,
        'order_no'          => 'PRD-000100',
        'order_date'        => now()->toDateString(),
        'output_quantity'   => 5,
        'status'            => 'draft',
    ]);
    foreach ($bom->stages as $stage) {
        $order->stages()->create([
            'bom_stage_id' => $stage->id,
            'sort_order'   => $stage->sort_order,
            'name'         => $stage->name,
            'status'       => 'pending',
        ]);
    }

    $this->postJson("/api/production/orders/{$order->id}/complete", [], $this->apiHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

it('completes multi-stage production sequentially', function () {
    $bom = createMultiStageBom($this);
    $soil = $bom->items->firstWhere('bom_stage_id', $bom->stages[0]->id)->component;
    $pot = $bom->items->firstWhere('bom_stage_id', $bom->stages[1]->id)->component;
    $output = $bom->product;

    $orderResponse = $this->postJson('/api/production/orders', [
        'bom_id'          => $bom->id,
        'output_quantity' => 2,
        'order_date'      => now()->toDateString(),
    ], $this->apiHeaders())->assertCreated();

    $order = ProductionOrder::find($orderResponse->json('data.id'));
    $stage1 = $order->stages[0];
    $stage2 = $order->stages[1];

    $this->postJson("/api/production/orders/{$order->id}/stages/{$stage2->id}/start", [], $this->apiHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stage']);

    $this->postJson("/api/production/orders/{$order->id}/stages/{$stage1->id}/start", [], $this->apiHeaders())
        ->assertOk();
    expect($order->fresh()->status)->toBe('in_progress');

    $this->postJson("/api/production/orders/{$order->id}/stages/{$stage1->id}/complete", [], $this->apiHeaders())
        ->assertOk();
    expect((float) $soil->fresh()->current_stock)->toBe(96.0);

    $this->postJson("/api/production/orders/{$order->id}/stages/{$stage2->id}/start", [], $this->apiHeaders())
        ->assertOk();

    $this->postJson("/api/production/orders/{$order->id}/stages/{$stage2->id}/complete", [], $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect((float) $pot->fresh()->current_stock)->toBe(48.0);
    expect((float) $output->fresh()->current_stock)->toBe(2.0);
    expect((float) $order->fresh()->total_input_cost)->toBe(26.0);
    expect($order->fresh()->items)->toHaveCount(2);
});

it('returns stage permissions on order detail', function () {
    $bom = createMultiStageBom($this);
    $order = ProductionOrder::create([
        'company_id'        => $this->company->id,
        'bom_id'            => $bom->id,
        'output_product_id' => $bom->product_id,
        'order_no'          => 'PRD-000101',
        'order_date'        => now()->toDateString(),
        'output_quantity'   => 1,
        'status'            => 'draft',
    ]);
    foreach ($bom->stages as $stage) {
        ProductionOrderStage::create([
            'production_order_id' => $order->id,
            'bom_stage_id'        => $stage->id,
            'sort_order'          => $stage->sort_order,
            'name'                => $stage->name,
            'status'              => 'pending',
        ]);
    }

    $this->getJson("/api/production/orders/{$order->id}", $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.is_multi_stage', true)
        ->assertJsonPath('data.can.complete', false)
        ->assertJsonPath('data.stages.0.can.start', true)
        ->assertJsonPath('data.stages.1.can.start', false);
});
