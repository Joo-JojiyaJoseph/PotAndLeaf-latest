<?php

use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

it('super admin can update a draft purchase when company_id is an integer', function () {
    $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
    $this->createCompanyWithUser(['purchases.create', 'purchases.update']);

    $supplier = $this->createSupplier();
    $product = $this->createProduct(['cost_price' => 100, 'gst_rate' => 0]);

    $create = $this->actingAs($admin)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->postJson('/api/purchases', [
            'supplier_id'       => $supplier->id,
            'purchase_date'     => now()->toDateString(),
            'is_interstate'     => false,
            'landed_cost_total' => 0,
            'items'             => [
                ['product_id' => $product->id, 'qty' => 10, 'rate' => 100, 'discount' => 0, 'gst_rate' => 0],
            ],
        ])
        ->assertCreated();

    $purchaseId = $create->json('data.id');

    $this->actingAs($admin)
        ->withHeader('X-Company-Id', (string) $this->company->id)
        ->putJson("/api/purchases/{$purchaseId}", [
            'supplier_id'       => $supplier->id,
            'purchase_date'     => now()->toDateString(),
            'invoice_no'        => 'INV-001',
            'is_interstate'     => false,
            'landed_cost_total' => 100,
            'company_id'        => $this->company->id,
            'items'             => [
                ['product_id' => $product->id, 'qty' => 1000, 'rate' => 100, 'discount' => 0, 'gst_rate' => 0],
            ],
        ])
        ->assertOk();

    expect(Purchase::find($purchaseId)->invoice_no)->toBe('INV-001');
});
