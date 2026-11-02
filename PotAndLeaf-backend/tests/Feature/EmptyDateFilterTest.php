<?php

/**
 * Clearing a date box on screen means "no limit", not a hidden default.
 * A receipt from 40 days ago: Cash Book with From cleared shows it (before, From
 * fell back to the 1st of this month and the entry vanished into "Opening").
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['accounts.view', 'receipts.view', 'receipts.create', 'reports.view', 'customers.view']);
    $customer = $this->createCustomer(['outstanding' => 400]);
    $this->old = now()->subDays(40)->toDateString();
    $this->postJson('/api/customer-receipts', [
        'customer_id' => $customer->id, 'receipt_date' => $this->old, 'amount' => 400, 'mode' => 'cash',
    ], $this->apiHeaders())->assertCreated();
});

it('shows every entry in the cash book when the dates are cleared', function () {
    // Not sent: the usual default (this month) — the old entry is in the opening.
    $default = $this->getJson('/api/accounting/cash-book', $this->apiHeaders())->assertOk()->json('data');
    expect($default['rows'])->toHaveCount(0);

    // Sent empty (box cleared): from the start, up to today.
    $cleared = $this->getJson('/api/accounting/cash-book?from=&to=', $this->apiHeaders())->assertOk()->json('data');
    expect($cleared['rows'])->toHaveCount(1)->and($cleared['receipts'])->toEqual(400);
});

it('treats a cleared report date as no limit, not the last 30 days', function () {
    $cleared = $this->getJson('/api/reports/accounting/cash-book?from=&to=', $this->apiHeaders())->assertOk()->json('data');
    $default = $this->getJson('/api/reports/accounting/cash-book', $this->apiHeaders())->assertOk()->json('data');
    expect(collect($cleared['rows'] ?? $cleared['entries'] ?? [])->count())->toBeGreaterThan(collect($default['rows'] ?? $default['entries'] ?? [])->count());
});
