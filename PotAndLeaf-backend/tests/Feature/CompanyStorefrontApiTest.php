<?php

use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\CompanySetting;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

beforeEach(function () {
    $this->createCompanyWithUser([
        'api.view', 'api.manage', 'sales.view', 'sales.create', 'sales.confirm', 'customers.create',
    ]);
    $this->createLocation();
    CompanySetting::query()->updateOrCreate(
        ['company_id' => $this->company->id, 'key' => 'website_integration'],
        ['value' => '1'],
    );
});

function storefrontHeaders(string $apiKey): array
{
    return [
        'X-Api-Key' => $apiKey,
        'Accept' => 'application/json',
    ];
}

it('exposes public openapi docs without a key', function () {
    $this->getJson('/api/v1/openapi.json')
        ->assertOk()
        ->assertJsonPath('openapi', '3.0.3');
});

it('creates a hashed api key and never lists the secret again', function () {
    $res = $this->postJson('/api/company-api-keys', ['name' => 'Website'], $this->apiHeaders())
        ->assertCreated();

    $plaintext = $res->json('data.api_key');
    expect($plaintext)->toStartWith('plk_');
    expect(CompanyApiKey::count())->toBe(1);
    expect(CompanyApiKey::first()->key_hash)->not->toBe($plaintext);

    $list = $this->getJson('/api/company-api-keys', $this->apiHeaders())->assertOk();
    expect($list->json('data.0'))->not->toHaveKey('api_key');
    expect($list->json('data.0.status'))->toBe('active');
});

it('blocks storefront access when website integration is off', function () {
    CompanySetting::query()->updateOrCreate(
        ['company_id' => $this->company->id, 'key' => 'website_integration'],
        ['value' => '0'],
    );
    $plaintext = $this->postJson('/api/company-api-keys', ['name' => 'Site'], $this->apiHeaders())
        ->json('data.api_key');

    $this->getJson('/api/v1/products', storefrontHeaders($plaintext))->assertForbidden();
});

it('rejects missing, invalid and revoked storefront keys', function () {
    $this->getJson('/api/v1/products')->assertUnauthorized();
    $this->getJson('/api/v1/products', storefrontHeaders('plk_'.str_repeat('ab', 24)))->assertUnauthorized();

    $plaintext = $this->postJson('/api/company-api-keys', ['name' => 'Site'], $this->apiHeaders())
        ->json('data.api_key');
    $id = CompanyApiKey::first()->id;

    $this->postJson("/api/company-api-keys/{$id}/revoke", [], $this->apiHeaders())->assertOk();
    $this->getJson('/api/v1/products', storefrontHeaders($plaintext))->assertUnauthorized();
});

it('scopes catalogue and orders to the authenticated company', function () {
    $product = $this->createProduct(['name' => 'Areca Palm', 'current_stock' => 20, 'gst_rate' => 0, 'retail_price' => 400]);

    $other = Company::create([
        'name' => 'Other Co',
        'code' => 'OTH'.Str::upper(Str::random(4)),
        'is_active' => true,
    ]);
    $this->createProduct([
        'company_id' => $other->id,
        'name' => 'Secret Fern',
        'sku' => 'SKU-OTHER',
        'current_stock' => 5,
    ]);

    $plaintext = $this->postJson('/api/company-api-keys', ['name' => 'Website'], $this->apiHeaders())
        ->json('data.api_key');

    $this->getJson('/api/v1/products?company_id='.$other->id, storefrontHeaders($plaintext))
        ->assertOk()
        ->assertJsonFragment(['name' => 'Areca Palm'])
        ->assertJsonMissing(['name' => 'Secret Fern']);

    $order = $this->postJson('/api/v1/orders', [
        'company_id' => $other->id,
        'customer' => ['name' => 'Walk-in Web', 'phone' => '9990001111'],
        'items' => [['product_id' => $product->id, 'qty' => 1, 'rate' => 400]],
        'confirm' => true,
    ], storefrontHeaders($plaintext))->assertCreated();

    $saleId = $order->json('data.id');
    expect(Sale::find($saleId)->company_id)->toBe($this->company->id);
    expect((float) $product->fresh()->current_stock)->toBe(19.0);

    $this->getJson('/api/v1/orders/'.$saleId, storefrontHeaders($plaintext))
        ->assertOk()
        ->assertJsonPath('data.sale_no', $order->json('data.sale_no'));
});
