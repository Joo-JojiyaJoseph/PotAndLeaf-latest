<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

it('rejects a subcategory under an inactive category', function () {
    $this->createCompanyWithUser(['subcategories.create', 'categories.view']);
    $parent = ProductCategory::create([
        'company_id' => $this->company->id,
        'code' => 'CAT-OFF',
        'name' => 'Retired',
        'status' => 'inactive',
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->postJson('/api/masters/categories', [
            'name' => 'Child',
            'parent_id' => $parent->id,
            'status' => 'active',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_id']);
});

it('blocks writes against an inactive company', function () {
    $this->createCompanyWithUser(['categories.create']);
    $this->company->update(['is_active' => false]);

    $this->actingAs($this->user)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->postJson('/api/masters/categories', ['name' => 'Nope', 'status' => 'active'])
        ->assertStatus(422);
});

it('does not let a company user pick another company on a transfer request', function () {
    $this->createCompanyWithUser(['transfers.create', 'transfers.view']);
    $other = Company::create(['name' => 'Other', 'code' => 'OTH1', 'is_active' => true]);
    $product = $this->createProduct(['company_id' => $other->id, 'current_stock' => 5]);

    $this->actingAs($this->user)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->postJson('/api/transfers', [
            'from_company_id' => $other->id,
            'to_company_id' => $this->company->id,
            'transfer_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'qty' => 1]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['from_company_id']);
});

it('refuses to delete a product that already has a batch', function () {
    $this->createCompanyWithUser(['products.delete']);
    $product = $this->createProduct();
    \App\Models\ProductBatch::create([
        'company_id' => $this->company->id,
        'product_id' => $product->id,
        'batch_no' => 'B-1',
        'barcode' => 'BC1',
        'qty' => 1,
        'remaining_qty' => 1,
        'cost_price' => 10,
        'status' => 'active',
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->deleteJson('/api/products/'.$product->id)
        ->assertUnprocessable();

    expect(Product::find($product->id))->not->toBeNull();
});

it('uses Asia/Kolkata as the application timezone', function () {
    expect(config('app.timezone'))->toBe('Asia/Kolkata');
});

it('keeps inactive products out of the default inventory list and shows draft transfer quantity separately', function () {
    $this->createCompanyWithUser(['inventory.view', 'transfers.create', 'products.view']);
    $active = $this->createProduct(['name' => 'Live Plant', 'sku' => 'LIVE-1', 'current_stock' => 12, 'status' => 'active']);
    $inactive = $this->createProduct(['name' => 'Retired Plant', 'sku' => 'RET-1', 'current_stock' => 4, 'status' => 'inactive']);

    $this->actingAs($this->user)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->postJson('/api/transfers', [
            'transfer_date' => now()->toDateString(),
            'items' => [['product_id' => $active->id, 'qty' => 3]],
        ])->assertCreated();

    $stock = $this->actingAs($this->user)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->getJson('/api/inventory/stock')
        ->assertOk();

    $rows = collect($stock->json('data'));
    expect($rows->pluck('id'))->toContain($active->id)->not->toContain($inactive->id);

    $row = $rows->firstWhere('id', $active->id);
    expect((float) $row['current_stock'])->toBe(12.0)
        ->and((float) $row['draft_transfer_qty'])->toBe(3.0)
        ->and((float) $row['available_qty'])->toBe(9.0);

    $inactiveOnly = $this->actingAs($this->user)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->getJson('/api/inventory/stock?status=inactive')
        ->assertOk();
    expect(collect($inactiveOnly->json('data'))->pluck('id'))->toContain($inactive->id);
});

it('creates a batch and barcode when a purchase is confirmed', function () {
    $this->createCompanyWithUser(['purchases.create', 'purchases.confirm', 'products.view']);
    $supplier = $this->createSupplier();
    $product = $this->createProduct(['current_stock' => 0]);

    $created = $this->actingAs($this->user)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'is_interstate' => false,
            'items' => [['product_id' => $product->id, 'qty' => 4, 'rate' => 25, 'discount' => 0, 'gst_rate' => 0]],
        ])->assertCreated();

    $purchaseId = $created->json('data.id');
    $this->actingAs($this->user)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->postJson("/api/purchases/{$purchaseId}/confirm")
        ->assertOk();

    $batches = $this->actingAs($this->user)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->getJson('/api/inventory/batches?status=in_stock')
        ->assertOk();

    $batch = collect($batches->json('data.batches'))->firstWhere('product_id', $product->id);
    expect($batch)->not->toBeNull()
        ->and($batch['batch_no'])->not->toBeEmpty()
        ->and($batch['barcode'])->not->toBeEmpty()
        ->and((float) $batch['remaining_qty'])->toBe(4.0);
});

it('rejects a purchase return quantity above the received batch and blocks cancelling a confirmed return', function () {
    $this->createCompanyWithUser([
        'purchases.create', 'purchases.confirm',
        'purchase_returns.create', 'purchase_returns.confirm', 'purchase_returns.delete',
    ]);
    $supplier = $this->createSupplier();
    $product = $this->createProduct(['current_stock' => 0]);
    $headers = ['X-Company-Id' => (string) $this->company->id];

    $created = $this->actingAs($this->user)->withHeaders($headers)->postJson('/api/purchases', [
        'supplier_id' => $supplier->id,
        'purchase_date' => now()->toDateString(),
        'is_interstate' => false,
        'items' => [['product_id' => $product->id, 'qty' => 5, 'rate' => 40, 'discount' => 10, 'gst_rate' => 0]],
    ])->assertCreated();
    $purchaseId = $created->json('data.id');
    $itemId = \App\Models\Purchase::with('items')->findOrFail($purchaseId)->items->first()->id;
    $this->actingAs($this->user)->withHeaders($headers)->postJson("/api/purchases/{$purchaseId}/confirm")->assertOk();

    $this->actingAs($this->user)->withHeaders($headers)->postJson('/api/purchase-returns', [
        'purchase_id' => $purchaseId,
        'return_date' => now()->toDateString(),
        'items' => [['purchase_item_id' => $itemId, 'qty' => 9]],
    ])->assertUnprocessable();

    $draft = $this->actingAs($this->user)->withHeaders($headers)->postJson('/api/purchase-returns', [
        'purchase_id' => $purchaseId,
        'return_date' => now()->toDateString(),
        'items' => [['purchase_item_id' => $itemId, 'qty' => 2]],
    ])->assertCreated();
    $returnId = $draft->json('data.id');

    $this->actingAs($this->user)->withHeaders($headers)
        ->postJson("/api/purchase-returns/{$returnId}/confirm")->assertOk();

    $this->actingAs($this->user)->withHeaders($headers)
        ->deleteJson("/api/purchase-returns/{$returnId}")
        ->assertUnprocessable();

    expect(\App\Models\PurchaseReturn::find($returnId)->status)->toBe('confirmed');
});

it('shows supplier advance balance and leaves outstanding unchanged when only the name is edited', function () {
    $this->createCompanyWithUser(['suppliers.view', 'suppliers.update', 'payments.create']);
    $supplier = $this->createSupplier(['outstanding' => 80, 'advance_balance' => 0, 'address' => 'Old address']);

    $this->actingAs($this->user)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->postJson('/api/supplier-payments', [
            'supplier_id' => $supplier->id,
            'payment_date' => now()->toDateString(),
            'amount' => 25,
            'mode' => 'cash',
            'is_advance' => true,
        ])->assertCreated();

    $show = $this->actingAs($this->user)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->getJson('/api/suppliers/'.$supplier->id)
        ->assertOk();

    // credit_days is a payment term (sets each bill's due date), so it is returned.
    expect($show->json('data'))->toHaveKey('credit_days')
        ->and((float) $show->json('data.advance_balance'))->toBe(25.0)
        ->and((float) $show->json('data.outstanding'))->toBe(80.0);

    $this->actingAs($this->user)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->putJson('/api/suppliers/'.$supplier->id, [
            'name' => 'Renamed Supplier',
            'address' => 'Old address',
            'status' => 'active',
        ])->assertOk();

    expect((float) $supplier->fresh()->outstanding)->toBe(80.0)
        ->and((float) $supplier->fresh()->advance_balance)->toBe(25.0);
});

it('requires a batch when recording damage', function () {
    $this->createCompanyWithUser(['damage.create']);
    $product = $this->createProduct();

    $this->actingAs($this->user)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->postJson('/api/damage-entries', [
            'product_id' => $product->id,
            'qty' => 1,
            'entry_date' => now()->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['product_batch_id']);
});

it('hides inactive companies from the signed-in company list', function () {
    $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
    $active = Company::create(['name' => 'Open', 'code' => 'OPN1', 'is_active' => true]);
    Company::create(['name' => 'Closed', 'code' => 'CLS1', 'is_active' => false]);

    $names = $this->actingAs($admin)->getJson('/api/me')->assertOk()->json('data.companies');
    expect(collect($names)->pluck('id'))->toContain($active->id)
        ->and(collect($names)->pluck('name'))->not->toContain('Closed');
});
