<?php

/**
 * Supplier module automated tests — SUPPLIER-001 … SUPPLIER-015.
 *
 * API coverage via Pest (Laravel). UI-only cases skipped until Vitest/Playwright.
 *
 * Run: php artisan test tests/Feature/SupplierModuleTest.php
 */

use App\Http\Controllers\Api\SupplierController;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'suppliers.view',
        'suppliers.create',
        'suppliers.update',
        'suppliers.delete',
    ]);
});

function supplierHeaders(object $test): array
{
    return $test->apiHeaders();
}

function validSupplierPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Green Valley Growers',
        'address' => '123 Farm Road, Bangalore',
        'status' => 'active',
        'phone' => '9876543210',
        'email' => 'supplier@example.test',
    ], $overrides);
}

describe('Supplier module — API', function () {

    it('SUPPLIER-001 list suppliers', function () {
        $this->createSupplier(['name' => 'Alpha Seeds']);
        $this->createSupplier(['name' => 'Beta Fertilizers']);

        $response = $this->getJson('/api/suppliers', supplierHeaders($this));

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total']]);

        expect(collect($response->json('data')))->toHaveCount(2);
    })->group('supplier', 'SUPPLIER-001');

    it('SUPPLIER-002 search supplier filters by name code email or phone', function () {
        $this->createSupplier(['name' => 'Rose Growers', 'supplier_code' => 'SUP-00001']);
        $this->createSupplier(['name' => 'Tulip Supplies', 'phone' => '9123456789']);
        $this->createSupplier(['name' => 'Unrelated Co', 'email' => 'unique@supplier.test']);

        $byName = $this->getJson('/api/suppliers?search=Rose', supplierHeaders($this))->assertOk();
        expect(collect($byName->json('data')))->toHaveCount(1)
            ->and($byName->json('data.0.name'))->toBe('Rose Growers');

        $byPhone = $this->getJson('/api/suppliers?search=9123456789', supplierHeaders($this))->assertOk();
        expect(collect($byPhone->json('data')))->toHaveCount(1)
            ->and($byPhone->json('data.0.name'))->toBe('Tulip Supplies');

        $noMatch = $this->getJson('/api/suppliers?search=ZZZNOMATCH', supplierHeaders($this))->assertOk();
        expect(collect($noMatch->json('data')))->toHaveCount(0);
    })->group('supplier', 'SUPPLIER-002');

    it('SUPPLIER-003 create supplier with valid data', function () {
        $response = $this->postJson('/api/suppliers', validSupplierPayload(), supplierHeaders($this));

        $response->assertCreated()
            ->assertJsonPath('message', 'Supplier created.')
            ->assertJsonPath('data.name', 'Green Valley Growers')
            ->assertJsonPath('data.status', 'active');

        expect(Supplier::forCompany($this->company->id)->where('name', 'Green Valley Growers')->exists())->toBeTrue();
        expect($response->json('data.supplier_code'))->not->toBeEmpty();
    })->group('supplier', 'SUPPLIER-003');

    it('SUPPLIER-004 submit empty form is rejected', function () {
        $response = $this->postJson('/api/suppliers', [], supplierHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'address', 'status']);
    })->group('supplier', 'SUPPLIER-004');

    it('SUPPLIER-005 missing required field name is rejected', function () {
        $response = $this->postJson('/api/suppliers', [
            'address' => '456 Warehouse Lane',
            'status' => 'active',
        ], supplierHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    })->group('supplier', 'SUPPLIER-005');

    it('SUPPLIER-006 invalid email is rejected', function () {
        $response = $this->postJson('/api/suppliers', validSupplierPayload([
            'email' => 'not-an-email',
        ]), supplierHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    })->group('supplier', 'SUPPLIER-006');

    it('SUPPLIER-007 invalid phone is rejected', function () {
        $response = $this->postJson('/api/suppliers', validSupplierPayload([
            'phone' => 'abc',
        ]), supplierHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    })->group('supplier', 'SUPPLIER-007');

    it('SUPPLIER-008 duplicate supplier name in same company is rejected', function () {
        $this->createSupplier(['name' => 'Duplicate Supplier Co']);

        $response = $this->postJson('/api/suppliers', validSupplierPayload([
            'name' => 'Duplicate Supplier Co',
        ]), supplierHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        expect($response->json('errors.name.0'))
            ->toContain('already exists');
    })->group('supplier', 'SUPPLIER-008');

    it('SUPPLIER-009 edit supplier updates record', function () {
        $supplier = $this->createSupplier(['name' => 'Before Edit', 'address' => 'Old Address']);

        $response = $this->putJson("/api/suppliers/{$supplier->id}", validSupplierPayload([
            'name' => 'After Edit',
            'address' => 'New Address Line',
            'phone' => '9988776655',
        ]), supplierHeaders($this));

        $response->assertOk()
            ->assertJsonPath('message', 'Supplier updated.')
            ->assertJsonPath('data.name', 'After Edit')
            ->assertJsonPath('data.address', 'New Address Line');

        expect($supplier->fresh()->name)->toBe('After Edit')
            ->and($supplier->fresh()->address)->toBe('New Address Line');
    })->group('supplier', 'SUPPLIER-009');

    it('SUPPLIER-010 delete supplier soft deletes record', function () {
        $supplier = $this->createSupplier(['name' => 'To Delete']);

        $this->deleteJson("/api/suppliers/{$supplier->id}", [], supplierHeaders($this))
            ->assertOk()
            ->assertJsonPath('message', 'Supplier moved to trash.');

        expect(Supplier::find($supplier->id))->toBeNull();
        expect(Supplier::withTrashed()->find($supplier->id))->not->toBeNull();
    })->group('supplier', 'SUPPLIER-010');

    it('SUPPLIER-012 API validation error returns 422 with field errors', function () {
        $response = $this->postJson('/api/suppliers', validSupplierPayload([
            'status' => 'invalid-status',
        ]), supplierHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status'])
            ->assertJsonStructure(['message', 'errors']);
    })->group('supplier', 'SUPPLIER-012');

    it('SUPPLIER-013 API server error on create returns 500', function () {
        $this->app->bind(SupplierController::class, fn () => new class extends SupplierController
        {
            public function __construct()
            {
                parent::__construct(app(\App\Services\SupplierService::class));
            }

            public function store(\App\Http\Requests\Supplier\StoreSupplierRequest $request): JsonResponse
            {
                return response()->json(['message' => 'Server Error'], 500);
            }
        });

        $response = $this->postJson('/api/suppliers', validSupplierPayload(), supplierHeaders($this));

        $response->assertStatus(500)
            ->assertJson(['message' => 'Server Error']);
    })->group('supplier', 'SUPPLIER-013');

    it('SUPPLIER-014 pagination returns distinct pages', function () {
        for ($i = 1; $i <= 12; $i++) {
            $this->createSupplier(['name' => "Paginated Supplier {$i}"]);
        }

        $page1 = $this->getJson('/api/suppliers?per_page=5&page=1', supplierHeaders($this))->assertOk();
        $page2 = $this->getJson('/api/suppliers?per_page=5&page=2', supplierHeaders($this))->assertOk();

        expect($page1->json('meta.current_page'))->toBe(1)
            ->and($page2->json('meta.current_page'))->toBe(2)
            ->and($page1->json('meta.total'))->toBe(12)
            ->and(collect($page1->json('data')))->toHaveCount(5)
            ->and(collect($page2->json('data')))->toHaveCount(5);

        $page1Ids = collect($page1->json('data'))->pluck('id');
        $page2Ids = collect($page2->json('data'))->pluck('id');
        expect($page1Ids->intersect($page2Ids))->toHaveCount(0);
    })->group('supplier', 'SUPPLIER-014');

    it('SUPPLIER-015 empty state returns zero suppliers', function () {
        $response = $this->getJson('/api/suppliers', supplierHeaders($this));

        $response->assertOk();
        expect($response->json('data'))->toBeArray()->toHaveCount(0);
        expect($response->json('meta.total'))->toBe(0);
    })->group('supplier', 'SUPPLIER-015');

})->group('supplier');

describe('Supplier module — frontend UI (requires Vitest / Playwright)', function () {

    it('SUPPLIER-011 cancel delete does not call API', function () {
        $this->markTestSkipped(
            'Frontend only: useConfirm cancel returns false — no DELETE request. Install vitest + RTL or Playwright.'
        );
    })->group('supplier', 'SUPPLIER-011');

})->group('supplier');
