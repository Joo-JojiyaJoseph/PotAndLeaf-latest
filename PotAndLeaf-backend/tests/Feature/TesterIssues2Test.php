<?php

/**
 * Tester issues reported 26 Oct 2026: retail price tier, commission
 * create/change, manager-only manager share, salesman sees own records,
 * walk-in sales in the bank/cash book.
 *
 * Run: php artisan test tests/Feature/TesterIssues2Test.php
 */

use App\Actions\Sales\CancelSale;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Models\AccountingTransaction;
use App\Models\CommissionRule;
use App\Models\ManagerCommissionRule;
use App\Models\Role;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\AccountingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'sales.view', 'sales.view_all', 'sales.create', 'sales.confirm', 'sales.delete',
        'customers.view', 'customers.view_all', 'customers.create',
        'commission.view', 'commission.manage', 'commission.pay', 'accounts.view',
    ]);
});

function t2Sale(object $test, array $extra = [], float $rate = 500)
{
    $product = $test->createProduct(['current_stock' => 50, 'retail_price' => $rate, 'gst_rate' => 0]);
    $sale = app(CreateSale::class)->handle($test->company->id, array_merge([
        'sale_date' => now()->toDateString(), 'payment_mode' => 'cash', 'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 2, 'rate' => $rate, 'discount' => 0, 'gst_rate' => 0, 'price_level' => 'dealer']],
    ], $extra), $test->user->id);

    return app(ConfirmSale::class)->handle($sale, $test->user->id);
}

it('keeps a retail customer on the retail price tier', function () {
    $retail = $this->createCustomer(['type' => 'retail']);
    $sale = t2Sale($this, ['customer_id' => $retail->id]);
    expect(SaleItem::where('sale_id', $sale->id)->value('price_level'))->toBe('retail');

    $dealer = $this->createCustomer(['type' => 'dealer']);
    $sale2 = t2Sale($this, ['customer_id' => $dealer->id]);
    expect(SaleItem::where('sale_id', $sale2->id)->value('price_level'))->toBe('dealer');
});

it('refuses a commission payment for staff with no commission set, or a second one for the same month', function () {
    $h = $this->apiHeaders();
    // Nothing sold this month, so nothing earned: only ₹0 can be recorded.
    $body = ['user_id' => $this->user->id, 'period' => now()->format('Y-m'), 'amount' => 0, 'mode' => 'cash', 'status' => 'paid'];

    $this->postJson('/api/commission/payouts', $body, $h)->assertStatus(422)
        ->assertJsonPath('message', $this->user->name.' has no commission set. Set a staff rate first.');

    CommissionRule::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'base_percent' => 2, 'is_active' => true, 'is_supervisor' => false]);
    $id = $this->postJson('/api/commission/payouts', $body, $h)->assertCreated()->json('data.id');
    $this->postJson('/api/commission/payouts', ['amount' => 999] + $body, $h)->assertStatus(422);

    // Change it properly instead — but never above what was earned.
    $this->putJson("/api/commission/payouts/{$id}", ['amount' => 150, 'mode' => 'upi', 'status' => 'paid'], $h)
        ->assertStatus(422)->assertJsonPath('errors.amount.0', $this->user->name.' earned ₹0.00 for '.now()->format('Y-m').'. You cannot pay more than that.');
    $this->putJson("/api/commission/payouts/{$id}", ['amount' => 0, 'mode' => 'upi', 'status' => 'paid'], $h)
        ->assertOk()->assertJsonPath('data.mode', 'upi');

    // Removed then added again for the same month works (no duplicate-key error).
    $this->deleteJson("/api/commission/payouts/{$id}", [], $h)->assertOk();
    $this->postJson('/api/commission/payouts', $body, $h)->assertCreated();
});

it('does not silently replace an existing staff rate or manager share', function () {
    $h = $this->apiHeaders();
    $rate = ['user_id' => $this->user->id, 'base_percent' => 2, 'is_active' => true];
    $this->postJson('/api/commission/rules', $rate, $h)->assertOk();
    $this->postJson('/api/commission/rules', ['base_percent' => 5] + $rate, $h)->assertStatus(422);
    $this->postJson('/api/commission/rules', ['base_percent' => 5, 'update_existing' => true] + $rate, $h)->assertOk();
    expect((float) CommissionRule::where('user_id', $this->user->id)->value('base_percent'))->toBe(5.0);

    $share = ['user_id' => $this->user->id, 'percent' => 1];
    $id = $this->postJson('/api/commission/manager-rules', $share, $h)->assertCreated()->json('data.id');
    $this->postJson('/api/commission/manager-rules', $share, $h)->assertStatus(422);
    $this->putJson("/api/commission/manager-rules/{$id}", ['percent' => 1.5], $h)->assertOk();
    expect((float) ManagerCommissionRule::find($id)->percent)->toBe(1.5);
    $this->deleteJson("/api/commission/manager-rules/{$id}", [], $h)->assertOk();
});

it('offers only manager-role users for a manager share', function () {
    $manager = User::factory()->create(['name' => 'Meera Manager', 'is_active' => true]);
    $manager->companies()->attach($this->company->id, ['is_default' => false]);
    $role = Role::create(['name' => 'Manager', 'slug' => 'manager-'.Str::lower(Str::random(4)), 'is_system' => false]);
    $manager->roles()->attach($role->id, ['company_id' => $this->company->id]);

    $data = $this->getJson('/api/commission/form-data', $this->apiHeaders())->assertOk()->json('data');
    expect(collect($data['managers'])->pluck('name')->all())->toBe(['Meera Manager'])
        ->and(collect($data['staff'])->pluck('name'))->toContain($this->user->name);
});

it('shows a salesman only their own customers and bills', function () {
    $mine = $this->createCustomer(['name' => 'My Customer', 'created_by' => null]);
    $salesman = User::factory()->create(['is_active' => true]);
    $salesman->companies()->attach($this->company->id, ['is_default' => true]);
    $role = Role::create(['name' => 'Salesman', 'slug' => 'salesman-'.Str::lower(Str::random(4)), 'is_system' => false]);
    $role->permissions()->sync(\App\Models\Permission::whereIn('name', ['sales.view', 'sales.create', 'customers.view', 'customers.create'])->pluck('id'));
    $salesman->roles()->attach($role->id, ['company_id' => $this->company->id]);

    $own = $this->createCustomer(['name' => 'Salesman Customer']);
    $own->forceFill(['created_by' => $salesman->id])->save();
    $otherSale = t2Sale($this, ['customer_id' => $mine->id]);
    $ownSale = t2Sale($this, ['customer_id' => $own->id]);
    $ownSale->forceFill(['created_by' => $salesman->id])->save();

    app('auth')->forgetGuards();
    $h = $this->apiHeaders($salesman);
    expect(collect($this->getJson('/api/customers', $h)->assertOk()->json('data'))->pluck('name')->all())->toBe(['Salesman Customer'])
        ->and(collect($this->getJson('/api/sales', $h)->assertOk()->json('data'))->pluck('id')->all())->toBe([$ownSale->id]);
    $this->getJson("/api/sales/{$otherSale->id}", $h)->assertForbidden();
    $this->getJson("/api/customers/{$mine->id}", $h)->assertForbidden();
    expect(collect($this->getJson('/api/sales/form-data', $h)->json('data.customers'))->pluck('name')->all())->toBe(['Salesman Customer']);

    // Someone with "see everyone" still sees all.
    app('auth')->forgetGuards();
    expect($this->getJson('/api/customers', $this->apiHeaders())->json('data'))->toHaveCount(2);
});

it('puts walk-in UPI sales in the bank book and walk-in cash sales in the cash book', function () {
    $upi = t2Sale($this, ['payment_mode' => 'upi']);
    $cash = t2Sale($this, ['payment_mode' => 'cash'], 300);
    $today = now()->toDateString();
    $engine = app(AccountingEngine::class);

    $bank = collect($engine->book($this->company->id, 'bank', $today, $today)['rows']);
    $cashBook = collect($engine->book($this->company->id, 'cash', $today, $today)['rows']);
    expect($bank->pluck('source_id')->all())->toBe([$upi->id])->and($bank->first()['debit'])->toBe(1000.0)
        ->and($cashBook->pluck('source_id')->all())->toBe([$cash->id])->and($cashBook->first()['debit'])->toBe(600.0);

    // Cancelling the sale takes it back out of the book.
    app(CancelSale::class)->handle($upi->fresh(), $this->user->id);
    expect(collect($engine->book($this->company->id, 'bank', $today, $today)['rows']))->toHaveCount(0)
        ->and(AccountingTransaction::where('source_id', $upi->id)->where('status', 'posted')->count())->toBe(0);
});
