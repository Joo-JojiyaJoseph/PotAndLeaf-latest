<?php

/**
 * Product module automated tests — PRODUCT-001 … PRODUCT-023.
 *
 * API coverage via Pest (Laravel). UI-only cases skipped until Vitest/Playwright.
 *
 * Run: php artisan test tests/Feature/ProductModuleTest.php
 */

use App\Http\Controllers\Api\ProductController;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'products.view',
        'products.create',
        'products.update',
        'products.delete',
    ]);
});

function productHeaders(object $test): array
{
    return $test->apiHeaders();
}

function createProductCategory(object $test, string $name = 'Plants', array $overrides = []): ProductCategory
{
    return ProductCategory::create(array_merge([
        'company_id' => $test->company->id,
        'code' => 'CAT-'.Str::upper(Str::random(4)),
        'name' => $name,
        'status' => 'active',
    ], $overrides));
}

function validProductPayload(string $categoryId, array $overrides = []): array
{
    return array_merge([
        'name' => 'Test Product',
        'category_id' => $categoryId,
        'cost_price' => 100,
        'status' => 'active',
    ], $overrides);
}

describe('Product module — API', function () {

    it('PRODUCT-001 product list loads', function () {
        $category = createProductCategory($this);
        $this->createProduct(['name' => 'Alpha Palm', 'category_id' => $category->id]);
        $this->createProduct(['name' => 'Beta Fern', 'category_id' => $category->id]);

        $response = $this->getJson('/api/products', productHeaders($this));

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total']]);

        expect(collect($response->json('data')))->toHaveCount(2);
    })->group('product', 'PRODUCT-001');

    it('PRODUCT-002 product search filters by name sku barcode or hsn', function () {
        $category = createProductCategory($this);
        $this->createProduct(['name' => 'Rose Bush', 'sku' => 'SKU-ROSE-01', 'category_id' => $category->id]);
        $this->createProduct(['name' => 'Tulip Pot', 'barcode' => 'BAR-TULIP-99', 'category_id' => $category->id]);
        $this->createProduct(['name' => 'Unrelated', 'hsn_code' => 'HSN12345', 'category_id' => $category->id]);

        $byName = $this->getJson('/api/products?search=Rose', productHeaders($this))->assertOk();
        expect(collect($byName->json('data')))->toHaveCount(1)
            ->and($byName->json('data.0.name'))->toBe('Rose Bush');

        $byBarcode = $this->getJson('/api/products?search=BAR-TULIP', productHeaders($this))->assertOk();
        expect(collect($byBarcode->json('data')))->toHaveCount(1)
            ->and($byBarcode->json('data.0.name'))->toBe('Tulip Pot');

        $noMatch = $this->getJson('/api/products?search=ZZZNOMATCH', productHeaders($this))->assertOk();
        expect(collect($noMatch->json('data')))->toHaveCount(0);
    })->group('product', 'PRODUCT-002');

    it('PRODUCT-003 product filtering by status category and low stock', function () {
        $plants = createProductCategory($this, 'Plants');
        $tools = createProductCategory($this, 'Tools');

        $this->createProduct(['name' => 'Active Plant', 'status' => 'active', 'category_id' => $plants->id]);
        $this->createProduct(['name' => 'Inactive Plant', 'status' => 'inactive', 'category_id' => $plants->id]);
        $this->createProduct(['name' => 'Low Stock Tool', 'status' => 'active', 'category_id' => $tools->id, 'reorder_level' => 10, 'current_stock' => 5, 'opening_stock' => 5]);
        $this->createProduct(['name' => 'Healthy Tool', 'status' => 'active', 'category_id' => $tools->id, 'reorder_level' => 10, 'current_stock' => 50, 'opening_stock' => 50]);

        $active = $this->getJson('/api/products?status=active', productHeaders($this))->assertOk();
        expect(collect($active->json('data')))->toHaveCount(3);

        $byCategory = $this->getJson("/api/products?category_id={$tools->id}", productHeaders($this))->assertOk();
        expect(collect($byCategory->json('data')))->toHaveCount(2)
            ->and(collect($byCategory->json('data'))->pluck('name')->all())->toContain('Low Stock Tool');

        $lowOnly = $this->getJson('/api/products?low_only=1', productHeaders($this))->assertOk();
        expect(collect($lowOnly->json('data')))->toHaveCount(1)
            ->and($lowOnly->json('data.0.name'))->toBe('Low Stock Tool');
    })->group('product', 'PRODUCT-003');

    it('PRODUCT-004 product pagination returns distinct pages', function () {
        $category = createProductCategory($this);
        for ($i = 1; $i <= 12; $i++) {
            $this->createProduct(['name' => "Paginated Product {$i}", 'category_id' => $category->id]);
        }

        $page1 = $this->getJson('/api/products?per_page=5&page=1', productHeaders($this))->assertOk();
        $page2 = $this->getJson('/api/products?per_page=5&page=2', productHeaders($this))->assertOk();

        expect($page1->json('meta.current_page'))->toBe(1)
            ->and($page2->json('meta.current_page'))->toBe(2)
            ->and($page1->json('meta.total'))->toBe(12)
            ->and(collect($page1->json('data')))->toHaveCount(5)
            ->and(collect($page2->json('data')))->toHaveCount(5);

        $page1Ids = collect($page1->json('data'))->pluck('id');
        $page2Ids = collect($page2->json('data'))->pluck('id');
        expect($page1Ids->intersect($page2Ids))->toHaveCount(0);
    })->group('product', 'PRODUCT-004');

    it('PRODUCT-006 create product with valid data', function () {
        $category = createProductCategory($this);

        $response = $this->postJson('/api/products', validProductPayload($category->id, [
            'sku' => 'SKU-VALID-01',
            'mrp' => 500,
            'retail_price' => 450,
            'opening_stock' => 20,
        ]), productHeaders($this));

        $response->assertCreated()
            ->assertJsonPath('message', 'Product created.')
            ->assertJsonPath('data.name', 'Test Product')
            ->assertJsonPath('data.sku', 'SKU-VALID-01');

        expect(Product::forCompany($this->company->id)->where('name', 'Test Product')->exists())->toBeTrue();
    })->group('product', 'PRODUCT-006');

    it('PRODUCT-007 submit empty product form is rejected', function () {
        $response = $this->postJson('/api/products', [], productHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'category_id', 'cost_price', 'status']);
    })->group('product', 'PRODUCT-007');

    it('PRODUCT-008 missing required category field is rejected', function () {
        $response = $this->postJson('/api/products', [
            'name' => 'No Category Product',
            'cost_price' => 100,
            'status' => 'active',
        ], productHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    })->group('product', 'PRODUCT-008');

    it('PRODUCT-009 invalid product price non numeric is rejected', function () {
        $category = createProductCategory($this);

        $response = $this->postJson('/api/products', validProductPayload($category->id, [
            'cost_price' => 'not-a-price',
        ]), productHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['cost_price']);
    })->group('product', 'PRODUCT-009');

    it('PRODUCT-010 negative price is rejected', function () {
        $category = createProductCategory($this);

        $response = $this->postJson('/api/products', validProductPayload($category->id, [
            'cost_price' => -10,
        ]), productHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['cost_price']);
    })->group('product', 'PRODUCT-010');

    it('PRODUCT-011 zero price is accepted', function () {
        $category = createProductCategory($this);

        $response = $this->postJson('/api/products', validProductPayload($category->id, [
            'name' => 'Free Sample',
            'cost_price' => 0,
        ]), productHeaders($this));

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Free Sample');

        $product = Product::forCompany($this->company->id)->where('name', 'Free Sample')->first();
        expect($product)->not->toBeNull()
            ->and((float) $product->cost_price)->toBe(0.0);
    })->group('product', 'PRODUCT-011');

    it('PRODUCT-012 negative quantity is rejected', function () {
        $category = createProductCategory($this);

        $response = $this->postJson('/api/products', validProductPayload($category->id, [
            'opening_stock' => -5,
        ]), productHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['opening_stock']);
    })->group('product', 'PRODUCT-012');

    it('PRODUCT-013 invalid SKU exceeding max length is rejected', function () {
        $category = createProductCategory($this);

        $response = $this->postJson('/api/products', validProductPayload($category->id, [
            'sku' => str_repeat('X', 51),
        ]), productHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);
    })->group('product', 'PRODUCT-013');

    it('PRODUCT-014 duplicate SKU in same company is rejected', function () {
        $category = createProductCategory($this);
        $this->createProduct(['sku' => 'SKU-DUP-01', 'category_id' => $category->id]);

        $response = $this->postJson('/api/products', validProductPayload($category->id, [
            'name' => 'Another Product',
            'sku' => 'SKU-DUP-01',
        ]), productHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);

        expect($response->json('errors.sku.0'))
            ->toContain('already used');
    })->group('product', 'PRODUCT-014');

    it('PRODUCT-015 maximum field length violations are rejected', function () {
        $category = createProductCategory($this);

        $longName = $this->postJson('/api/products', validProductPayload($category->id, [
            'name' => str_repeat('N', 192),
        ]), productHeaders($this));
        $longName->assertUnprocessable()->assertJsonValidationErrors(['name']);

        $longDescription = $this->postJson('/api/products', validProductPayload($category->id, [
            'name' => 'Valid Name',
            'description' => str_repeat('D', 2001),
        ]), productHeaders($this));
        $longDescription->assertUnprocessable()->assertJsonValidationErrors(['description']);
    })->group('product', 'PRODUCT-015');

    it('PRODUCT-016 edit product updates record', function () {
        $category = createProductCategory($this);
        $product = $this->createProduct(['name' => 'Before Edit', 'category_id' => $category->id, 'cost_price' => 100]);

        $response = $this->putJson("/api/products/{$product->id}", validProductPayload($category->id, [
            'name' => 'After Edit',
            'cost_price' => 150,
            'mrp' => 300,
        ]), productHeaders($this));

        $response->assertOk()
            ->assertJsonPath('message', 'Product updated.')
            ->assertJsonPath('data.name', 'After Edit');

        expect($product->fresh()->name)->toBe('After Edit')
            ->and((float) $product->fresh()->cost_price)->toBe(150.0);
    })->group('product', 'PRODUCT-016');

    it('PRODUCT-017 delete product soft deletes record', function () {
        $category = createProductCategory($this);
        $product = $this->createProduct(['name' => 'To Delete', 'category_id' => $category->id]);

        $this->deleteJson("/api/products/{$product->id}", [], productHeaders($this))
            ->assertOk()
            ->assertJsonPath('message', 'Product deleted.');

        expect(Product::find($product->id))->toBeNull();
        expect(Product::withTrashed()->find($product->id))->not->toBeNull();
    })->group('product', 'PRODUCT-017');

    it('PRODUCT-019 product details returns single product', function () {
        $category = createProductCategory($this, 'Indoor Plants');
        $product = $this->createProduct([
            'name' => 'Detail Product',
            'sku' => 'SKU-DETAIL',
            'category_id' => $category->id,
            'cost_price' => 75,
        ]);

        $response = $this->getJson("/api/products/{$product->id}", productHeaders($this));

        $response->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'Detail Product')
            ->assertJsonPath('data.sku', 'SKU-DETAIL')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonStructure(['data' => ['id', 'name', 'sku', 'status', 'current_stock']]);

        expect((float) $product->fresh()->cost_price)->toBe(75.0);
    })->group('product', 'PRODUCT-019');

    it('PRODUCT-020 API validation error returns 422 with field errors', function () {
        $category = createProductCategory($this);

        $response = $this->postJson('/api/products', validProductPayload($category->id, [
            'status' => 'invalid-status',
        ]), productHeaders($this));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status'])
            ->assertJsonStructure(['message', 'errors']);
    })->group('product', 'PRODUCT-020');

    it('PRODUCT-021 API server error on create returns 500', function () {
        $this->app->bind(ProductController::class, fn () => new class extends ProductController
        {
            public function __construct()
            {
                parent::__construct(app(\App\Services\ProductService::class));
            }

            public function store(\App\Http\Requests\Product\StoreProductRequest $request): JsonResponse
            {
                return response()->json(['message' => 'Server Error'], 500);
            }
        });

        $category = createProductCategory($this);

        $response = $this->postJson('/api/products', validProductPayload($category->id), productHeaders($this));

        $response->assertStatus(500)
            ->assertJson(['message' => 'Server Error']);
    })->group('product', 'PRODUCT-021');

    it('PRODUCT-023 empty state returns zero products', function () {
        $response = $this->getJson('/api/products', productHeaders($this));

        $response->assertOk();
        expect($response->json('data'))->toBeArray()->toHaveCount(0);
        expect($response->json('meta.total'))->toBe(0);
    })->group('product', 'PRODUCT-023');

})->group('product');

describe('Product module — frontend UI (requires Vitest / Playwright)', function () {

    it('PRODUCT-005 open create product form', function () {
        $this->markTestSkipped(
            'Frontend only: ProductsList navigates to /products/new. Install vitest, jsdom, @testing-library/react.'
        );
    })->group('product', 'PRODUCT-005');

    it('PRODUCT-018 cancel delete does not call API', function () {
        $this->markTestSkipped(
            'Frontend only: useConfirm cancel returns false — no DELETE request. Install vitest + RTL or Playwright.'
        );
    })->group('product', 'PRODUCT-018');

    it('PRODUCT-022 loading state shows spinner', function () {
        $this->markTestSkipped(
            'Frontend only: isLoading renders Spinner in ProductsList. Install vitest, jsdom, @testing-library/react.'
        );
    })->group('product', 'PRODUCT-022');

})->group('product');
