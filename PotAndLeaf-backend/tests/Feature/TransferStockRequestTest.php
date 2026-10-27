<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Role;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

const XFER_PERMS = ['transfers.view', 'transfers.create', 'transfers.approve', 'transfers.dispatch', 'transfers.receive', 'transfers.delete', 'inventory.view'];

beforeEach(function () {
    // Company A (this->company / this->user) asks for stock.
    $this->createCompanyWithUser(XFER_PERMS);
    $this->companyA = $this->company;
    $this->userA = $this->user;

    // Company B has the stock, with its own user.
    $this->companyB = Company::create(['name' => 'Branch B', 'code' => 'BRB'.Str::upper(Str::random(3)), 'is_active' => true]);
    $this->userB = User::factory()->create(['is_super_admin' => false, 'is_active' => true]);
    $this->userB->companies()->attach($this->companyB->id, ['is_default' => true]);
    $role = Role::where('name', 'Tester')->first();
    $this->userB->roles()->attach($role->id, ['company_id' => $this->companyB->id]);

    $this->admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);

    // Same auto SKU in both companies, for DIFFERENT products.
    $this->rosePotA = $this->createProduct(['company_id' => $this->companyA->id, 'sku' => 'SKU-00001', 'name' => 'Rose Pot', 'current_stock' => 5]);
    $this->arecaB = $this->createProduct(['company_id' => $this->companyB->id, 'sku' => 'SKU-00001', 'name' => 'Areca Palm', 'current_stock' => 30]);
    ProductBatch::create([
        'company_id' => $this->companyB->id, 'product_id' => $this->arecaB->id, 'batch_no' => 'B-1', 'barcode' => 'BAR-B-1',
        'qty' => 30, 'remaining_qty' => 30, 'cost_price' => 200, 'status' => 'active', 'received_at' => now(),
    ]);
});

function headersFor(object $t, User $u, $companyId): array
{
    app('auth')->forgetGuards(); // each request signs in as the given user

    return array_merge($t->apiHeaders($u), ['X-Company-Id' => (string) $companyId]);
}

it('shows other companies stock, not my own', function () {
    $rows = $this->getJson('/api/transfers/request-data', headersFor($this, $this->userA, $this->companyA->id))
        ->assertOk()->json('data.stock');

    expect(collect($rows)->pluck('company_id')->unique()->values()->all())->toBe([$this->companyB->id]);
    expect($rows[0]['name'])->toBe('Areca Palm');
    expect($rows[0]['available'])->toEqual(30);
});

it('runs request, admin approval, dispatch by sender, receive by requester with a new barcode on the right product', function () {
    $req = $this->postJson('/api/transfers/request', [
        'transfer_date' => now()->toDateString(),
        'lines' => [['from_company_id' => $this->companyB->id, 'product_id' => $this->arecaB->id, 'qty' => 10]],
    ], headersFor($this, $this->userA, $this->companyA->id))->assertCreated();

    $id = $req->json('data.0.id');
    expect($req->json('data.0.status'))->toBe('requested');
    expect($req->json('data.0.from_company_id'))->toBe($this->companyB->id);
    expect($req->json('data.0.to_company_id'))->toBe($this->companyA->id);

    // The requesting company can open it with its OWN company header.
    $this->getJson("/api/transfers/{$id}", headersFor($this, $this->userA, $this->companyA->id))->assertOk()
        ->assertJsonPath('data.can.approve', false)
        ->assertJsonPath('data.can.cancel', true);
    // ...but cannot approve its own request.
    $this->postJson("/api/transfers/{$id}/approve", [], headersFor($this, $this->userA, $this->companyA->id))->assertForbidden();

    // Asking for more than is free elsewhere is refused.
    $this->postJson('/api/transfers/request', [
        'transfer_date' => now()->toDateString(),
        'lines' => [['from_company_id' => $this->companyB->id, 'product_id' => $this->arecaB->id, 'qty' => 25]],
    ], headersFor($this, $this->userA, $this->companyA->id))->assertStatus(422);

    // Admin approves.
    $this->postJson("/api/transfers/{$id}/approve", [], headersFor($this, $this->admin, $this->companyA->id))->assertOk()
        ->assertJsonPath('data.status', 'draft');

    // Sending company dispatches.
    $this->getJson("/api/transfers/{$id}", headersFor($this, $this->userB, $this->companyB->id))->assertOk()
        ->assertJsonPath('data.can.dispatch', true);
    $this->postJson("/api/transfers/{$id}/dispatch", [], headersFor($this, $this->userB, $this->companyB->id))->assertOk()
        ->assertJsonPath('data.status', 'in_transit');
    expect((float) $this->arecaB->fresh()->current_stock)->toBe(20.0);

    // Requesting company sees Receive and receives.
    $show = $this->getJson("/api/transfers/{$id}", headersFor($this, $this->userA, $this->companyA->id))->assertOk();
    expect($show->json('data.can.receive'))->toBeTrue();
    $itemId = $show->json('data.items.0.id');
    $this->postJson("/api/transfers/{$id}/receive", ['receipts' => [['id' => $itemId, 'received_qty' => 10]]], headersFor($this, $this->userA, $this->companyA->id))
        ->assertOk()->assertJsonPath('data.status', 'received');

    // Stock went to a product called Areca Palm, NOT to Rose Pot (which shares SKU-00001).
    expect((float) $this->rosePotA->fresh()->current_stock)->toBe(5.0);
    $arecaA = Product::forCompany($this->companyA->id)->where('name', 'Areca Palm')->first();
    expect($arecaA)->not->toBeNull();
    expect((float) $arecaA->current_stock)->toBe(10.0);
    expect($arecaA->sku)->not->toBe('SKU-00001');

    // A barcode batch was made and shows on the transfer.
    $show = $this->getJson("/api/transfers/{$id}", headersFor($this, $this->userA, $this->companyA->id))->assertOk();
    $barcode = $show->json('data.items.0.received_barcode');
    expect($barcode)->not->toBeEmpty();
    expect(ProductBatch::where('barcode', $barcode)->where('product_id', $arecaA->id)->value('remaining_qty'))->toEqual(10);
});

it('adds received stock to the existing same-named product', function () {
    $arecaA = $this->createProduct(['company_id' => $this->companyA->id, 'sku' => 'MY-ARECA', 'name' => 'areca palm ', 'current_stock' => 2]);
    $made = app(\App\Services\TransferService::class)->requestStock($this->companyA->id, [
        'transfer_date' => now()->toDateString(),
        'lines' => [['from_company_id' => $this->companyB->id, 'product_id' => $this->arecaB->id, 'qty' => 4]],
    ], $this->userA->id, true);
    $t = $made[0];
    $svc = app(\App\Services\TransferService::class);
    $svc->dispatch($t->fresh());
    $svc->receive($t->fresh(), [$t->items[0]->id => 4]);

    expect((float) $arecaA->fresh()->current_stock)->toBe(6.0);
    expect(Product::forCompany($this->companyA->id)->count())->toBe(2);
});

it('lets the admin send a request from a different company that has the product', function () {
    $companyC = Company::create(['name' => 'Branch C', 'code' => 'BRC'.Str::upper(Str::random(3)), 'is_active' => true]);
    $arecaC = $this->createProduct(['company_id' => $companyC->id, 'sku' => 'C-1', 'name' => 'Areca Palm', 'current_stock' => 50]);

    $id = $this->postJson('/api/transfers/request', [
        'transfer_date' => now()->toDateString(),
        'lines' => [['from_company_id' => $this->companyB->id, 'product_id' => $this->arecaB->id, 'qty' => 10]],
    ], headersFor($this, $this->userA, $this->companyA->id))->assertCreated()->json('data.0.id');

    $sources = $this->getJson("/api/transfers/{$id}/sources", headersFor($this, $this->admin, $this->companyA->id))->assertOk()->json('data');
    expect(collect($sources)->pluck('company_id')->all())->toContain($companyC->id, $this->companyB->id);

    $this->postJson("/api/transfers/{$id}/approve", ['from_company_id' => $companyC->id], headersFor($this, $this->admin, $this->companyA->id))
        ->assertOk()->assertJsonPath('data.from_company_id', $companyC->id);
    $t = StockTransfer::with('items')->find($id);
    expect($t->items[0]->product_id)->toBe($arecaC->id);
});

it('lets the requesting company withdraw its request before approval', function () {
    $id = $this->postJson('/api/transfers/request', [
        'transfer_date' => now()->toDateString(),
        'lines' => [['from_company_id' => $this->companyB->id, 'product_id' => $this->arecaB->id, 'qty' => 1]],
    ], headersFor($this, $this->userA, $this->companyA->id))->assertCreated()->json('data.0.id');

    $this->deleteJson("/api/transfers/{$id}", [], headersFor($this, $this->userA, $this->companyA->id))->assertOk();
    expect(StockTransfer::find($id)->status)->toBe('cancelled');
});
