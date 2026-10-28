<?php

/**
 * Product export needs category, subcategory and unit as plain names,
 * and the import accepts an optional Subcategory column.
 */

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['products.view', 'products.create']);
});

it('imports a subcategory under its category and lists category, subcategory and unit as names', function () {
    $this->postJson('/api/import/products', ['rows' => [
        ['name' => 'Areca Palm', 'category' => 'Live Plants', 'subcategory' => 'Palms', 'unit' => 'Nos', 'cost_price' => '80', 'retail_price' => '200'],
        ['name' => 'Money Plant', 'category' => 'Live Plants', 'unit' => 'Nos', 'cost_price' => '30', 'retail_price' => '90'],
    ]], $this->apiHeaders())->assertOk();

    $main = ProductCategory::query()->where('name', 'Live Plants')->whereNull('parent_id')->firstOrFail();
    $sub = ProductCategory::query()->where('name', 'Palms')->where('parent_id', $main->id)->firstOrFail();
    expect(Product::query()->where('name', 'Areca Palm')->value('category_id'))->toBe($sub->id);

    $rows = collect($this->getJson('/api/products?per_page=50', $this->apiHeaders())->assertOk()->json('data'))->keyBy('name');
    expect($rows['Areca Palm']['main_category'])->toBe('Live Plants')
        ->and($rows['Areca Palm']['subcategory'])->toBe('Palms')
        ->and($rows['Areca Palm']['unit'])->toBe('Nos')
        ->and($rows['Money Plant']['main_category'])->toBe('Live Plants')
        ->and($rows['Money Plant']['subcategory'])->toBeNull();

    // Importing the same subcategory again reuses it.
    $this->postJson('/api/import/products', ['rows' => [
        ['name' => 'Kentia Palm', 'category' => 'live plants', 'subcategory' => 'palms', 'cost_price' => '90', 'retail_price' => '250'],
    ]], $this->apiHeaders())->assertOk();
    expect(ProductCategory::query()->where('name', 'Palms')->count())->toBe(1);
});
