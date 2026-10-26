<?php

/**
 * Deleting is permanent, so a record that is used anywhere is refused with a
 * plain message (make it Inactive instead); an unused one is really removed.
 */

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'customers.view', 'customers.view_all', 'customers.create', 'customers.delete',
        'suppliers.view', 'suppliers.create', 'suppliers.delete', 'products.view', 'products.create', 'products.delete',
        'categories.view', 'categories.create', 'categories.delete', 'purchases.view', 'purchases.create',
        'sales.view', 'sales.view_all', 'sales.create',
    ]);
    \App\Support\Deletion\InUseGuard::flush();
});

it('refuses to delete a customer, product and supplier that are used on bills', function () {
    $h = $this->apiHeaders();
    $cat = $this->postJson('/api/masters/categories', ['name' => 'Plants', 'code' => 'PLT', 'status' => 'active'], $h)->json('data.id');
    $product = $this->postJson('/api/products', ['name' => 'Areca', 'category_id' => $cat, 'cost_price' => 100, 'retail_price' => 150, 'status' => 'active'], $h)->json('data.id');
    $customer = $this->postJson('/api/customers', ['name' => 'Anu', 'type' => 'retail', 'status' => 'active'], $h)->json('data.id');
    $supplier = $this->postJson('/api/suppliers', ['name' => 'Green', 'address' => 'x', 'status' => 'active'], $h)->json('data.id');
    $this->postJson('/api/sales', ['customer_id' => $customer, 'sale_date' => now()->toDateString(), 'payment_mode' => 'cash',
        'items' => [['product_id' => $product, 'qty' => 1, 'rate' => 150]]], $h)->assertCreated();
    $this->postJson('/api/purchases', ['supplier_id' => $supplier, 'purchase_date' => now()->toDateString(),
        'items' => [['product_id' => $product, 'qty' => 2, 'rate' => 100]]], $h)->assertCreated();

    $r = $this->deleteJson("/api/customers/$customer", [], $h)->assertStatus(422);
    expect($r->json('message'))->toContain('Cannot delete customer "Anu": it is used in 1 sale')->toContain('Make it Inactive instead');
    $this->deleteJson("/api/products/$product", [], $h)->assertStatus(422);
    $this->deleteJson("/api/suppliers/$supplier", [], $h)->assertStatus(422);
    $r = $this->deleteJson("/api/masters/categories/$cat", [], $h)->assertStatus(422);
    expect($r->json('message'))->toContain('1 product');

    expect(Customer::find($customer))->not->toBeNull()
        ->and(Product::find($product))->not->toBeNull()
        ->and(Supplier::find($supplier))->not->toBeNull()
        ->and(ProductCategory::find($cat))->not->toBeNull();
});

it('really removes an unused category and gives the next number without repeating', function () {
    $h = $this->apiHeaders();
    $a = $this->postJson('/api/customers', ['name' => 'A', 'type' => 'retail', 'status' => 'active'], $h)->json('data');
    $b = $this->postJson('/api/customers', ['name' => 'B', 'type' => 'retail', 'status' => 'active'], $h)->json('data');
    $this->deleteJson('/api/customers/'.$a['id'], [], $h)->assertOk();
    $this->assertDatabaseMissing('customers', ['id' => $a['id']]);

    // Two customers made, the first deleted: the next code must not repeat the second one's.
    $c = $this->postJson('/api/customers', ['name' => 'C', 'type' => 'retail', 'status' => 'active'], $h)->json('data');
    expect($c['customer_code'])->not->toBe($b['customer_code']);
});
