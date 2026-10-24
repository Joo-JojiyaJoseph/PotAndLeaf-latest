<?php

/**
 * 24 Oct 2026: one location per company, and the commission payout
 * breakdown (calculation unchanged).
 *
 * Run: php artisan test tests/Feature/OneLocationAndCommissionTest.php
 */

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Location;
use App\Models\LocationStock;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser(['locations.view', 'locations.manage', 'sales.view', 'sales.create', 'sales.confirm', 'commission.view']);
});

it('creates exactly one location with every new company', function () {
    $company = Company::create(['name' => 'Kottayam Nursery', 'code' => 'KTM'.Str::upper(Str::random(3)), 'is_active' => true]);

    $locations = Location::where('company_id', $company->id)->get();
    expect($locations)->toHaveCount(1)
        ->and($locations[0]->name)->toBe('Kottayam Nursery')
        ->and($locations[0]->is_default)->toBeTrue()
        ->and($locations[0]->is_active)->toBeTrue();

    // Calling it again never makes a second one.
    $company->ensureMainLocation();
    expect(Location::where('company_id', $company->id)->count())->toBe(1);
});

it('does not let anyone add a second location or delete the only one', function () {
    $main = $this->company->ensureMainLocation();

    $this->postJson('/api/locations', ['name' => 'Godown 2', 'code' => 'G2', 'type' => 'godown', 'is_active' => true], $this->apiHeaders())
        ->assertStatus(422)
        ->assertJsonPath('message', 'Each company has one location, created with the company. Rename it instead of adding another.');

    $this->deleteJson("/api/locations/{$main->id}", [], $this->apiHeaders())->assertStatus(422);
    expect(Location::where('company_id', $this->company->id)->count())->toBe(1);
});

it('merges old extra locations into the main one without losing stock', function () {
    $main = $this->company->ensureMainLocation();
    $godown = $this->createLocation(['name' => 'Old godown', 'is_default' => false]);
    $product = $this->createProduct(['current_stock' => 30]);
    $other = $this->createProduct(['current_stock' => 5]);
    LocationStock::create(['company_id' => $this->company->id, 'location_id' => $main->id, 'product_id' => $product->id, 'qty' => 10]);
    LocationStock::create(['company_id' => $this->company->id, 'location_id' => $godown->id, 'product_id' => $product->id, 'qty' => 20]);
    LocationStock::create(['company_id' => $this->company->id, 'location_id' => $godown->id, 'product_id' => $other->id, 'qty' => 5]);

    (require base_path('database/migrations/2026_10_24_000001_one_location_per_company.php'))->up();

    expect(Location::where('company_id', $this->company->id)->where('is_active', true)->pluck('id')->all())->toBe([$main->id])
        ->and((float) LocationStock::where('location_id', $main->id)->where('product_id', $product->id)->value('qty'))->toBe(30.0)
        ->and((float) LocationStock::where('location_id', $main->id)->where('product_id', $other->id)->value('qty'))->toBe(5.0)
        ->and(LocationStock::where('location_id', $godown->id)->count())->toBe(0);
});

it('keeps the existing payout calculation and labels which bonus it contains', function () {
    CommissionRule::create([
        'company_id' => $this->company->id, 'user_id' => $this->user->id, 'base_percent' => 2,
        'monthly_target' => 1000, 'target_bonus' => 500, 'is_active' => true, 'is_supervisor' => false,
    ]);
    $product = $this->createProduct(['current_stock' => 10, 'retail_price' => 750, 'gst_rate' => 0]);
    $sale = app(CreateSale::class)->handle($this->company->id, [
        'sale_date' => now()->toDateString(), 'payment_mode' => 'cash', 'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 2, 'rate' => 750, 'discount' => 0, 'gst_rate' => 0]],
    ], $this->user->id);
    app(ConfirmSale::class)->handle($sale, $this->user->id);

    $c = app(CommissionService::class)->compute($this->company->id, $this->user->id, now()->format('Y-m'));

    // Same as before: commission is recorded per bill (2% of ₹1,500 = ₹30); with
    // per-bill records the bonus figure is the daily-target bonus (none set here).
    expect($c['has_ledger'])->toBeTrue()
        ->and($c['base_amount'])->toBe(30.0)
        ->and($c['target_met'])->toBeTrue()
        ->and($c['bonus'])->toBe(0.0)
        ->and($c['monthly_target_bonus'])->toBe(0.0)
        ->and($c['daily_target_bonus'])->toBe(0.0)
        ->and($c['commission'])->toBe(30.0);
});

it('refuses to delete a company that has data, but deletes an empty one', function () {
    $this->user->forceFill(['is_super_admin' => true])->save();
    app('auth')->forgetGuards();
    $h = $this->apiHeaders();

    $busy = Company::create(['name' => 'Busy Nursery', 'code' => 'BSY'.Str::upper(Str::random(3)), 'is_active' => true]);
    $this->createProduct(['company_id' => $busy->id]);
    $this->createCustomer(['company_id' => $busy->id]);

    $this->deleteJson("/api/companies/{$busy->id}", [], $h)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Busy Nursery cannot be deleted because it has 1 products, 1 customers. Make it Inactive instead — its records stay safe.');
    expect(Company::find($busy->id))->not->toBeNull();

    $empty = Company::create(['name' => 'Empty Nursery', 'code' => 'EMP'.Str::upper(Str::random(3)), 'is_active' => true]);
    $this->deleteJson("/api/companies/{$empty->id}", [], $h)->assertOk();
    expect(Company::find($empty->id))->toBeNull();
});
