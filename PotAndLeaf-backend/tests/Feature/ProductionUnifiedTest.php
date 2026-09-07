<?php

use App\Models\Bom;
use App\Models\Company;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'production.view',
        'production.create',
        'production.complete',
        'production.manage_bom',
        'production.delete',
        'products.create',
    ]);
});

function unifiedPayload($test, array $overrides = []): array
{
    $output = $test->createProduct(['name' => 'Finished Mix', 'cost_price' => 0, 'current_stock' => 0]);
    $soil = $test->createProduct(['name' => 'Soil', 'sku' => 'SOIL-UNI', 'cost_price' => 10, 'current_stock' => 100]);

    return [$output, $soil, array_merge([
        'product_id' => $output->id,
        'output_quantity' => 2,
        'supervisor_id' => $test->user->id,
        'order_date' => now()->toDateString(),
        'items' => [
            ['component_product_id' => $soil->id, 'qty' => 3],
        ],
    ], $overrides)];
}

it('saves a draft production from product and materials without a prior bom', function () {
    [$output, $soil, $payload] = unifiedPayload($this);

    $this->postJson('/api/production', $payload, $this->apiHeaders())
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.output_product', 'Finished Mix')
        ->assertJsonPath('data.bom_name', 'Finished Mix');

    $bom = Bom::forCompany($this->company->id)->where('product_id', $output->id)->first();
    expect($bom)->not->toBeNull();
    expect($bom->items)->toHaveCount(1);
    expect((float) $bom->items->first()->qty)->toBe(3.0);
    expect((float) $soil->fresh()->current_stock)->toBe(100.0);
    expect((float) $output->fresh()->current_stock)->toBe(0.0);
});

it('completes single-step production and adds finished stock', function () {
    [$output, $soil, $payload] = unifiedPayload($this, ['complete' => true]);

    $this->postJson('/api/production', $payload, $this->apiHeaders())
        ->assertCreated()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.total_input_cost', 60)
        ->assertJsonPath('data.output_unit_cost', 30);

    expect((float) $soil->fresh()->current_stock)->toBe(94.0);
    expect((float) $output->fresh()->current_stock)->toBe(2.0);
    expect((float) $output->fresh()->cost_price)->toBe(30.0);
});

it('estimates cost and shortage from form lines', function () {
    $soil = $this->createProduct(['name' => 'Soil', 'sku' => 'SOIL-EST', 'cost_price' => 8, 'current_stock' => 5]);

    $this->getJson('/api/production/estimate?'.http_build_query([
        'output_quantity' => 4,
        'items' => [
            ['component_product_id' => $soil->id, 'qty' => 2],
        ],
    ]), $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.can_complete', false)
        ->assertJsonPath('data.items.0.product_name', 'Soil')
        ->assertJsonPath('data.items.0.required_qty', 8)
        ->assertJsonPath('data.items.0.available_stock', 5)
        ->assertJsonPath('data.items.0.shortage_qty', 3)
        ->assertJsonPath('data.total_material_cost', 64)
        ->assertJsonPath('data.unit_cost', 16);
});

it('creates multi-stage production from the unified form', function () {
    $output = $this->createProduct(['name' => 'Staged Mix', 'current_stock' => 0]);
    $soil = $this->createProduct(['name' => 'Soil', 'sku' => 'SOIL-ST', 'cost_price' => 5, 'current_stock' => 50]);
    $pot = $this->createProduct(['name' => 'Pot', 'sku' => 'POT-ST', 'cost_price' => 4, 'current_stock' => 20]);

    $response = $this->postJson('/api/production', [
        'product_id' => $output->id,
        'output_quantity' => 1,
        'supervisor_id' => $this->user->id,
        'order_date' => now()->toDateString(),
        'stages' => [
            ['name' => 'Mix', 'items' => [['component_product_id' => $soil->id, 'qty' => 1]]],
            ['name' => 'Pot', 'items' => [['component_product_id' => $pot->id, 'qty' => 1]]],
        ],
    ], $this->apiHeaders())->assertCreated();

    $order = ProductionOrder::find($response->json('data.id'));
    expect($order->isMultiStage())->toBeTrue();
    expect($order->stages)->toHaveCount(2);
    expect($order->status)->toBe('draft');
    expect($response->json('data.is_multi_stage'))->toBeTrue();
});

it('includes recipe stock and cost fields on form-data', function () {
    $output = $this->createProduct(['name' => 'Recipe Product']);
    $component = $this->createProduct(['name' => 'Peat', 'sku' => 'PEAT-1', 'current_stock' => 12, 'cost_price' => 7]);
    Bom::create([
        'company_id' => $this->company->id,
        'product_id' => $output->id,
        'name' => 'Recipe Product',
        'output_qty' => 1,
        'is_active' => true,
    ])->items()->create(['component_product_id' => $component->id, 'qty' => 2]);

    $response = $this->getJson('/api/production/form-data', $this->apiHeaders())->assertOk();
    $product = collect($response->json('data.products'))->firstWhere('id', $component->id);
    expect($product['current_stock'])->toBe(12);
    expect($product['cost_price'])->toBe(7);

    $bom = collect($response->json('data.boms'))->firstWhere('product_id', $output->id);
    expect($bom['items'][0]['component_product_id'])->toBe($component->id);
    expect($bom['items'][0]['qty'])->toBe(2);
});

it('lets a super admin change production status', function () {
    [, , $payload] = unifiedPayload($this);
    $id = $this->postJson('/api/production', $payload, $this->apiHeaders())->assertCreated()->json('data.id');

    $admin = User::factory()->create(['is_active' => true]);
    $admin->forceFill(['is_super_admin' => true])->save();
    $this->app['auth']->forgetGuards();

    $this->patchJson("/api/production/orders/{$id}/status", ['status' => 'in_progress'], $this->apiHeaders($admin))
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress');

    $this->app['auth']->forgetGuards();
    $this->patchJson("/api/production/orders/{$id}/status", ['status' => 'cancelled'], $this->apiHeaders($admin))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('forbids status changes for regular users', function () {
    [, , $payload] = unifiedPayload($this);
    $id = $this->postJson('/api/production', $payload, $this->apiHeaders())->assertCreated()->json('data.id');

    $this->patchJson("/api/production/orders/{$id}/status", ['status' => 'in_progress'], $this->apiHeaders())
        ->assertForbidden();
});

it('lets a super admin move a draft production to another company', function () {
    [, , $payload] = unifiedPayload($this);
    $id = $this->postJson('/api/production', $payload, $this->apiHeaders())->assertCreated()->json('data.id');

    $other = Company::create([
        'name' => 'Other Shop',
        'code' => 'OTH'.Str::upper(Str::random(4)),
        'is_active' => true,
    ]);
    $output = $this->createProduct(['name' => 'Other Mix', 'company_id' => $other->id, 'current_stock' => 0]);
    $soil = $this->createProduct(['name' => 'Other Soil', 'sku' => 'SOIL-OTH', 'company_id' => $other->id, 'cost_price' => 4, 'current_stock' => 40]);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->forceFill(['is_super_admin' => true])->save();
    $this->app['auth']->forgetGuards();

    $headers = $this->apiHeaders($admin);
    $headers['X-Company-Id'] = (string) $other->id;

    $this->putJson("/api/production/{$id}", [
        'product_id' => $output->id,
        'output_quantity' => 1,
        'supervisor_id' => $admin->id,
        'order_date' => now()->toDateString(),
        'items' => [
            ['component_product_id' => $soil->id, 'qty' => 1],
        ],
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.company_id', $other->id)
        ->assertJsonPath('data.output_product', 'Other Mix');
});
