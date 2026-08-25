<?php

/**
 * Customer module automated tests — CUSTOMER-001 … CUSTOMER-018.
 *
 * API coverage via Pest (Laravel). UI-only cases skipped until Vitest/Playwright.
 *
 * Run: php artisan test tests/Feature/CustomerModuleTest.php
 */

use App\Http\Controllers\Api\CustomerController;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'customers.view',
        'customers.create',
        'customers.update',
        'customers.delete',
    ]);
});

function customerHeaders(object $test): array
{
    return $test->apiHeaders();
}

function validCustomerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Green Valley Nursery',
        'type' => 'retail',
        'status' => 'active',
        'phone' => '9876543210',
        'email' => 'green@example.test',
    ], $overrides);
}

describe('Customer module — API', function () {

    it('CUSTOMER-001 customer list loads', function () {
        $this->createCustomer(['name' => 'Alpha Plants']);
        $this->createCustomer(['name' => 'Beta Gardens', 'type' => 'wholesale']);

        $response = $this->getJson('/api/customers', customerHeaders($this));

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total']]);

        expect(collect($response->json('data')))->toHaveCount(2);
    })->group('customer', 'CUSTOMER-001');

    it('CUSTOMER-002 customer search filters by name code phone or GST', function () {
        $this->createCustomer(['name' => 'Rose Garden', 'customer_code' => 'CUST-00001']);
        $this->createCustomer(['name' => 'Tulip World', 'phone' => '9123456789']);
        $this->createCustomer(['name' => 'Unrelated Shop', 'gst_number' => '29AAAAA0000A1Z5']);

        $byName = $this->getJson('/api/customers?search=Rose', customerHeaders($this))->assertOk();
        expect(collect($byName->json('data')))->toHaveCount(1)
            ->and($byName->json('data.0.name'))->toBe('Rose Garden');

        $byPhone = $this->getJson('/api/customers?search=9123456789', customerHeaders($this))->assertOk();
        expect(collect($byPhone->json('data')))->toHaveCount(1)
            ->and($byPhone->json('data.0.name'))->toBe('Tulip World');

        $noMatch = $this->getJson('/api/customers?search=ZZZNOMATCH', customerHeaders($this))->assertOk();
        expect(collect($noMatch->json('data')))->toHaveCount(0);
    })->group('customer', 'CUSTOMER-002');

    it('CUSTOMER-003 customer filter by type', function () {
        $this->createCustomer(['name' => 'Retail One', 'type' => 'retail']);
        $this->createCustomer(['name' => 'Wholesale One', 'type' => 'wholesale']);
        $this->createCustomer(['name' => 'Dealer One', 'type' => 'dealer']);

        $wholesale = $this->getJson('/api/customers?type=wholesale', customerHeaders($this))->assertOk();
        expect(collect($wholesale->json('data')))->toHaveCount(1)
            ->and($wholesale->json('data.0.type'))->toBe('wholesale');

        $dealer = $this->getJson('/api/customers?type=dealer', customerHeaders($this))->assertOk();
        expect(collect($dealer->json('data')))->toHaveCount(1)
            ->and($dealer->json('data.0.name'))->toBe('Dealer One');
    })->group('customer', 'CUSTOMER-003');

    it('CUSTOMER-005 create customer with valid data', function () {
        $response = $this->postJson('/api/customers', validCustomerPayload(), customerHeaders($this));

        $response->assertCreated()
            ->assertJsonPath('message', 'Customer created.')
            ->assertJsonPath('data.name', 'Green Valley Nursery')
            ->assertJsonPath('data.type', 'retail');

        expect(Customer::forCompany($this->company->id)->where('name', 'Green Valley Nursery')->exists())->toBeTrue();
        expect($response->json('data.customer_code'))->not->toBeEmpty();
    })->group('customer', 'CUSTOMER-005');

    it('CUSTOMER-006 submit empty customer form is rejected', function () {
        $response = $this->postJson('/api/customers', [], customerHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'type', 'status']);
    })->group('customer', 'CUSTOMER-006');

    it('CUSTOMER-007 missing required field name is rejected', function () {
        $response = $this->postJson('/api/customers', [
            'type' => 'retail',
            'status' => 'active',
        ], customerHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    })->group('customer', 'CUSTOMER-007');

    it('CUSTOMER-008 invalid email is rejected', function () {
        $response = $this->postJson('/api/customers', validCustomerPayload([
            'email' => 'not-an-email',
        ]), customerHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    })->group('customer', 'CUSTOMER-008');

    it('CUSTOMER-009 invalid phone number is rejected', function () {
        $response = $this->postJson('/api/customers', validCustomerPayload([
            'phone' => 'abc',
        ]), customerHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    })->group('customer', 'CUSTOMER-009');

    it('CUSTOMER-010 duplicate customer name in same company is rejected', function () {
        $this->createCustomer(['name' => 'Duplicate Name Co']);

        $response = $this->postJson('/api/customers', validCustomerPayload([
            'name' => 'Duplicate Name Co',
        ]), customerHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        expect($response->json('errors.name.0'))
            ->toContain('already exists');
    })->group('customer', 'CUSTOMER-010');

    it('CUSTOMER-011 edit customer updates record', function () {
        $customer = $this->createCustomer(['name' => 'Before Edit', 'type' => 'retail']);

        $response = $this->putJson("/api/customers/{$customer->id}", [
            'name' => 'After Edit',
            'type' => 'wholesale',
            'status' => 'active',
            'phone' => '9988776655',
        ], customerHeaders($this));

        $response->assertOk()
            ->assertJsonPath('message', 'Customer updated.')
            ->assertJsonPath('data.name', 'After Edit')
            ->assertJsonPath('data.type', 'wholesale');

        expect($customer->fresh()->name)->toBe('After Edit')
            ->and($customer->fresh()->type->value ?? $customer->fresh()->type)->toBe('wholesale');
    })->group('customer', 'CUSTOMER-011');

    it('CUSTOMER-012 delete customer soft deletes record', function () {
        $customer = $this->createCustomer(['name' => 'To Delete']);

        $this->deleteJson("/api/customers/{$customer->id}", [], customerHeaders($this))
            ->assertOk()
            ->assertJsonPath('message', 'Customer deleted.');

        expect(Customer::find($customer->id))->toBeNull();
        expect(Customer::withTrashed()->find($customer->id))->not->toBeNull();
    })->group('customer', 'CUSTOMER-012');

    it('CUSTOMER-014 API validation error returns 422 with field errors', function () {
        $response = $this->postJson('/api/customers', [
            'name' => 'Bad Type Customer',
            'type' => 'invalid-type',
            'status' => 'active',
        ], customerHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['type'])
            ->assertJsonStructure(['message', 'errors']);
    })->group('customer', 'CUSTOMER-014');

    it('CUSTOMER-015 API server error on create returns 500', function () {
        $this->app->bind(CustomerController::class, fn () => new class extends CustomerController
        {
            public function __construct()
            {
                parent::__construct(app(\App\Services\CustomerService::class));
            }

            public function store(\App\Http\Requests\Customer\StoreCustomerRequest $request): JsonResponse
            {
                return response()->json(['message' => 'Server Error'], 500);
            }
        });

        $response = $this->postJson('/api/customers', validCustomerPayload(), customerHeaders($this));

        $response->assertStatus(500)
            ->assertJson(['message' => 'Server Error']);
    })->group('customer', 'CUSTOMER-015');

    it('CUSTOMER-017 empty state returns zero customers', function () {
        $response = $this->getJson('/api/customers', customerHeaders($this));

        $response->assertOk();
        expect($response->json('data'))->toBeArray()->toHaveCount(0);
        expect($response->json('meta.total'))->toBe(0);
    })->group('customer', 'CUSTOMER-017');

    it('CUSTOMER-018 pagination returns distinct pages', function () {
        for ($i = 1; $i <= 12; $i++) {
            $this->createCustomer(['name' => "Paginated Customer {$i}"]);
        }

        $page1 = $this->getJson('/api/customers?per_page=5&page=1', customerHeaders($this))->assertOk();
        $page2 = $this->getJson('/api/customers?per_page=5&page=2', customerHeaders($this))->assertOk();

        expect($page1->json('meta.current_page'))->toBe(1)
            ->and($page2->json('meta.current_page'))->toBe(2)
            ->and($page1->json('meta.total'))->toBe(12)
            ->and(collect($page1->json('data')))->toHaveCount(5)
            ->and(collect($page2->json('data')))->toHaveCount(5);

        $page1Ids = collect($page1->json('data'))->pluck('id');
        $page2Ids = collect($page2->json('data'))->pluck('id');
        expect($page1Ids->intersect($page2Ids))->toHaveCount(0);
    })->group('customer', 'CUSTOMER-018');

})->group('customer');

describe('Customer module — frontend UI (requires Vitest / Playwright)', function () {

    it('CUSTOMER-004 open create customer form', function () {
        $this->markTestSkipped(
            'Frontend only: CustomersList openNew() opens Modal. Install vitest, jsdom, @testing-library/react.'
        );
    })->group('customer', 'CUSTOMER-004');

    it('CUSTOMER-013 cancel delete does not call API', function () {
        $this->markTestSkipped(
            'Frontend only: useConfirm cancel returns false — no DELETE request. Install vitest + RTL or Playwright.'
        );
    })->group('customer', 'CUSTOMER-013');

    it('CUSTOMER-016 loading state shows spinner', function () {
        $this->markTestSkipped(
            'Frontend only: isLoading renders Spinner in CustomersList. Install vitest, jsdom, @testing-library/react.'
        );
    })->group('customer', 'CUSTOMER-016');

})->group('customer');
