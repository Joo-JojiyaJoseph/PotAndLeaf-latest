<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            ['sku' => 'PLT-ROSE',  'name' => 'Rose Plant (Hybrid Tea)', 'cat' => 'PLANT', 'unit' => 'NOS', 'gst' => 5,  'cost' => 45,  'mrp' => 120, 'reorder' => 20, 'dim' => [15, 15, 40]],
            ['sku' => 'PLT-MANGO', 'name' => 'Mango Sapling (Alphonso)', 'cat' => 'PLANT', 'unit' => 'NOS', 'gst' => 5,  'cost' => 90,  'mrp' => 220, 'reorder' => 15, 'dim' => [20, 20, 60]],
            ['sku' => 'POT-CLAY8', 'name' => 'Clay Pot 8 inch',          'cat' => 'POT',   'unit' => 'NOS', 'gst' => 12, 'cost' => 35,  'mrp' => 80,  'reorder' => 50, 'dim' => [20, 20, 18]],
            ['sku' => 'POT-FIBER', 'name' => 'Fiber Planter (Large)',    'cat' => 'POT',   'unit' => 'NOS', 'gst' => 18, 'cost' => 260, 'mrp' => 560, 'reorder' => 10, 'dim' => [45, 45, 40]],
            ['sku' => 'FRT-VERMI', 'name' => 'Vermicompost 5kg',         'cat' => 'FERT',  'unit' => 'BAG', 'gst' => 5,  'cost' => 120, 'mrp' => 200, 'reorder' => 25, 'dim' => [40, 25, 12]],
        ];

        Company::all()->each(function (Company $company) use ($catalog) {
            $cats = ProductCategory::where('company_id', $company->id)->pluck('id', 'code');
            $units = ProductUnit::where('company_id', $company->id)->pluck('id', 'code');
            $brand = ProductBrand::where('company_id', $company->id)->where('code', 'GEN')->value('id');

            foreach ($catalog as $p) {
                Product::firstOrCreate(
                    ['company_id' => $company->id, 'sku' => $p['sku']],
                    [
                        'name'          => $p['name'],
                        'category_id'   => $cats[$p['cat']] ?? null,
                        'brand_id'      => $brand,
                        'unit_id'       => $units[$p['unit']] ?? null,
                        'gst_rate'      => $p['gst'],
                        'cost_price'    => $p['cost'],
                        'mrp'           => $p['mrp'],
                        'retail_price'  => $p['mrp'],
                        'reorder_level' => $p['reorder'],
                        'length_cm'     => $p['dim'][0] ?? null,
                        'width_cm'      => $p['dim'][1] ?? null,
                        'height_cm'     => $p['dim'][2] ?? null,
                        'opening_stock' => 0,
                        'current_stock' => 0, // starts empty so purchases drive stock (and low-stock alerts show)
                        'status'        => 'active',
                    ],
                );
            }
        });
    }
}
