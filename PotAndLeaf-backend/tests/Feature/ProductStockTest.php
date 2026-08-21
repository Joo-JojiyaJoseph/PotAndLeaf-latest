<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

it('creates product with opening stock equal to current stock not doubled', function () {
    $this->createCompanyWithUser(['products.create']);

    $category = ProductCategory::create([
        'company_id' => $this->company->id,
        'code'       => 'CAT-0001',
        'name'       => 'Plants',
        'status'     => 'active',
    ]);
    $unit = ProductUnit::create([
        'company_id' => $this->company->id,
        'code'       => 'UNIT-0001',
        'name'       => 'Piece',
        'status'     => 'active',
    ]);

    $product = app(ProductService::class)->create($this->company->id, [
        'name'          => 'Rose',
        'category_id'   => $category->id,
        'unit_id'       => $unit->id,
        'cost_price'    => 50,
        'status'        => 'active',
        'opening_stock' => 25,
    ], $this->user->id);

    expect((float) $product->opening_stock)->toBe(25.0);
    expect((float) $product->current_stock)->toBe(25.0);
});

it('adjusts current stock when opening stock is updated', function () {
    $this->createCompanyWithUser(['products.create', 'products.update']);

    $category = ProductCategory::create([
        'company_id' => $this->company->id,
        'code'       => 'CAT-0002',
        'name'       => 'Trees',
        'status'     => 'active',
    ]);

    $product = Product::create([
        'company_id'    => $this->company->id,
        'sku'           => 'SKU-00001',
        'name'          => 'Palm',
        'category_id'   => $category->id,
        'cost_price'    => 100,
        'opening_stock' => 10,
        'current_stock' => 10,
        'status'        => 'active',
    ]);

    $updated = app(ProductService::class)->update($product, [
        'name'          => 'Palm',
        'category_id'   => $category->id,
        'cost_price'    => 100,
        'opening_stock' => 15,
        'status'        => 'active',
    ], $this->user->id);

    expect((float) $updated->opening_stock)->toBe(15.0);
    expect((float) $updated->current_stock)->toBe(15.0);
});

it('super admin can update product with integer company_id unchanged', function () {
    $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
    $this->createCompanyWithUser(['products.update']);

    $category = ProductCategory::create([
        'company_id' => $this->company->id,
        'code'       => 'CAT-0003',
        'name'       => 'Herbs',
        'status'     => 'active',
    ]);
    $product = Product::create([
        'company_id'    => $this->company->id,
        'sku'           => 'SKU-00002',
        'name'          => 'Basil',
        'category_id'   => $category->id,
        'cost_price'    => 20,
        'opening_stock' => 5,
        'current_stock' => 5,
        'status'        => 'active',
    ]);

    $this->actingAs($admin)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->putJson("/api/products/{$product->id}", [
            'name'          => 'Basil updated',
            'category_id'   => $category->id,
            'cost_price'    => 20,
            'opening_stock' => 5,
            'status'        => 'active',
            'company_id'    => $this->company->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Basil updated');
});
