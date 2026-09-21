<?php

use App\Models\Company;
use App\Models\LocationStock;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
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

function inTransitInterCompany(object $test): array
{
    $dest = Company::create(['name' => 'Receive Shop', 'code' => 'RS'.Str::upper(Str::random(3)), 'is_active' => true]);
    $sourceProduct = $test->createProduct(['sku' => 'RECV-01', 'current_stock' => 50, 'cost_price' => 20]);
    $destProduct = Product::create([
        'company_id'      => $dest->id,
        'sku'             => 'RECV-01',
        'name'            => $sourceProduct->name,
        'gst_rate'        => 18,
        'mrp'             => 500,
        'cost_price'      => 20,
        'retail_price'    => 400,
        'wholesale_price' => 350,
        'dealer_price'    => 300,
        'current_stock'   => 0,
        'opening_stock'   => 0,
        'status'          => 'active',
    ]);

    $transfer = StockTransfer::create([
        'company_id'    => $test->company->id,
        'to_company_id' => $dest->id,
        'transfer_type' => 'inter_company',
        'transfer_no'   => 'TRF-RECV01',
        'transfer_date' => now()->toDateString(),
        'status'        => 'draft',
    ]);
    $item = $transfer->items()->create([
        'product_id'   => $sourceProduct->id,
        'product_name' => $sourceProduct->name,
        'qty'          => 10,
        'received_qty' => 0,
    ]);

    app(TransferService::class)->dispatch($transfer->fresh(), $test->user->id);

    return compact('dest', 'destProduct', 'transfer', 'item');
}

it('rejects receive when posted as the source company', function () {
    ['transfer' => $transfer, 'item' => $item] = inTransitInterCompany($this);

    $this->postJson("/api/transfers/{$transfer->id}/receive", [
        'receipts' => [['id' => $item->id, 'received_qty' => 10]],
    ], $this->apiHeaders())->assertNotFound();
});

it('lets a super-admin receive even when the company header is the source shop', function () {
    ['destProduct' => $destProduct, 'transfer' => $transfer, 'item' => $item] = inTransitInterCompany($this);
    $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);

    $this->postJson("/api/transfers/{$transfer->id}/receive", [
        'receipts' => [['id' => $item->id, 'received_qty' => 10]],
    ], $this->apiHeaders($admin))->assertOk();

    expect($transfer->fresh()->status)->toBe('received');
    expect((float) $destProduct->fresh()->current_stock)->toBe(10.0);
});

it('creates a destination product on receive when the shop does not have it yet', function () {
    $dest = Company::create(['name' => 'New Shop', 'code' => 'NS'.Str::upper(Str::random(3)), 'is_active' => true]);
    $sourceProduct = $this->createProduct([
        'sku' => 'ARECA-01',
        'name' => 'Areca Palm',
        'description' => 'Indoor palm',
        'current_stock' => 40,
        'cost_price' => 25,
        'mrp' => 600,
        'retail_price' => 500,
        'wholesale_price' => 450,
        'dealer_price' => 400,
        'gst_rate' => 18,
        'hsn_code' => '0602',
        'barcode' => 'BAR-ARECA-01',
    ]);

    $transfer = StockTransfer::create([
        'company_id'    => $this->company->id,
        'to_company_id' => $dest->id,
        'transfer_type' => 'inter_company',
        'transfer_no'   => 'TRF-AUTO01',
        'transfer_date' => now()->toDateString(),
        'status'        => 'draft',
    ]);
    $item = $transfer->items()->create([
        'product_id'   => $sourceProduct->id,
        'product_name' => $sourceProduct->name,
        'qty'          => 10,
        'received_qty' => 0,
    ]);

    app(TransferService::class)->dispatch($transfer->fresh(), $this->user->id);
    expect((float) $sourceProduct->fresh()->current_stock)->toBe(30.0);

    app(TransferService::class)->receive($transfer->fresh(), [$item->id => 10], $this->user->id);

    $destProduct = Product::forCompany($dest->id)->where('sku', 'ARECA-01')->first();
    expect($destProduct)->not->toBeNull();
    expect($destProduct->name)->toBe('Areca Palm');
    expect($destProduct->description)->toBe('Indoor palm');
    expect($destProduct->barcode)->toBe('BAR-ARECA-01');
    expect($destProduct->hsn_code)->toBe('0602');
    expect((float) $destProduct->cost_price)->toBe(25.0);
    expect((float) $destProduct->mrp)->toBe(600.0);
    expect((float) $destProduct->retail_price)->toBe(500.0);
    expect((float) $destProduct->current_stock)->toBe(10.0);
    expect((float) $sourceProduct->fresh()->current_stock)->toBe(30.0);
    expect($transfer->fresh()->status)->toBe('received');
});

it('adds transferred quantity onto existing destination stock', function () {
    $dest = Company::create(['name' => 'Stocked Shop', 'code' => 'SS'.Str::upper(Str::random(3)), 'is_active' => true]);
    $sourceProduct = $this->createProduct(['sku' => 'FICUS-01', 'name' => 'Ficus', 'current_stock' => 20, 'cost_price' => 15]);
    $destProduct = Product::create([
        'company_id'      => $dest->id,
        'sku'             => 'FICUS-01',
        'name'            => 'Ficus',
        'gst_rate'        => 18,
        'mrp'             => 400,
        'cost_price'      => 15,
        'retail_price'    => 300,
        'wholesale_price' => 250,
        'dealer_price'    => 220,
        'current_stock'   => 7,
        'opening_stock'   => 7,
        'status'          => 'active',
    ]);

    $transfer = StockTransfer::create([
        'company_id'    => $this->company->id,
        'to_company_id' => $dest->id,
        'transfer_type' => 'inter_company',
        'transfer_no'   => 'TRF-ADD01',
        'transfer_date' => now()->toDateString(),
        'status'        => 'draft',
    ]);
    $item = $transfer->items()->create([
        'product_id'   => $sourceProduct->id,
        'product_name' => $sourceProduct->name,
        'qty'          => 5,
        'received_qty' => 0,
    ]);

    app(TransferService::class)->dispatch($transfer->fresh(), $this->user->id);
    app(TransferService::class)->receive($transfer->fresh(), [$item->id => 5], $this->user->id);

    expect((float) $destProduct->fresh()->current_stock)->toBe(12.0);
    expect((float) $sourceProduct->fresh()->current_stock)->toBe(15.0);
});

it('lists every other active company as a transfer destination for a shop user', function () {
    $other = Company::create(['name' => 'Cheerakuzhy Outlet', 'code' => 'OUT'.Str::upper(Str::random(3)), 'is_active' => true]);
    Company::create(['name' => 'Inactive Shop', 'code' => 'INA'.Str::upper(Str::random(3)), 'is_active' => false]);

    $this->getJson('/api/transfers/form-data', $this->apiHeaders())
        ->assertOk()
        ->assertJsonCount(1, 'data.companies')
        ->assertJsonPath('data.companies.0.id', $other->id)
        ->assertJsonPath('data.from_company.id', $this->company->id);

    $sourceIds = collect($this->getJson('/api/transfers/form-data', $this->apiHeaders())->json('data.source_companies'))->pluck('id')->all();
    expect($sourceIds)->toContain($this->company->id)->toContain($other->id);
});

it('loads stock from a selected source company the user does not belong to', function () {
    $other = Company::create(['name' => 'Agro Supplies', 'code' => 'AG'.Str::upper(Str::random(3)), 'is_active' => true]);
    $product = $this->createProduct(['company_id' => $other->id, 'name' => 'Neem cake', 'current_stock' => 310]);

    $res = $this->getJson('/api/transfers/form-data?source_company_id='.$other->id, $this->apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.from_company.id', $other->id);

    expect(collect($res->json('data.products'))->firstWhere('id', $product->id)['current_stock'])->toBe(310);
    expect(collect($res->json('data.companies'))->pluck('id'))->toContain($this->company->id)->not->toContain($other->id);
});

it('lets a shop user request stock from another company', function () {
    $other = Company::create(['name' => 'Source Branch', 'code' => 'SRC'.Str::upper(Str::random(3)), 'is_active' => true]);
    $product = $this->createProduct(['company_id' => $other->id, 'current_stock' => 50]);

    $this->postJson('/api/transfers', [
        'from_company_id' => $other->id,
        'to_company_id'   => $this->company->id,
        'transfer_date'   => now()->toDateString(),
        'items'           => [['product_id' => $product->id, 'qty' => 10]],
    ], $this->apiHeaders())
        ->assertCreated()
        ->assertJsonPath('data.from_company_id', $other->id)
        ->assertJsonPath('data.to_company_id', $this->company->id)
        ->assertJsonPath('data.status', 'requested');
});
