<?php

/** Product detail and list show the main category and the subcategory; filtering by a main category includes its subcategories. */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

it('shows main category and subcategory separately and filters by main category', function () {
    $this->createCompanyWithUser(['products.view', 'products.create', 'categories.view', 'categories.create', 'subcategories.create']);
    $h = $this->apiHeaders();
    $main = $this->postJson('/api/masters/categories', ['name' => 'Live Plants', 'code' => 'LIVE', 'status' => 'active'], $h)->json('data.id');
    $sub = $this->postJson('/api/masters/categories', ['name' => 'Palms', 'code' => 'PALM', 'status' => 'active', 'parent_id' => $main], $h)->assertCreated()->json('data.id');
    $p1 = $this->postJson('/api/products', ['name' => 'Areca', 'category_id' => $sub, 'cost_price' => 100, 'status' => 'active'], $h)->json('data.id');
    $p2 = $this->postJson('/api/products', ['name' => 'Rose', 'category_id' => $main, 'cost_price' => 50, 'status' => 'active'], $h)->json('data.id');

    $this->getJson("/api/products/$p1", $h)->assertOk()
        ->assertJsonPath('data.main_category', 'Live Plants')->assertJsonPath('data.subcategory', 'Palms');
    $this->getJson("/api/products/$p2", $h)->assertOk()
        ->assertJsonPath('data.main_category', 'Live Plants')->assertJsonPath('data.subcategory', null);

    $list = $this->getJson("/api/products?category_id=$main", $h)->assertOk()->json('data');
    expect(collect($list)->pluck('name')->sort()->values()->all())->toBe(['Areca', 'Rose'])
        ->and(collect($list)->firstWhere('name', 'Areca')['subcategory'])->toBe('Palms');
    expect(collect($this->getJson("/api/products?category_id=$sub", $h)->json('data'))->pluck('name')->all())->toBe(['Areca']);
});
