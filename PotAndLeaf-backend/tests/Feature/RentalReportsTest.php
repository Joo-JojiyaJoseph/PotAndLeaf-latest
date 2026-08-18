<?php

use App\Models\Rental;
use App\Models\RentalInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser();
});

function makeRental(object $test, array $overrides = [], array $items = []): Rental
{
    $customer = $overrides['customer'] ?? $test->createCustomer();
    unset($overrides['customer']);
    $location = $overrides['location'] ?? $test->createLocation();
    unset($overrides['location']);
    $product = $test->createProduct();

    $rental = Rental::create(array_merge([
        'company_id' => $test->company->id,
        'customer_id' => $customer->id,
        'location_id' => $location->id,
        'rental_no' => 'RNT-'.str_pad((string) (Rental::count() + 1), 6, '0', STR_PAD_LEFT),
        'start_date' => now()->subDays(10)->toDateString(),
        'expected_end_date' => now()->addDays(5)->toDateString(),
        'billing_cycle' => 'monthly',
        'deposit' => 1000,
        'status' => 'active',
        'activated_at' => now()->subDays(8),
    ], $overrides));

    $rental->items()->createMany($items ?: [[
        'product_id' => $product->id,
        'product_name' => $product->name,
        'qty' => 2,
        'rate_per_cycle' => 100,
        'returned_qty' => 0,
        'damaged_qty' => 0,
        'missing_qty' => 0,
    ]]);

    return $rental->fresh(['items', 'customer', 'location']);
}

it('returns empty rental delivery report', function () {
    $this->getJson('/api/reports/rental/delivery', $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.total', 0);
});

it('filters rental delivery report by date and location', function () {
    $location = $this->createLocation(['code' => 'A1', 'name' => 'Branch A']);
    $other = $this->createLocation(['code' => 'B1', 'name' => 'Branch B']);

    makeRental($this, [
        'location' => $location,
        'activated_at' => now()->subDays(3),
        'rental_no' => 'RNT-000100',
    ]);
    makeRental($this, [
        'location' => $other,
        'activated_at' => now()->subDays(3),
        'rental_no' => 'RNT-000101',
    ]);
    makeRental($this, [
        'location' => $location,
        'activated_at' => now()->subDays(40),
        'rental_no' => 'RNT-000102',
    ]);

    $this->getJson('/api/reports/rental/delivery?'.http_build_query([
        'from' => now()->subDays(7)->toDateString(),
        'to' => now()->toDateString(),
        'location_id' => $location->id,
    ]), $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.rental_no', 'RNT-000100')
        ->assertJsonPath('data.0.deposit', 1000);
});

it('denies rental delivery report without permission', function () {
    $denied = User::factory()->create(['is_super_admin' => false, 'is_active' => true]);
    $denied->companies()->attach($this->company->id, ['is_default' => true]);
    // sales only — no reports.view / rental.view
    $role = \App\Models\Role::create(['name' => 'Cashier', 'slug' => 'cashier-'.Str::random(4), 'is_system' => false]);
    $role->permissions()->sync(\App\Models\Permission::whereIn('name', ['sales.view'])->pluck('id'));
    $denied->roles()->attach($role->id, ['company_id' => $this->company->id]);

    $this->getJson('/api/reports/rental/delivery', $this->apiHeaders($denied))
        ->assertForbidden();
});

it('returns empty rental income report', function () {
    $this->getJson('/api/reports/rental/income', $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('data.rows', []);
});

it('groups rental income by invoices in range', function () {
    $rental = makeRental($this);
    RentalInvoice::create([
        'company_id' => $this->company->id,
        'rental_id' => $rental->id,
        'invoice_no' => 'RINV-000001',
        'period_from' => now()->subDays(2)->toDateString(),
        'period_to' => now()->toDateString(),
        'cycles' => 1,
        'amount' => 250,
        'status' => 'unpaid',
    ]);

    $this->getJson('/api/reports/rental/income?'.http_build_query([
        'from' => now()->subDays(7)->toDateString(),
        'to' => now()->toDateString(),
        'period' => 'daily',
    ]), $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.total', 250)
        ->assertJsonPath('data.count', 1)
        ->assertJsonPath('data.rows.0.invoice_no', 'RINV-000001');
});

it('denies rental income report without permission', function () {
    $denied = User::factory()->create(['is_super_admin' => false, 'is_active' => true]);
    $denied->companies()->attach($this->company->id, ['is_default' => true]);

    $this->getJson('/api/reports/rental/income', $this->apiHeaders($denied))
        ->assertForbidden();
});

it('returns empty currently rented report', function () {
    $this->getJson('/api/reports/rental/current', $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.total', 0);
});

it('lists items currently out on rent', function () {
    makeRental($this, ['status' => 'active', 'rental_no' => 'RNT-000200']);
    makeRental($this, ['status' => 'returned', 'returned_at' => now(), 'rental_no' => 'RNT-000201']);

    $this->getJson('/api/reports/rental/current', $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.rental_no', 'RNT-000200')
        ->assertJsonPath('data.0.qty', 2);
});

it('denies currently rented report without permission', function () {
    $denied = User::factory()->create(['is_super_admin' => false, 'is_active' => true]);
    $denied->companies()->attach($this->company->id, ['is_default' => true]);

    $this->getJson('/api/reports/rental/current', $this->apiHeaders($denied))
        ->assertForbidden();
});

it('returns empty customer rental history', function () {
    $customer = $this->createCustomer();

    $this->getJson("/api/reports/rental/customer/{$customer->id}", $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.total', 0);
});

it('lists customer-wise rental history with settlement fields', function () {
    $customer = $this->createCustomer(['name' => 'History Customer']);
    makeRental($this, [
        'customer' => $customer,
        'status' => 'returned',
        'rental_charge' => 300,
        'damage_charge' => 50,
        'missing_charge' => 0,
        'refund_amount' => 650,
        'return_date' => now()->toDateString(),
        'rental_no' => 'RNT-000300',
    ]);

    $this->getJson("/api/reports/rental/customer/{$customer->id}", $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.rental_no', 'RNT-000300')
        ->assertJsonPath('data.0.rental_charge', 300)
        ->assertJsonPath('data.0.damage_charge', 50)
        ->assertJsonPath('data.0.refund_amount', 650);
});

it('denies customer rental history without permission', function () {
    $customer = $this->createCustomer();
    $denied = User::factory()->create(['is_super_admin' => false, 'is_active' => true]);
    $denied->companies()->attach($this->company->id, ['is_default' => true]);

    $this->getJson("/api/reports/rental/customer/{$customer->id}", $this->apiHeaders($denied))
        ->assertForbidden();
});
