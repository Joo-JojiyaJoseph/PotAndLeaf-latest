<?php

/**
 * Update 56:
 *  - Selling prices may not be below the cost price (form, import, bulk split).
 *  - A user cannot be created (or left) without a role; super admins may have none.
 *  - Email addresses must look like name@example.com everywhere.
 *  - Upcoming dates (expected / rental start) cannot be in the past on a new record.
 *  - Rental: blank deposit stays 0; settlement charges plants returned earlier only
 *    up to the day they came back.
 */

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'products.view', 'products.create', 'products.update', 'users.view', 'users.create', 'users.update',
        'customers.view', 'customers.create', 'suppliers.view', 'suppliers.create', 'settings.update', 'settings.view',
        'po.view', 'po.create', 'backorders.view', 'backorders.create', 'advance_orders.view', 'advance_orders.create',
        'rental.view', 'rental.create', 'rental.activate', 'rental.return', 'rental.bill',
    ]);
});

it('refuses a selling price below the cost price, but allows empty prices', function () {
    $cat = \App\Models\ProductCategory::create(['company_id' => $this->company->id, 'code' => 'CAT-U56', 'name' => 'Plants', 'status' => 'active']);
    $unit = \App\Models\ProductUnit::create(['company_id' => $this->company->id, 'code' => 'UNIT-U56', 'name' => 'Piece', 'status' => 'active']);
    $base = ['name' => 'Areca', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'gst_rate' => 5, 'cost_price' => 100, 'status' => 'active'];

    $this->postJson('/api/products', $base + ['retail_price' => 90, 'wholesale_price' => 95], $this->apiHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['retail_price', 'wholesale_price'])
        ->assertJsonFragment(['retail_price' => ['Retail price (₹90) is below the cost price (₹100). You would lose money on every piece.']]);

    $this->postJson('/api/products', $base + ['retail_price' => 140, 'wholesale_price' => 120, 'dealer_price' => ''], $this->apiHeaders())
        ->assertCreated();
});

it('requires a role to create a user, keeps it on edit, and lets a super admin have none', function () {
    $role = Role::create(['name' => 'Cashier', 'slug' => 'cashier-u56', 'is_system' => false]);
    $body = ['name' => 'Anu', 'email' => 'anu@shop.in', 'password' => 'password1', 'is_active' => true];

    $this->postJson('/api/users', $body, $this->apiHeaders())
        ->assertStatus(422)->assertJsonValidationErrors(['role_id']);
    $id = $this->postJson('/api/users', $body + ['role_id' => $role->id], $this->apiHeaders())->assertCreated()->json('data.id');

    $this->putJson("/api/users/{$id}", ['name' => 'Anu', 'email' => 'anu@shop.in', 'is_active' => true, 'role_id' => ''], $this->apiHeaders())
        ->assertStatus(422)->assertJsonValidationErrors(['role_id']);

    $super = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
    $super->companies()->attach($this->company->id, ['is_default' => true]);
    $this->putJson("/api/users/{$super->id}", ['name' => $super->name, 'email' => $super->email, 'is_active' => true, 'role_id' => ''], $this->apiHeaders())
        ->assertOk();
});

it('checks email addresses everywhere', function () {
    foreach (['ravi@gmail', 'ravi.gmail.com', 'ravi @gmail.com'] as $bad) {
        $this->postJson('/api/customers', ['name' => 'Ravi', 'type' => 'retail', 'status' => 'active', 'email' => $bad], $this->apiHeaders())
            ->assertStatus(422)->assertJsonFragment(['email' => ['Enter a valid email address, like name@example.com.']]);
        $this->postJson('/api/suppliers', ['name' => 'Green', 'address' => 'Kochi', 'status' => 'active', 'email' => $bad], $this->apiHeaders())
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
    }
    $this->postJson('/api/customers', ['name' => 'Ravi', 'type' => 'retail', 'status' => 'active', 'email' => 'ravi@gmail.com'], $this->apiHeaders())->assertCreated();

    $this->putJson('/api/settings', ['eod_management_email_recipients' => 'ho@pot.in, wrong'], $this->apiHeaders())
        ->assertStatus(422)->assertJsonValidationErrors(['eod_management_email_recipients']);
    $this->putJson('/api/settings', ['eod_management_email_recipients' => 'ho@pot.in, md@pot.in'], $this->apiHeaders())->assertOk();
});

it('refuses an expected date in the past on a new purchase order', function () {
    $supplier = $this->createSupplier();
    $product = $this->createProduct();
    $body = fn ($expected) => [
        'supplier_id' => $supplier->id, 'po_date' => now()->subDays(3)->toDateString(), 'expected_date' => $expected,
        'items' => [['product_id' => $product->id, 'qty' => 5, 'rate' => 10, 'gst_rate' => 0]],
    ];
    $this->postJson('/api/purchase-orders', $body(now()->subDay()->toDateString()), $this->apiHeaders())
        ->assertStatus(422)->assertJsonFragment(['expected_date' => ['Choose today or a later date.']]);
    $this->postJson('/api/purchase-orders', $body(now()->toDateString()), $this->apiHeaders())->assertCreated();
});

it('keeps a blank deposit at 0 and charges returned plants only up to their return day', function () {
    // 10 plants, ₹10 a day → ₹300 a plant a month (monthly cycle).
    $plant = $this->createProduct(['name' => 'Areca Palm', 'is_rental' => true, 'rental_daily_rate' => 10, 'current_stock' => 50, 'retail_price' => 500]);
    $customer = $this->createCustomer();
    $this->postJson('/api/rentals', [
        'customer_id' => $customer->id, 'start_date' => now()->subDay()->toDateString(), 'billing_cycle' => 'monthly',
        'items' => [['product_id' => $plant->id, 'qty' => 10]],
    ], $this->apiHeaders())->assertStatus(422)->assertJsonValidationErrors(['start_date']); // no past start

    $start = now()->startOfDay();
    $id = $this->postJson('/api/rentals', [
        'customer_id' => $customer->id, 'start_date' => $start->toDateString(), 'billing_cycle' => 'monthly', 'auto_bill' => false,
        'items' => [['product_id' => $plant->id, 'qty' => 10]],
    ], $this->apiHeaders())->assertSuccessful()->json('data.id');
    $this->postJson("/api/rentals/{$id}/activate", [], $this->apiHeaders())->assertOk();
    expect($this->getJson("/api/rentals/{$id}", $this->apiHeaders())->json('data.deposit'))->toEqual(0);

    // Day 11: 4 plants come back.
    $this->travelTo($start->copy()->addDays(10)->setTime(11, 0));
    $itemId = $this->getJson("/api/rentals/{$id}", $this->apiHeaders())->json('data.items.0.id');
    $this->postJson("/api/rentals/{$id}/return", ['returns' => [['id' => $itemId, 'qty' => 4]]], $this->apiHeaders())->assertOk();
    $item = $this->getJson("/api/rentals/{$id}", $this->apiHeaders())->json('data.items.0');
    expect($item['returned_earlier'])->toEqual([['qty' => 4, 'date' => $start->copy()->addDays(10)->toDateString()]]);

    // Day 46: settle the 6 still out.
    $this->travelTo($start->copy()->addDays(45)->setTime(11, 0));
    $this->postJson("/api/rentals/{$id}/settle", [
        'return_date' => now()->toDateString(),
        'lines' => [['id' => $itemId, 'returned' => 6, 'damaged' => 0, 'missing' => 0]],
    ], $this->apiHeaders())->assertOk();

    // 4 × 300 × 1 month (back on day 11) + 6 × 300 × 2 months (day 46) = 1,200 + 3,600 = 4,800
    // (before: all 10 for 2 months = 6,000).
    $r = \App\Models\Rental::find($id);
    expect((float) $r->rental_charge)->toBe(4800.0)->and((float) $r->balance_due)->toBe(4800.0)->and((float) $r->refund_amount)->toBe(0.0);
});
