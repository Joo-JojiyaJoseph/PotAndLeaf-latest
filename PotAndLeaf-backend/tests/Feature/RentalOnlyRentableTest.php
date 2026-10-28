<?php

/**
 * Only products ticked "This product can be rented" can be put on a rental.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['rental.view', 'rental.create']);
});

it('lists only rentable products and refuses a product not set for rent', function () {
    $rentable = $this->createProduct(['name' => 'Areca Palm', 'is_rental' => true, 'rental_daily_rate' => 10, 'current_stock' => 20]);
    $plain = $this->createProduct(['name' => 'Clay Pot', 'is_rental' => false, 'current_stock' => 20]);
    $customer = $this->createCustomer();

    $names = collect($this->getJson('/api/rentals/form-data', $this->apiHeaders())->assertOk()->json('data.products'))->pluck('name')->all();
    expect($names)->toBe(['Areca Palm']);

    $body = fn ($productId) => [
        'customer_id' => $customer->id, 'start_date' => now()->toDateString(), 'billing_cycle' => 'monthly',
        'items' => [['product_id' => $productId, 'qty' => 2]],
    ];
    $this->postJson('/api/rentals', $body($plain->id), $this->apiHeaders())
        ->assertStatus(422)
        ->assertJsonFragment(['items.0.product_id' => ['This product is not set for rent. Tick "This product can be rented" on the product first.']]);
    $id = $this->postJson('/api/rentals', $body($rentable->id) + ['expected_end_date' => now()->addDays(59)->toDateString()], $this->apiHeaders())
        ->assertSuccessful()->json('data.id');

    // Rent at a glance: 2 plants × ₹10/day × 30 days = ₹600 a month (monthly cycle); 60 days = 2 months.
    $r = $this->getJson("/api/rentals/{$id}", $this->apiHeaders())->assertOk()->json('data');
    expect($r['rent_per_cycle'])->toEqual(600)->and($r['expected_cycles'])->toBe(2)->and($r['billed_total'])->toEqual(0);
    expect(collect($this->getJson('/api/rentals', $this->apiHeaders())->json('data'))->first()['rent_per_cycle'])->toEqual(600);
});
