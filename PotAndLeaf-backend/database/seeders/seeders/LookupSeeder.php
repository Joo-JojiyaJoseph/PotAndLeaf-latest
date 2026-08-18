<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use Illuminate\Database\Seeder;

class LookupSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [['code' => 'PLANT', 'name' => 'Plants'], ['code' => 'POT', 'name' => 'Pots & Planters'], ['code' => 'SEED', 'name' => 'Seeds'], ['code' => 'FERT', 'name' => 'Fertilizers & Soil']];
        $brands = [['code' => 'GEN', 'name' => 'Generic'], ['code' => 'CHK', 'name' => 'Cheerakuzhy']];
        $units = [['code' => 'NOS', 'name' => 'Numbers', 'short_name' => 'Nos'], ['code' => 'KG', 'name' => 'Kilogram', 'short_name' => 'Kg'], ['code' => 'BAG', 'name' => 'Bag', 'short_name' => 'Bag']];

        Company::all()->each(function (Company $company) use ($categories, $brands, $units) {
            foreach ($categories as $c) {
                ProductCategory::firstOrCreate(['company_id' => $company->id, 'code' => $c['code']], $c + ['status' => 'active']);
            }
            foreach ($brands as $b) {
                ProductBrand::firstOrCreate(['company_id' => $company->id, 'code' => $b['code']], $b + ['status' => 'active']);
            }
            foreach ($units as $u) {
                ProductUnit::firstOrCreate(['company_id' => $company->id, 'code' => $u['code']], $u + ['status' => 'active']);
            }
        });
    }
}
