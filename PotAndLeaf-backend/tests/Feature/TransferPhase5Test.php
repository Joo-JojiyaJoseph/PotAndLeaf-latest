<?php

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Rental;
use App\Models\RentalInvoice;
use App\Models\StockTransfer;
use App\Services\RentalService;
use App\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'reports.view',
        'transfers.view',
        'transfers.create',
        'transfers.dispatch',
        'transfers.receive',
        'rental.view',
        'rental.create',
        'rental.activate',
        'rental.bill',
    ]);
});

it('returns transfer summary report for date range', function () {
    $dest = Company::create(['name' => 'Branch B', 'code' => 'BR'.Str::upper(Str::random(3)), 'is_active' => true]);
    $product = $this->createProduct();

    StockTransfer::create([
        'company_id'    => $this->company->id,
        'to_company_id' => $dest->id,
        'transfer_type' => 'inter_company',
        'transfer_no'   => 'TRF-000500',
        'transfer_date' => now()->subDays(2)->toDateString(),
        'status'        => 'received',
        'received_at'   => now()->subDay(),
    ]);

    $this->getJson('/api/reports/transfers/summary?'.http_build_query([
        'from' => now()->subDays(7)->toDateString(),
        'to' => now()->toDateString(),
    ]), $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.summary.total', 1)
        ->assertJsonPath('data.summary.received', 1)
        ->assertJsonPath('data.data.0.transfer_no', 'TRF-000500');
});

it('lists in-transit transfers in report', function () {
    $dest = Company::create(['name' => 'Branch C', 'code' => 'BC'.Str::upper(Str::random(3)), 'is_active' => true]);
    $product = $this->createProduct(['current_stock' => 50]);

    $transfer = StockTransfer::create([
        'company_id'    => $this->company->id,
        'to_company_id' => $dest->id,
        'transfer_type' => 'inter_company',
        'transfer_no'   => 'TRF-000501',
        'transfer_date' => now()->toDateString(),
        'status'        => 'draft',
    ]);
    $transfer->items()->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'qty' => 5,
        'received_qty' => 0,
    ]);

    app(TransferService::class)->dispatch($transfer->fresh(), $this->user->id);

    $this->getJson('/api/reports/transfers/in-transit', $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.transfer_no', 'TRF-000501')
        ->assertJsonPath('data.0.qty', 5);
});

it('denies transfer reports without permission', function () {
    $denied = \App\Models\User::factory()->create(['is_super_admin' => false, 'is_active' => true]);
    $denied->companies()->attach($this->company->id, ['is_default' => true]);

    $this->getJson('/api/reports/transfers/summary', $this->apiHeaders($denied))
        ->assertForbidden();
});

it('logs transfer dispatch to activity log', function () {
    $dest = Company::create(['name' => 'Branch D', 'code' => 'BD'.Str::upper(Str::random(3)), 'is_active' => true]);
    $product = $this->createProduct(['current_stock' => 20]);

    $transfer = StockTransfer::create([
        'company_id'    => $this->company->id,
        'to_company_id' => $dest->id,
        'transfer_type' => 'inter_company',
        'transfer_no'   => 'TRF-000502',
        'transfer_date' => now()->toDateString(),
        'status'        => 'draft',
    ]);
    $transfer->items()->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'qty' => 3,
        'received_qty' => 0,
    ]);

    app(TransferService::class)->dispatch($transfer->fresh(), $this->user->id);

    expect(ActivityLog::where('module', 'transfer')->where('action', 'dispatch')->exists())->toBeTrue();
});

it('logs rental activation to activity log', function () {
    $customer = $this->createCustomer();
    $location = $this->createLocation();
    $product = $this->createProduct(['current_stock' => 10]);

    $rental = Rental::create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'location_id' => $location->id,
        'rental_no' => 'RNT-000600',
        'start_date' => now()->toDateString(),
        'billing_cycle' => 'monthly',
        'deposit' => 500,
        'status' => 'draft',
    ]);
    $rental->items()->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'qty' => 2,
        'rate_per_cycle' => 100,
        'returned_qty' => 0,
    ]);

    app(RentalService::class)->activate($rental->fresh(), $this->user->id);

    expect(ActivityLog::where('module', 'rental')->where('action', 'activate')->exists())->toBeTrue();
});

it('exposes overdue display status on rental list API', function () {
    $customer = $this->createCustomer();
    $location = $this->createLocation();

    Rental::create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'location_id' => $location->id,
        'rental_no' => 'RNT-000601',
        'start_date' => now()->subDays(20)->toDateString(),
        'expected_end_date' => now()->subDays(2)->toDateString(),
        'billing_cycle' => 'monthly',
        'deposit' => 500,
        'status' => 'active',
        'activated_at' => now()->subDays(18),
    ]);

    $this->getJson('/api/rentals', $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.0.display_status', 'overdue')
        ->assertJsonPath('data.0.is_overdue', true);
});

it('includes rental and transfer stats on dashboard', function () {
    $dest = Company::create(['name' => 'Branch E', 'code' => 'BE'.Str::upper(Str::random(3)), 'is_active' => true]);
    $customer = $this->createCustomer();
    $location = $this->createLocation();

    StockTransfer::create([
        'company_id'    => $this->company->id,
        'to_company_id' => $dest->id,
        'transfer_type' => 'inter_company',
        'transfer_no'   => 'TRF-000503',
        'transfer_date' => now()->toDateString(),
        'status'        => 'in_transit',
        'dispatched_at' => now(),
    ]);

    $rental = Rental::create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'location_id' => $location->id,
        'rental_no' => 'RNT-000602',
        'start_date' => now()->subDays(10)->toDateString(),
        'expected_end_date' => now()->subDays(1)->toDateString(),
        'billing_cycle' => 'monthly',
        'deposit' => 500,
        'status' => 'active',
        'activated_at' => now()->subDays(8),
    ]);

    RentalInvoice::create([
        'company_id' => $this->company->id,
        'rental_id' => $rental->id,
        'invoice_no' => 'RINV-000700',
        'period_from' => now()->subDays(10)->toDateString(),
        'period_to' => now()->subDays(5)->toDateString(),
        'cycles' => 1,
        'amount' => 200,
        'due_date' => now()->subDays(2)->toDateString(),
        'status' => 'unpaid',
    ]);

    $this->getJson('/api/reports/dashboard?'.http_build_query([
        'from' => now()->subDays(29)->toDateString(),
        'to' => now()->toDateString(),
    ]), $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.transfers.in_transit', 1)
        ->assertJsonPath('data.rentals.overdue_returns', 1)
        ->assertJsonPath('data.rentals.payment_overdue', 1);
});
