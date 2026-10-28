<?php

/**
 * Journal lists the automatic entries too; form pick-lists for head office show only
 * the company chosen in the form.
 */

use App\Models\Company;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['accounts.view', 'payments.view', 'payments.create', 'purchases.create']);
});

it('shows supplier payments in the journal by themselves', function () {
    $s = $this->createSupplier(['outstanding' => 500, 'opening_balance' => 500]);
    $this->postJson('/api/supplier-payments', ['supplier_id' => $s->id, 'amount' => 200, 'mode' => 'cash',
        'payment_date' => now()->toDateString()], $this->apiHeaders())->assertCreated();

    $all = $this->getJson('/api/accounting/journal', $this->apiHeaders())->assertOk()->json('data');
    expect($all['rows'])->toHaveCount(1)
        ->and($all['rows'][0]['is_manual'])->toBeFalse()
        ->and(collect($all['rows'][0]['entries'])->sum('debit'))->toEqual(200)
        ->and($all['receipts'])->toEqual($all['payments']);   // debits = credits

    $only = $this->getJson('/api/accounting/journal?show=corrections', $this->apiHeaders())->assertOk()->json('data.rows');
    expect($only)->toHaveCount(0);
});

it('gives head office only the chosen company suppliers in a form', function () {
    $this->user->update(['is_super_admin' => true]);
    $other = Company::create(['name' => 'Other Co', 'code' => 'OTH'.Str::upper(Str::random(3)), 'is_active' => true]);
    $this->createSupplier(['name' => 'Mine']);
    Supplier::create(['company_id' => $other->id, 'supplier_code' => 'SUP-X1', 'name' => 'Theirs', 'status' => 'active', 'outstanding' => 0, 'opening_balance' => 0]);

    $names = collect($this->getJson('/api/supplier-payments/form-data', $this->apiHeaders())->assertOk()->json('data.suppliers'))->pluck('name')->all();
    expect($names)->toBe(['Mine']);
    $names = collect($this->getJson('/api/purchases/form-data', $this->apiHeaders())->assertOk()->json('data.suppliers'))->pluck('name')->all();
    expect($names)->toBe(['Mine']);
});
