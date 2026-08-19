<?php

use App\Models\Bom;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'production.view',
        'production.create',
        'production.manage_bom',
        'production.complete',
    ]);
});

it('loads production form-data including supervisors', function () {
    $this->getJson('/api/production/form-data', $this->apiHeaders())
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['products', 'units', 'locations', 'boms', 'supervisors'],
        ]);
});

it('excludes inactive boms from form-data', function () {
    $output = $this->createProduct(['name' => 'Finished Rose']);
    $component = $this->createProduct(['name' => 'Soil Mix', 'sku' => 'SOIL-1']);

    Bom::create([
        'company_id' => $this->company->id,
        'product_id' => $output->id,
        'name' => 'Active recipe',
        'output_qty' => 1,
        'is_active' => true,
    ])->items()->create(['component_product_id' => $component->id, 'qty' => 2]);

    $inactive = Bom::create([
        'company_id' => $this->company->id,
        'product_id' => $output->id,
        'name' => 'Old recipe',
        'output_qty' => 1,
        'is_active' => false,
    ]);
    $inactive->items()->create(['component_product_id' => $component->id, 'qty' => 1]);

    $response = $this->getJson('/api/production/form-data', $this->apiHeaders())
        ->assertOk();

    $names = collect($response->json('data.boms'))->pluck('name');
    expect($names)->toContain('Active recipe')->not->toContain('Old recipe');
});

it('rejects production order with inactive bom', function () {
    $output = $this->createProduct(['name' => 'Finished Rose']);
    $component = $this->createProduct(['name' => 'Soil Mix', 'sku' => 'SOIL-2', 'current_stock' => 50]);

    $bom = Bom::create([
        'company_id' => $this->company->id,
        'product_id' => $output->id,
        'name' => 'Inactive recipe',
        'output_qty' => 1,
        'is_active' => false,
    ]);
    $bom->items()->create(['component_product_id' => $component->id, 'qty' => 1]);

    $this->postJson('/api/production/orders', [
        'bom_id' => $bom->id,
        'output_quantity' => 1,
        'order_date' => now()->toDateString(),
    ], $this->apiHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['bom_id']);
});
