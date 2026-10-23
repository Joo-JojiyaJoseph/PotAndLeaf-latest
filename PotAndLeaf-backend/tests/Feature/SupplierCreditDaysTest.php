<?php

/**
 * Supplier credit days: saved from the API, editable, returned, and used to
 * derive each purchase's due date (purchase_date + credit_days).
 *
 * Run: php artisan test tests/Feature/SupplierCreditDaysTest.php
 */

use App\Models\Purchase;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['suppliers.view', 'suppliers.create', 'suppliers.update']);
});

function creditDaysPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Credit Days Growers',
        'address' => '1 Nursery Road, Calicut',
        'status' => 'active',
    ], $overrides);
}

it('saves credit days when a supplier is created', function () {
    $res = $this->postJson('/api/suppliers', creditDaysPayload(['credit_days' => 30]), $this->apiHeaders())
        ->assertCreated()
        ->assertJsonPath('data.credit_days', 30);

    expect((int) \App\Models\Supplier::find($res->json('data.id'))->credit_days)->toBe(30);
});

it('defaults credit days to 0 when left blank', function () {
    $this->postJson('/api/suppliers', creditDaysPayload(['credit_days' => null]), $this->apiHeaders())
        ->assertCreated()
        ->assertJsonPath('data.credit_days', 0);
});

it('updates credit days on edit', function () {
    $supplier = $this->createSupplier(['credit_days' => 0]);

    $this->putJson("/api/suppliers/{$supplier->id}", creditDaysPayload([
        'name' => $supplier->name,
        'credit_days' => 45,
    ]), $this->apiHeaders())->assertOk()->assertJsonPath('data.credit_days', 45);

    expect((int) $supplier->fresh()->credit_days)->toBe(45);
});

it('keeps existing credit days when an edit does not send the field', function () {
    $supplier = $this->createSupplier(['credit_days' => 30]);

    $this->putJson("/api/suppliers/{$supplier->id}", creditDaysPayload([
        'name' => $supplier->name,
    ]), $this->apiHeaders())->assertOk();

    expect((int) $supplier->fresh()->credit_days)->toBe(30);
});

it('rejects negative or non-integer credit days', function () {
    $this->postJson('/api/suppliers', creditDaysPayload(['credit_days' => -5]), $this->apiHeaders())
        ->assertStatus(422)->assertJsonValidationErrors('credit_days');
    $this->postJson('/api/suppliers', creditDaysPayload(['credit_days' => 2.5]), $this->apiHeaders())
        ->assertStatus(422)->assertJsonValidationErrors('credit_days');
});

it('still ignores balances sent on edit', function () {
    $supplier = $this->createSupplier(['outstanding' => 1000, 'advance_balance' => 200]);

    $this->putJson("/api/suppliers/{$supplier->id}", creditDaysPayload([
        'name' => $supplier->name,
        'outstanding' => 0,
        'advance_balance' => 0,
        'credit_days' => 15,
    ]), $this->apiHeaders())->assertOk();

    $fresh = $supplier->fresh();
    expect((float) $fresh->outstanding)->toBe(1000.0)
        ->and((float) $fresh->advance_balance)->toBe(200.0)
        ->and((int) $fresh->credit_days)->toBe(15);
});

it('uses credit days for the purchase due date', function () {
    $supplier = $this->createSupplier(['credit_days' => 30]);

    Purchase::create([
        'company_id'    => $this->company->id,
        'supplier_id'   => $supplier->id,
        'purchase_no'   => 'PO-CD-1',
        'invoice_no'    => 'INV-CD-1',
        'purchase_date' => '2026-10-01',
        'status'        => 'confirmed',
        'subtotal'      => 1000,
        'tax_total'     => 0,
        'grand_total'   => 1000,
    ]);

    $row = collect(app(PaymentService::class)->payables($this->company->id, $supplier->id))->first();

    expect($row['due_date'])->toBe('2026-10-31');
});
