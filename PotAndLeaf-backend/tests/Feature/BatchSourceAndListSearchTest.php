<?php

use App\Models\Company;
use App\Models\ProductBatch;
use App\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

it('labels a batch made by receiving a transfer as "Transfer", not "Opening"', function () {
    $this->createCompanyWithUser(['inventory.view', 'products.view']);
    $other = Company::create(['name' => 'Other', 'code' => 'OT'.Str::upper(Str::random(3)), 'is_active' => true]);
    $src = $this->createProduct(['company_id' => $other->id, 'name' => 'Money Plant', 'sku' => 'MP-1', 'current_stock' => 20]);
    $svc = app(TransferService::class);
    $t = $svc->requestStock($this->company->id, ['transfer_date' => now()->toDateString(), 'lines' => [['from_company_id' => $other->id, 'product_id' => $src->id, 'qty' => 5]]], null, true)[0];
    $svc->dispatch($t->fresh());
    $svc->receive($t->fresh(), [$t->items[0]->id => 5]);

    $rows = collect($this->getJson('/api/inventory/batches?status=all', $this->apiHeaders())->assertOk()->json('data.batches'));
    $row = $rows->firstWhere('product', 'Money Plant');
    expect($row['source'])->toBe('Transfer '.$t->transfer_no);
});

it('searches production orders on the server', function () {
    $this->createCompanyWithUser(['production.view']);
    $this->getJson('/api/production/orders?search=zzz-none', $this->apiHeaders())->assertOk()->assertJsonPath('meta.total', 0);
});
