<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

it('lists active customers by name for pick lists, searchable', function () {
    $this->createCompanyWithUser(['loyalty.view']);
    $this->createCustomer(['name' => 'City Garden Dealers', 'loyalty_points' => 9]);
    $this->createCustomer(['name' => 'Walk-in Retail']);
    $this->createCustomer(['name' => 'Old Shop', 'status' => 'inactive']);

    $all = $this->getJson('/api/customers/options', $this->apiHeaders())->assertOk()->json('data');
    expect(collect($all)->pluck('name')->all())->toBe(['City Garden Dealers', 'Walk-in Retail']);
    expect($all[0]['loyalty_points'])->toBe(9);

    $found = $this->getJson('/api/customers/options?search=walk', $this->apiHeaders())->assertOk()->json('data');
    expect(collect($found)->pluck('name')->all())->toBe(['Walk-in Retail']);
});

it('refuses users without customer or loyalty access', function () {
    $this->createCompanyWithUser(['inventory.view']);
    $this->getJson('/api/customers/options', $this->apiHeaders())->assertForbidden();
});
