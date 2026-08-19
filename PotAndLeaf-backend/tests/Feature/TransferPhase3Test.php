<?php

use App\Models\Company;
use App\Models\LocationStock;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Services\LocationStockService;
use App\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'transfers.view',
        'transfers.create',
        'transfers.dispatch',
        'transfers.receive',
        'transfers.delete',
        'transfers.approve',
    ]);
});

it('creates and completes an intra-company location transfer', function () {
    $godown = $this->createLocation(['name' => 'Main Godown', 'type' => 'godown']);
    $shop = $this->createLocation(['name' => 'Front Shop', 'type' => 'shop']);
    $product = $this->createProduct(['current_stock' => 100]);

    app(LocationStockService::class)->adjust($this->company->id, $godown->id, $product->id, 'in', 50);

    $response = $this->postJson('/api/transfers', [
        'transfer_type'    => 'intra_company',
        'from_location_id' => $godown->id,
        'to_location_id'   => $shop->id,
        'transfer_date'    => now()->toDateString(),
        'items'            => [['product_id' => $product->id, 'qty' => 10]],
    ], $this->apiHeaders())->assertCreated();

    $transfer = StockTransfer::find($response->json('data.id'));
    expect($transfer->isIntraCompany())->toBeTrue();

    app(TransferService::class)->dispatch($transfer, $this->user->id);
    expect((float) LocationStock::where('location_id', $godown->id)->where('product_id', $product->id)->value('qty'))->toBe(40.0);

    app(TransferService::class)->receive($transfer->fresh(), [$transfer->items[0]->id => 10], $this->user->id);
    expect($transfer->fresh()->status)->toBe('received');
    expect((float) LocationStock::where('location_id', $shop->id)->where('product_id', $product->id)->value('qty'))->toBe(10.0);
    expect((float) $product->fresh()->current_stock)->toBe(100.0);
});

it('approves transfer lines partially', function () {
    $dest = Company::create(['name' => 'Branch B', 'code' => 'BR'.Str::upper(Str::random(3)), 'is_active' => true]);
    $product = $this->createProduct(['current_stock' => 100, 'cost_price' => 10]);

    $transfer = StockTransfer::create([
        'company_id'    => $this->company->id,
        'to_company_id' => $dest->id,
        'transfer_type' => 'inter_company',
        'transfer_no'   => 'TRF-000010',
        'transfer_date' => now()->toDateString(),
        'status'        => 'requested',
    ]);
    $item = $transfer->items()->create([
        'product_id'   => $product->id,
        'product_name' => $product->name,
        'qty'          => 100,
        'received_qty' => 0,
    ]);

    $this->postJson("/api/transfers/{$transfer->id}/approve", [
        'approvals' => [
            ['id' => $item->id, 'approved_qty' => 60, 'rejection_reason' => 'Partial stock available'],
        ],
    ], $this->apiHeaders())->assertOk();

    expect((float) $item->fresh()->approved_qty)->toBe(60.0);
    expect((float) $item->fresh()->rejected_qty)->toBe(40.0);
    expect($transfer->fresh()->status)->toBe('draft');

    app(TransferService::class)->dispatch($transfer->fresh(), $this->user->id);
    expect((float) $product->fresh()->current_stock)->toBe(40.0);
});

it('records redirect audit fields', function () {
    $destA = Company::create(['name' => 'Shop A', 'code' => 'SA'.Str::upper(Str::random(3)), 'is_active' => true]);
    $destB = Company::create(['name' => 'Shop B', 'code' => 'SB'.Str::upper(Str::random(3)), 'is_active' => true]);
    $product = $this->createProduct(['current_stock' => 50]);

    $transfer = StockTransfer::create([
        'company_id'    => $this->company->id,
        'to_company_id' => $destA->id,
        'transfer_type' => 'inter_company',
        'transfer_no'   => 'TRF-000011',
        'transfer_date' => now()->toDateString(),
        'status'        => 'draft',
    ]);
    $transfer->items()->create([
        'product_id'   => $product->id,
        'product_name' => $product->name,
        'qty'          => 5,
        'received_qty' => 0,
    ]);

    app(TransferService::class)->dispatch($transfer->fresh(), $this->user->id);

    $this->postJson("/api/transfers/{$transfer->id}/redirect", [
        'to_company_id' => $destB->id,
    ], $this->apiHeaders())->assertOk();

    $transfer->refresh();
    expect((string) $transfer->to_company_id)->toBe((string) $destB->id);
    expect((string) $transfer->redirected_from_company_id)->toBe((string) $destA->id);
    expect($transfer->redirected_at)->not->toBeNull();
    expect((string) $transfer->redirected_by)->toBe((string) $this->user->id);
});

it('partially receives inter-company transfer and returns shortfall', function () {
    $dest = Company::create(['name' => 'Branch C', 'code' => 'BC'.Str::upper(Str::random(3)), 'is_active' => true]);
    $sourceProduct = $this->createProduct(['sku' => 'PALM-01', 'current_stock' => 100, 'cost_price' => 20]);
    $destProduct = Product::create([
        'company_id'    => $dest->id,
        'sku'           => 'PALM-01',
        'name'          => $sourceProduct->name,
        'gst_rate'      => 18,
        'mrp'           => 500,
        'cost_price'    => 20,
        'retail_price'  => 400,
        'wholesale_price' => 350,
        'dealer_price'  => 300,
        'current_stock' => 0,
        'opening_stock' => 0,
        'status'        => 'active',
    ]);

    $transfer = StockTransfer::create([
        'company_id'    => $this->company->id,
        'to_company_id' => $dest->id,
        'transfer_type' => 'inter_company',
        'transfer_no'   => 'TRF-000012',
        'transfer_date' => now()->toDateString(),
        'status'        => 'draft',
    ]);
    $item = $transfer->items()->create([
        'product_id'   => $sourceProduct->id,
        'product_name' => $sourceProduct->name,
        'qty'          => 20,
        'received_qty' => 0,
    ]);

    app(TransferService::class)->dispatch($transfer->fresh(), $this->user->id);
    expect((float) $sourceProduct->fresh()->current_stock)->toBe(80.0);

    app(TransferService::class)->receive($transfer->fresh(), [$item->id => 15], $this->user->id);

    expect((float) $sourceProduct->fresh()->current_stock)->toBe(85.0);
    expect((float) $destProduct->fresh()->current_stock)->toBe(15.0);
    expect((float) $item->fresh()->received_qty)->toBe(15.0);
});
