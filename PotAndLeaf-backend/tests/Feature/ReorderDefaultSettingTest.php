<?php

/** A new product with no reorder level takes "Default reorder alert qty" from Settings. */

use App\Models\Product;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

it('uses the settings default when reorder level is left blank', function () {
    $this->createCompanyWithUser(['products.view', 'products.create']);
    app(SettingsService::class)->setMany($this->company->id, ['reorder_alert_default' => '25']);
    // Through the import (same create step as the product form): one blank, one typed in.
    $this->postJson('/api/import/products', ['rows' => [
        ['name' => 'Blank Level', 'category' => 'Pots', 'cost_price' => '50', 'retail_price' => '100', 'reorder_level' => ''],
        ['name' => 'Own Level', 'category' => 'Pots', 'cost_price' => '50', 'retail_price' => '100', 'reorder_level' => '4'],
    ]], $this->apiHeaders())->assertOk();

    expect((float) Product::where('name', 'Blank Level')->value('reorder_level'))->toBe(25.0)
        ->and((float) Product::where('name', 'Own Level')->value('reorder_level'))->toBe(4.0);
});

it('gives the product form the company default for the reorder level', function () {
    $this->createCompanyWithUser(['products.view', 'products.create']);
    app(SettingsService::class)->setMany($this->company->id, ['reorder_alert_default' => '15']);
    $this->getJson('/api/products/form-data', $this->apiHeaders())->assertOk()->assertJsonPath('data.reorder_default', 15);
});
