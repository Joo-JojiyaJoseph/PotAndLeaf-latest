<?php

use App\Models\AccountingTransaction;
use App\Models\LedgerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'accounts.view', 'accounts.create', 'accounts.update', 'accounts.delete',
        'receipts.view', 'receipts.create', 'receipts.delete',
        'payments.view', 'payments.create',
        'customers.view', 'customers.create',
        'reports.view',
    ]);
});

function otherIncomeId(object $test): string
{
    app(\App\Services\ChartOfAccountsService::class)->ensureForCompany($test->company->id);

    return LedgerAccount::forCompany($test->company->id)->where('system_key', 'other_income')->value('id');
}

it('posts a cash book entry and lists it with a running balance', function () {
    $counter = otherIncomeId($this);
    $date = now()->toDateString();

    $created = $this->postJson('/api/accounting/vouchers', [
        'voucher_date' => $date,
        'voucher_type' => 'cash_receipt',
        'amount' => 500,
        'counterpart_account_id' => $counter,
        'narration' => 'Walk-in cash sale',
    ], $this->apiHeaders())->assertCreated()->json('data');

    expect($created['voucher_no'])->toStartWith('CB-');
    expect($created['is_manual'])->toBeTrue();
    expect($created['debit_total'])->toEqual(500);
    expect($created['credit_total'])->toEqual(500);

    $book = $this->getJson('/api/accounting/cash-book?from='.$date.'&to='.$date, $this->apiHeaders())
        ->assertOk()
        ->json();

    expect($book['data']['receipts'])->toBe(500)
        ->and($book['data']['closing'])->toBe(500)
        ->and($book['data']['rows'])->toHaveCount(1)
        ->and($book['data']['rows'][0]['voucher_no'])->toBe($created['voucher_no'])
        ->and($book['data']['rows'][0]['balance'])->toBe(500)
        ->and($book['data']['rows'][0]['is_manual'])->toBeTrue();
});

it('edits a cash book entry', function () {
    $counter = otherIncomeId($this);
    $date = now()->toDateString();

    $id = $this->postJson('/api/accounting/vouchers', [
        'voucher_date' => $date,
        'voucher_type' => 'cash_receipt',
        'amount' => 200,
        'counterpart_account_id' => $counter,
        'narration' => 'Original',
    ], $this->apiHeaders())->assertCreated()->json('data.id');

    $this->putJson("/api/accounting/vouchers/{$id}", [
        'voucher_date' => $date,
        'voucher_type' => 'cash_receipt',
        'amount' => 350,
        'counterpart_account_id' => $counter,
        'narration' => 'Corrected',
    ], $this->apiHeaders())->assertOk()
        ->assertJsonPath('data.narration', 'Corrected')
        ->assertJsonPath('data.debit_total', 350);

    $book = $this->getJson('/api/accounting/cash-book?from='.$date.'&to='.$date, $this->apiHeaders())->json('data');
    expect($book['receipts'])->toBe(350)->and($book['rows'][0]['narration'])->toBe('Corrected');
});

it('adds and edits a journal voucher', function () {
    app(\App\Services\ChartOfAccountsService::class)->ensureForCompany($this->company->id);
    $expense = LedgerAccount::forCompany($this->company->id)->where('system_key', 'other_expense')->value('id');
    $capital = LedgerAccount::forCompany($this->company->id)->where('system_key', 'capital')->value('id');
    $date = now()->toDateString();

    $id = $this->postJson('/api/accounting/vouchers', [
        'voucher_date' => $date,
        'voucher_type' => 'journal',
        'narration' => 'Owner drawing adjustment',
        'entries' => [
            ['ledger_account_id' => $expense, 'debit' => 100, 'credit' => 0],
            ['ledger_account_id' => $capital, 'debit' => 0, 'credit' => 100],
        ],
    ], $this->apiHeaders())->assertCreated()->json('data.id');

    $this->getJson('/api/accounting/journal?from='.$date.'&to='.$date, $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.rows.0.id', $id)
        ->assertJsonPath('data.rows.0.debit', 100);

    $this->putJson("/api/accounting/vouchers/{$id}", [
        'voucher_date' => $date,
        'voucher_type' => 'journal',
        'narration' => 'Corrected journal',
        'entries' => [
            ['ledger_account_id' => $expense, 'debit' => 250, 'credit' => 0],
            ['ledger_account_id' => $capital, 'debit' => 0, 'credit' => 250],
        ],
    ], $this->apiHeaders())->assertOk()->assertJsonPath('data.debit_total', 250);
});

it('rejects an unbalanced journal', function () {
    app(\App\Services\ChartOfAccountsService::class)->ensureForCompany($this->company->id);
    $expense = LedgerAccount::forCompany($this->company->id)->where('system_key', 'other_expense')->value('id');
    $capital = LedgerAccount::forCompany($this->company->id)->where('system_key', 'capital')->value('id');

    $this->postJson('/api/accounting/vouchers', [
        'voucher_date' => now()->toDateString(),
        'voucher_type' => 'journal',
        'entries' => [
            ['ledger_account_id' => $expense, 'debit' => 100, 'credit' => 0],
            ['ledger_account_id' => $capital, 'debit' => 0, 'credit' => 40],
        ],
    ], $this->apiHeaders())->assertStatus(422);
});

it('posts a customer cash receipt into the cash book as read-only', function () {
    $customer = $this->createCustomer(['outstanding' => 400]);
    $date = now()->toDateString();

    $this->postJson('/api/customer-receipts', [
        'customer_id' => $customer->id,
        'receipt_date' => $date,
        'amount' => 400,
        'mode' => 'cash',
        'notes' => 'Collection',
    ], $this->apiHeaders())->assertCreated();

    $book = $this->getJson('/api/accounting/cash-book?from='.$date.'&to='.$date, $this->apiHeaders())->json('data');
    expect($book['rows'])->toHaveCount(1)
        ->and($book['rows'][0]['is_manual'])->toBeFalse()
        ->and($book['rows'][0]['debit'])->toBe(400);

    $voucherId = $book['rows'][0]['id'];
    $this->putJson("/api/accounting/vouchers/{$voucherId}", [
        'voucher_date' => $date,
        'voucher_type' => 'cash_receipt',
        'amount' => 10,
        'counterpart_account_id' => otherIncomeId($this),
    ], $this->apiHeaders())->assertStatus(422);
});

it('cancels a manual cash entry so it leaves the book', function () {
    $counter = otherIncomeId($this);
    $date = now()->toDateString();

    $id = $this->postJson('/api/accounting/vouchers', [
        'voucher_date' => $date,
        'voucher_type' => 'cash_payment',
        'amount' => 80,
        'counterpart_account_id' => $counter,
        'narration' => 'Petty cash',
    ], $this->apiHeaders())->assertCreated()->json('data.id');

    $this->deleteJson("/api/accounting/vouchers/{$id}", [], $this->apiHeaders())->assertOk();

    expect(AccountingTransaction::find($id)->status)->toBe('cancelled');

    $book = $this->getJson('/api/accounting/cash-book?from='.$date.'&to='.$date, $this->apiHeaders())->json('data');
    expect($book['rows'])->toHaveCount(0)->and($book['payments'])->toBe(0);
});

it('shows a recorded receipt in both the cash book and the cash report', function () {
    $customer = $this->createCustomer(['outstanding' => 250]);
    $date = now()->toDateString();

    $this->postJson('/api/customer-receipts', [
        'customer_id' => $customer->id,
        'receipt_date' => $date,
        'amount' => 250,
        'mode' => 'cash',
        'notes' => 'Shop collection',
    ], $this->apiHeaders())->assertCreated();

    $book = $this->getJson('/api/accounting/cash-book?from='.$date.'&to='.$date, $this->apiHeaders())->json('data');
    expect($book['rows'])->toHaveCount(1)->and($book['receipts'])->toEqual(250);

    $report = $this->getJson('/api/reports/accounting/cash-book?from='.$date.'&to='.$date, $this->apiHeaders())
        ->assertOk()
        ->json('data');
    expect(collect($report['rows'])->firstWhere('description', 'Customer receipt')['amount'] ?? null)->toEqual(250);
});

it('reflects a manual cash book entry in the cash report', function () {
    $counter = otherIncomeId($this);
    $date = now()->toDateString();

    $this->postJson('/api/accounting/vouchers', [
        'voucher_date' => $date,
        'voucher_type' => 'cash_receipt',
        'amount' => 75,
        'counterpart_account_id' => $counter,
        'narration' => 'Misc cash',
    ], $this->apiHeaders())->assertCreated();

    $report = $this->getJson('/api/reports/accounting/cash-book?from='.$date.'&to='.$date, $this->apiHeaders())
        ->assertOk()
        ->json('data');

    expect($report['total_in'])->toEqual(75)
        ->and(collect($report['rows'])->pluck('reference')->implode(' '))->toContain('CB-');
});

it('posts a supplier cash payment into the cash book', function () {
    $supplier = $this->createSupplier(['outstanding' => 500]);
    $date = now()->toDateString();

    $this->postJson('/api/supplier-payments', [
        'supplier_id' => $supplier->id,
        'payment_date' => $date,
        'amount' => 500,
        'mode' => 'cash',
        'notes' => 'GRN settlement',
    ], $this->apiHeaders())->assertCreated();

    $book = $this->getJson('/api/accounting/cash-book?from='.$date.'&to='.$date, $this->apiHeaders())->json('data');
    expect($book['rows'])->toHaveCount(1)
        ->and($book['rows'][0]['is_manual'])->toBeFalse()
        ->and($book['payments'])->toEqual(500);
});

it('backfills a leftover receipt when the cash book is opened', function () {
    $customer = $this->createCustomer();
    $date = now()->toDateString();
    \App\Models\CustomerReceipt::create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'receipt_no' => 'RCP-BACKFILL',
        'receipt_date' => $date,
        'amount' => 90,
        'mode' => 'cash',
        'notes' => 'Legacy receipt',
    ]);

    $book = $this->getJson('/api/accounting/cash-book?from='.$date.'&to='.$date, $this->apiHeaders())->json('data');
    expect($book['rows'])->toHaveCount(1)
        ->and($book['rows'][0]['is_manual'])->toBeFalse()
        ->and($book['receipts'])->toEqual(90);
});
