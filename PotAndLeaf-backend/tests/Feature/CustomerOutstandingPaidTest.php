<?php

/**
 * Customer "Outstanding (from before)" goes down when the customer pays it.
 * Outstanding 5,000. Receive 2,000 "On account" → 3,000 left. Editing the
 * customer shows 3,000; saving 3,500 means they now owe 3,500 (what was received stays).
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['customers.view', 'customers.create', 'customers.update', 'receipts.view', 'receipts.create']);
});

it('lowers the customer outstanding from before as it is received, and keeps it right when edited', function () {
    $id = $this->postJson('/api/customers', ['name' => 'Old Dues Customer', 'status' => 'active', 'type' => 'retail', 'opening_balance' => 5000], $this->apiHeaders())
        ->assertCreated()->json('data.id');
    $show = fn () => $this->getJson('/api/customers/'.$id, $this->apiHeaders())->assertOk()->json('data');
    expect($show()['outstanding'])->toEqual(5000)->and($show()['opening_left'])->toEqual(5000);

    $this->postJson('/api/customer-receipts', ['customer_id' => $id, 'receipt_date' => now()->toDateString(), 'amount' => 2000, 'mode' => 'cash'], $this->apiHeaders())->assertCreated();
    $c = $show();
    expect($c['opening_left'])->toEqual(3000)->and($c['opening_paid'])->toEqual(2000)->and($c['outstanding'])->toEqual(3000)
        ->and(collect($this->getJson('/api/customer-receipts/form-data', $this->apiHeaders())->json('data.customers'))->firstWhere('id', $id)['opening_left'])->toEqual(3000);

    // Saving unchanged changes nothing.
    $this->putJson('/api/customers/'.$id, ['name' => 'Old Dues Customer', 'status' => 'active', 'type' => 'retail', 'opening_left' => 3000], $this->apiHeaders())->assertOk();
    expect($show()['outstanding'])->toEqual(3000);

    // Corrected to 3,500 still to receive → owes 3,500.
    $this->putJson('/api/customers/'.$id, ['name' => 'Old Dues Customer', 'status' => 'active', 'type' => 'retail', 'opening_left' => 3500], $this->apiHeaders())->assertOk();
    $c = $show();
    expect($c['opening_left'])->toEqual(3500)->and($c['outstanding'])->toEqual(3500)->and($c['opening_balance'])->toEqual(5500);
});
