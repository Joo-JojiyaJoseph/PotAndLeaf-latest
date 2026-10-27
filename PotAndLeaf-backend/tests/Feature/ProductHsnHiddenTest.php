<?php

/** The HSN box is hidden on the product form; editing without it keeps the saved code. */

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

it('keeps a saved HSN code when the product is edited without it', function () {
    $this->createCompanyWithUser(['products.view', 'products.create', 'products.update', 'categories.view', 'categories.create']);
    $h = $this->apiHeaders();
    $cat = $this->postJson('/api/masters/categories', ['name' => 'Plants', 'code' => 'PLT', 'status' => 'active'], $h)->json('data.id');
    $id = $this->postJson('/api/products', ['name' => 'Areca', 'category_id' => $cat, 'cost_price' => 100, 'hsn_code' => '0602', 'status' => 'active'], $h)->json('data.id');

    $this->putJson("/api/products/$id", ['name' => 'Areca Palm', 'category_id' => $cat, 'cost_price' => 110, 'status' => 'active'], $h)->assertOk();
    expect(Product::find($id)->hsn_code)->toBe('0602')->and(Product::find($id)->name)->toBe('Areca Palm');

    // A product added without HSN simply has none.
    $id2 = $this->postJson('/api/products', ['name' => 'Snake', 'category_id' => $cat, 'cost_price' => 90, 'status' => 'active'], $h)->assertCreated()->json('data.id');
    expect(Product::find($id2)->hsn_code)->toBeNull();
});
